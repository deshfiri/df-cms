<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileManagerService
{
    private const DISK = 'file_manager';

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Strips any '.', '..', and empty segments so callers can never escape
     * the file-manager disk's root via path traversal.
     */
    public function sanitizePath(?string $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $segments = array_filter(
            explode('/', $path),
            fn ($segment) => $segment !== '' && $segment !== '.' && $segment !== '..'
        );

        return implode('/', $segments);
    }

    public function list(?string $path): array
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();

        if ($path !== '' && !$disk->exists($path)) {
            throw new RuntimeException('Folder not found.');
        }

        $folders = collect($disk->directories($path))
            ->map(fn ($full) => [
                'name'     => basename($full),
                'path'     => $full,
                'is_dir'   => true,
                'size'     => null,
                'modified' => null,
            ]);

        $files = collect($disk->files($path))
            ->map(function ($full) use ($disk) {
                $mime = $disk->mimeType($full) ?: null;

                return [
                    'name'     => basename($full),
                    'path'     => $full,
                    'is_dir'   => false,
                    'size'     => $this->formatSize($disk->size($full)),
                    'modified' => date('d M Y H:i', $disk->lastModified($full)),
                    'is_image' => $mime !== null && str_starts_with($mime, 'image/'),
                    'is_pdf'   => $mime === 'application/pdf',
                ];
            });

        $items = $folders->concat($files)
            ->sortBy([['is_dir', 'desc'], ['name', 'asc']])
            ->values();

        return [
            'path'       => $path,
            'breadcrumb' => $this->breadcrumb($path),
            'items'      => $items->all(),
        ];
    }

    public function createFolder(?string $path, string $name): void
    {
        $path = $this->sanitizePath($path);
        $name = $this->sanitizeName($name);
        $target = trim($path . '/' . $name, '/');

        $disk = $this->disk();
        if ($disk->exists($target)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        $disk->makeDirectory($target);
        $this->activityLog->log('File Manager', 'Folder Created', null, null, ['path' => $target], null);
    }

    public function upload(?string $path, UploadedFile $file): string
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();

        $name = $this->uniqueName($disk, $path, $this->sanitizeName($file->getClientOriginalName()));
        $disk->putFileAs($path, $file, $name);

        $stored = trim($path . '/' . $name, '/');
        $this->activityLog->log('File Manager', 'File Uploaded', null, null, ['path' => $stored], null);

        return $stored;
    }

    public function download(string $path): array
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();

        if ($path === '' || !$disk->exists($path) || $disk->directoryExists($path)) {
            throw new RuntimeException('File not found.');
        }

        return [$disk->path($path), basename($path)];
    }

    public function resolvePreview(string $path): array
    {
        [$absolutePath, $name] = $this->download($path);
        $mime = $this->disk()->mimeType($this->sanitizePath($path)) ?: 'application/octet-stream';

        return [$absolutePath, $name, $mime];
    }

    /**
     * Copies a file from another disk (e.g. the private client-documents
     * storage) into the File Manager, skipping it if a file with that exact
     * name is already there — safe to re-run for backfilling.
     */
    public function mirrorExistingFile(string $sourceDisk, string $sourcePath, ?string $folder, string $filename): ?string
    {
        $folder = $this->sanitizePath($folder);
        $filename = $this->sanitizeName($filename);
        $target = trim($folder . '/' . $filename, '/');
        $disk = $this->disk();

        if ($disk->exists($target)) {
            return null;
        }

        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if ($stream === null || $stream === false) {
            return null;
        }

        $disk->put($target, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $target;
    }

    public function rename(string $path, string $newName): void
    {
        $path = $this->sanitizePath($path);
        $newName = $this->sanitizeName($newName);
        $disk = $this->disk();

        if ($path === '' || !$disk->exists($path)) {
            throw new RuntimeException('Item not found.');
        }

        $target = trim(dirname($path) === '.' ? $newName : dirname($path) . '/' . $newName, '/');
        if ($disk->exists($target)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        $disk->move($path, $target);
        $this->activityLog->log('File Manager', 'Renamed', null, ['path' => $path], ['path' => $target], null);
    }

    public function delete(string $path): void
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();

        if ($path === '' || !$disk->exists($path)) {
            throw new RuntimeException('Item not found.');
        }

        if ($disk->directoryExists($path)) {
            $disk->deleteDirectory($path);
        } else {
            $disk->delete($path);
        }

        $this->activityLog->log('File Manager', 'Deleted', null, null, ['path' => $path], null);
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\'], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid name.');
        }

        return $name;
    }

    private function uniqueName(Filesystem $disk, string $path, string $name): string
    {
        $candidate = $name;
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $i = 1;

        while ($disk->exists(trim($path . '/' . $candidate, '/'))) {
            $candidate = $ext !== '' ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
            $i++;
        }

        return $candidate;
    }

    private function breadcrumb(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $segments = explode('/', $path);
        $crumbs = [];
        $accum = '';
        foreach ($segments as $segment) {
            $accum = trim($accum . '/' . $segment, '/');
            $crumbs[] = ['name' => $segment, 'path' => $accum];
        }

        return $crumbs;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
