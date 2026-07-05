<?php

namespace App\Console\Commands;

use App\Models\ClientDocument;
use App\Services\FileManagerService;
use Illuminate\Console\Command;

class SyncDocumentsToFileManager extends Command
{
    protected $signature = 'file-manager:sync-documents';

    protected $description = 'Mirror existing client documents into the File Manager (documents uploaded before mirroring was added never got copied over)';

    public function handle(FileManagerService $fileManager): int
    {
        $docs = ClientDocument::with('client')->get();

        $mirrored = 0;
        $skipped = 0;

        foreach ($docs as $doc) {
            if (!$doc->client) {
                $skipped++;
                continue;
            }

            $folder = 'Clients/' . str_replace(
                ['/', '\\'],
                '-',
                trim($doc->client->dfid_number . ' - ' . $doc->client->client_name)
            );

            $result = $fileManager->mirrorExistingFile($doc->disk, $doc->path, $folder, $doc->original_name);
            $result ? $mirrored++ : $skipped++;
        }

        $this->info("Mirrored: {$mirrored}, skipped (already present or unreadable): {$skipped}");

        return self::SUCCESS;
    }
}
