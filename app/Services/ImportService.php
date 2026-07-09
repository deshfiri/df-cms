<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Client;
use App\Models\ImportLog;
use App\Models\Payment;
use App\Models\ProductUpdate;
use App\Models\WorkflowStage;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ImportService
{
    /** Sheet checkbox column → matching workflow_stages.code (a legacy flag can cover more than one granular stage). */
    private const STAGE_FLAG_CODES = [
        'stage_agreement_signed' => ['agreement_signed'],
        'stage_website_done'     => ['website_development', 'website_approved'],
        'stage_branding_done'    => ['logo_design', 'banner_design'],
        'stage_sourcing_done'    => ['product_sourcing'],
        'stage_content_done'     => ['marketing_content_creation'],
        'stage_launch_done'      => ['marketing_launch'],
    ];

    /** Mappable keys that map directly onto `clients` table columns (or resolve to one, like category_name). */
    private const CLIENT_FIELDS = [
        'dfid_number', 'client_name', 'brand_name', 'website', 'page_link',
        'category_name', 'joining_date', 'client_status', 'doc_status', 'remarks',
    ];

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
        $stageIdsByCode  = WorkflowStage::pluck('id', 'code');

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 2;
                try {
                    $data = $this->mapClientFields($row, $mapping);

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
                        // UPDATE — a blank cell means "leave this field as-is", not
                        // "clear it out". Only fields the sheet actually provided a
                        // value for are eligible to be written.
                        $provided = array_filter($data, fn ($value) => $value !== null);
                        $changed  = $this->diffData($existing, $provided);

                        if (empty($changed)) {
                            $skippedCount++;
                        } else {
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
                        }
                        $client = $existing;
                    } else {
                        // CREATE — a brand new client, so sensible defaults for
                        // blank optional fields apply (nothing existing to protect).
                        if (empty($data['client_status']) || !\in_array($data['client_status'], Client::$statuses, true)) {
                            $data['client_status'] = 'Running';
                        }
                        if (empty($data['brand_name']) && !empty($data['client_name'])) {
                            $data['brand_name'] = $data['client_name'];
                        }

                        $client = $this->clientRepo->create(array_merge($data, [
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]));
                        $this->workflowRepo->initClientStages($client->id);
                        $this->activityLog->log('Import', 'Client Imported', $client->id, null, $data);
                        $newCount++;
                    }

                    // ── Workflow stage flags (Website Done, Agreement, …) ──
                    foreach ($this->mapStageFlags($row, $mapping) as $code => $done) {
                        if ($done && isset($stageIdsByCode[$code])) {
                            $this->workflowRepo->toggleStage($client->id, $stageIdsByCode[$code], true, Auth::id());
                        }
                    }

                    // ── Product update / payment history (one row per client, updated in place) ──
                    ['product' => $productData, 'payment' => $paymentData] = $this->mapProductPayment($row, $mapping);

                    if (!empty($productData)) {
                        $existingUpdate = ProductUpdate::where('client_id', $client->id)->first();
                        if ($existingUpdate) {
                            $existingUpdate->update($productData);
                        } else {
                            ProductUpdate::create($productData + [
                                'client_id'  => $client->id,
                                'created_by' => Auth::id(),
                                'status'     => $productData['status'] ?? 'Processing',
                            ]);
                        }
                    }

                    if (!empty($paymentData)) {
                        $existingPayment = Payment::where('client_id', $client->id)->first();
                        if ($existingPayment) {
                            $existingPayment->update($paymentData);
                        } else {
                            Payment::create($paymentData + [
                                'client_id'  => $client->id,
                                'created_by' => Auth::id(),
                            ]);
                        }
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
            // clients table
            'dfid_number'   => 'DFID Number',
            'client_name'   => 'Client Name',
            'brand_name'    => 'Brand / Page Name',
            'website'       => 'Website Link',
            'page_link'     => 'Page Link (Facebook/Brand Page)',
            'category_name' => 'Category',
            'joining_date'  => 'Joining Date',
            'client_status' => 'Client Status',
            'doc_status'    => 'DOC Status',
            'remarks'       => 'Notes / Remarks',

            // workflow stage flags — routed to client_stage_progress, not a clients column
            'stage_agreement_signed' => 'Agreement Done',
            'stage_website_done'     => 'Website Done',
            'stage_branding_done'    => 'Branding Done',
            'stage_sourcing_done'    => 'Sourcing Done',
            'stage_content_done'     => 'Content Done',
            'stage_launch_done'      => 'Launching Done',

            // product_updates table (one row per client, updated in place)
            'product_status'        => 'Product Update',
            'product_received_date' => 'Product Received Date',

            // payments table (one row per client, updated in place)
            'payment_status' => 'Product Payment (Paid/Partial/Unpaid)',
            'payment_date'   => 'Product Payment Date',
            'payment_note'   => 'Note',
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function mapClientFields(array $row, array $mapping): array
    {
        $data = [];
        foreach (self::CLIENT_FIELDS as $field) {
            if (isset($mapping[$field]) && $mapping[$field] !== '' && $mapping[$field] !== null) {
                $data[$field] = $this->cellValue($row, $mapping[$field]);
            }
        }

        // Resolve category name → ID
        if (!empty($data['category_name'])) {
            $slug = Str::slug($data['category_name']);
            $cat  = Category::firstOrCreate(
                ['slug' => $slug],
                ['name' => $data['category_name'], 'slug' => $slug]
            );
            $data['category_id'] = $cat->id;
        }
        unset($data['category_name']);

        // Normalise joining_date
        if (!empty($data['joining_date'])) {
            $data['joining_date'] = $this->parseFlexibleDate($data['joining_date']);
        }

        // An invalid (non-blank) status string is sanitized to "not provided"
        // rather than written as-is — a blank cell already maps to null above.
        // Callers decide what "not provided" means: the create path below
        // defaults it to 'Running', the update path just leaves it untouched.
        if (!empty($data['client_status']) && !\in_array($data['client_status'], Client::$statuses, true)) {
            $data['client_status'] = null;
        }

        // client_name is required
        if (empty($data['client_name'])) {
            throw new \InvalidArgumentException('client_name is required and cannot be empty');
        }

        return $data;
    }

    /**
     * Legacy sheet checkboxes ("Website Done", "Agreement", …) → completed
     * workflow_stages.code entries. Only truthy cells are returned — a blank or
     * "no"-ish cell never un-completes a stage that's already done.
     */
    private function mapStageFlags(array $row, array $mapping): array
    {
        $flags = [];
        foreach (self::STAGE_FLAG_CODES as $field => $codes) {
            if (!isset($mapping[$field]) || $mapping[$field] === '' || $mapping[$field] === null) {
                continue;
            }
            if ($this->isTruthyCell($this->cellValue($row, $mapping[$field]))) {
                foreach ($codes as $code) {
                    $flags[$code] = true;
                }
            }
        }

        return $flags;
    }

    private function isTruthyCell(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return \in_array(strtolower($value), ['done', 'true', '1', 'yes', 'y', 'x', '✓', 'checked', 'complete', 'completed'], true);
    }

    /**
     * Product Update / Received Date → product_updates; Product Payment /
     * Payment Date / Note → payments. Both are history tables, but the sheet
     * only ever carries the client's latest snapshot, so only fields the sheet
     * actually provided a value for are returned (blank = no change here too).
     */
    private function mapProductPayment(array $row, array $mapping): array
    {
        $product = [];
        if ($status = $this->mappedCellValue($row, $mapping, 'product_status')) {
            $product['status'] = $status;
        }
        if ($received = $this->mappedCellValue($row, $mapping, 'product_received_date')) {
            if ($parsed = $this->parseFlexibleDate($received)) {
                $product['received_date'] = $parsed;
            }
        }

        $payment = [];
        if ($payStatus = $this->mappedCellValue($row, $mapping, 'payment_status')) {
            $normalized = Str::title(strtolower($payStatus));
            if (\in_array($normalized, Payment::$statuses, true)) {
                $payment['status'] = $normalized;
            }
        }
        if ($payDate = $this->mappedCellValue($row, $mapping, 'payment_date')) {
            if ($parsed = $this->parseFlexibleDate($payDate)) {
                $payment['payment_date'] = $parsed;
            }
        }
        if ($note = $this->mappedCellValue($row, $mapping, 'payment_note')) {
            $payment['remarks'] = $note;
        }

        return ['product' => $product, 'payment' => $payment];
    }

    private function mappedCellValue(array $row, array $mapping, string $field): ?string
    {
        if (!isset($mapping[$field]) || $mapping[$field] === '' || $mapping[$field] === null) {
            return null;
        }

        return $this->cellValue($row, $mapping[$field]);
    }

    private function cellValue(array $row, int|string $colIndex): ?string
    {
        $raw = $row[(int) $colIndex] ?? null;

        return ($raw !== null && trim((string) $raw) !== '') ? trim((string) $raw) : null;
    }

    /**
     * Best-effort date parse. Sheet cells sometimes hold a range like
     * "3 May - 18 May" — take the first side of the range rather than fail.
     * Unparseable input returns null (never overwrites an existing value).
     */
    private function parseFlexibleDate(string $raw): ?string
    {
        $first = preg_split('/\s*[-–]\s*/', $raw, 2)[0] ?? $raw;
        try {
            return \Carbon\Carbon::parse($first)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
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
