<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Client;
use App\Models\ImportLog;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ImportService
{
    public function __construct(
        private readonly ClientRepositoryInterface   $clientRepo,
        private readonly WorkflowRepositoryInterface $workflowRepo,
        private readonly ActivityLogService          $activityLog,
    ) {}

    // ── Preview ───────────────────────────────────────────────────────────────

    public function preview(UploadedFile $file): array
    {
        $spreadsheet = $this->loadSpreadsheet($file);
        $sheet       = $spreadsheet->getActiveSheet();

        // false = do NOT evaluate formulas (avoids COUNTIF/array errors)
        $rows = $sheet->toArray(null, false, true, false);

        // Strip completely empty rows
        $rows    = array_values(array_filter($rows, fn ($r) => array_filter($r, fn ($v) => $v !== null && trim((string)$v) !== '')));
        $headers = array_shift($rows) ?? [];
        $headers = array_map(fn ($h) => (string)($h ?? ''), $headers);

        return [
            'headers'    => $headers,
            'rows'       => array_slice($rows, 0, 5),
            'total_rows' => count($rows),
        ];
    }

    // ── Main Import (Smart Upsert by DFID) ────────────────────────────────────

    public function import(UploadedFile $file, array $mapping, ImportLog $log): ImportLog
    {
        $startedAt = now();
        $log->update(['status' => 'processing', 'started_at' => $startedAt]);

        $spreadsheet = $this->loadSpreadsheet($file);
        $rows        = $spreadsheet->getActiveSheet()->toArray(null, false, true, false);

        $rows = array_values(array_filter($rows, fn ($r) => array_filter($r, fn ($v) => $v !== null && trim((string)$v) !== '')));
        array_shift($rows); // remove header row

        $newCount        = 0;
        $updatedCount    = 0;
        $skippedCount    = 0;
        $failedCount     = 0;
        $duplicateCount  = 0; // same DFID appears more than once in this file
        $errors          = [];
        $validationErrors = [];
        $seenDfids       = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;
                try {
                    $data = $this->mapRow($row, $mapping);

                    $dfid = $data['dfid_number'] ?? null;

                    // ── Intra-file duplicate detection ─────────────────────
                    if ($dfid && isset($seenDfids[$dfid])) {
                        $duplicateCount++;
                        $errors[] = "Row {$rowNum}: DFID {$dfid} appears more than once in this file — skipped.";
                        continue;
                    }
                    if ($dfid) {
                        $seenDfids[$dfid] = true;
                    }

                    // ── Upsert by DFID ─────────────────────────────────────
                    $existing = $dfid ? $this->clientRepo->findByDfid($dfid) : null;

                    if ($existing) {
                        // UPDATE — only write fields that actually changed
                        $changed = $this->diffData($existing, $data);

                        if (empty($changed)) {
                            $skippedCount++;
                            continue; // nothing changed, no need to write
                        }

                        $changed['updated_by'] = Auth::id();
                        $old = $existing->only(array_keys($changed));
                        $existing->update($changed);

                        $this->activityLog->log(
                            'Import',
                            'Client Updated',
                            $existing->id,
                            $old,
                            $changed
                        );
                        $updatedCount++;
                    } else {
                        // CREATE — new client
                        $client = $this->clientRepo->create(array_merge($data, [
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]));
                        $this->workflowRepo->initClientStages($client->id);
                        $this->activityLog->log('Import', 'Client Imported', $client->id, null, $data);
                        $newCount++;
                    }
                } catch (\InvalidArgumentException $e) {
                    // Validation error — row skipped intentionally
                    $failedCount++;
                    $validationErrors[] = "Row {$rowNum}: " . $e->getMessage();
                } catch (\Exception $e) {
                    $failedCount++;
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }

            DB::commit();

            $log->update([
                'status'                   => 'completed',
                'total_rows'               => count($rows),
                'success_rows'             => $newCount + $updatedCount,
                'updated_rows'             => $updatedCount,
                'skipped_rows'             => $skippedCount,
                'failed_rows'              => $failedCount,
                'duplicate_rows'           => $duplicateCount,
                'errors'                   => $errors,
                'validation_errors'        => $validationErrors,
                'import_duration_seconds'  => now()->diffInSeconds($startedAt),
                'completed_at'             => now(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $log->update(['status' => 'failed', 'errors' => [$e->getMessage()]]);
        }

        return $log->fresh();
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    public function rollback(ImportLog $log): void
    {
        Client::where('created_by', $log->user_id)
            ->whereBetween('created_at', [$log->started_at, $log->completed_at])
            ->delete();

        $log->update(['status' => 'rolled_back']);
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    public static function importableFields(): array
    {
        return [
            'dfid_number'   => 'DFID Number',
            'client_name'   => 'Client Name',
            'brand_name'    => 'Brand / Page Name',
            'website'       => 'Website Link',
            'category_name' => 'Category',
            'joining_date'  => 'Joining Date',
            'client_status' => 'Client Status',
            'doc_status'    => 'DOC Status',
            'remarks'       => 'Notes / Remarks',
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function mapRow(array $row, array $mapping): array
    {
        $data = [];
        foreach ($mapping as $field => $colIndex) {
            if ($colIndex !== null && $colIndex !== '') {
                $raw   = $row[(int)$colIndex] ?? null;
                $value = ($raw !== null && trim((string)$raw) !== '') ? trim((string)$raw) : null;
                $data[$field] = $value;
            }
        }

        // Resolve category name → ID
        if (!empty($data['category_name'])) {
            $slug = \Illuminate\Support\Str::slug($data['category_name']);
            $cat  = Category::firstOrCreate(
                ['slug' => $slug],
                ['name' => $data['category_name'], 'slug' => $slug]
            );
            $data['category_id'] = $cat->id;
        }
        unset($data['category_name']);

        // Normalise joining_date
        if (!empty($data['joining_date'])) {
            try {
                $data['joining_date'] = \Carbon\Carbon::parse($data['joining_date'])->format('Y-m-d');
            } catch (\Exception) {
                $data['joining_date'] = null;
            }
        }

        // Default client_status to 'Running' when absent or invalid
        if (empty($data['client_status']) || !\in_array($data['client_status'], Client::$statuses, true)) {
            $data['client_status'] = 'Running';
        }

        // Fallback brand_name → client_name
        if (empty($data['brand_name']) && !empty($data['client_name'])) {
            $data['brand_name'] = $data['client_name'];
        }

        // client_name is required
        if (empty($data['client_name'])) {
            throw new \InvalidArgumentException('client_name is required and cannot be empty');
        }

        return $data;
    }

    /**
     * Return only the keys whose values differ from the existing model.
     * Ignores audit fields so they don't count as "changes".
     */
    private function diffData(Client $existing, array $incoming): array
    {
        $ignore  = ['created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'];
        $changed = [];

        foreach ($incoming as $key => $value) {
            if (\in_array($key, $ignore, true)) {
                continue;
            }
            // Loose comparison; cast both sides to string for date/numeric safety
            $existingVal = $existing->getAttribute($key);
            if ((string)$existingVal !== (string)($value ?? '')) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    private function loadSpreadsheet(UploadedFile $file): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($file->getPathname());
        $reader->setReadDataOnly(true);
        return $reader->load($file->getPathname());
    }
}
