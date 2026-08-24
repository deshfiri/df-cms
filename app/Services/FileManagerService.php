<?php

namespace App\Services;

use App\Services\Storage\StorageSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shared drive: a browsable folder tree rather than a set of records.
 *
 * It follows the storage provider like everything else, with one wrinkle. There
 * is no per-file database row here to remember where each file went, so the
 * drive can only ever show one disk at a time. Self-hosted therefore keeps its
 * own long-standing `file_manager` disk untouched, and a CDN gets a dedicated
 * prefix — switching provider changes which drive you are looking at, and
 * `storage:migrate` is what carries the contents across.
 */
class FileManagerService
{
    /** Where the drive lives while self-hosted. Unchanged, so nothing moves. */
    private const LOCAL_DISK = 'file_manager';

    /** Folder the drive occupies on a shared CDN bucket. */
    private const REMOTE_ROOT = 'file-manager';

    /**
     * Object stores have no empty directories — a folder exists only while
     * something is filed under it. This placeholder is what makes "create an
     * empty folder" survive, and it is filtered back out of every listing.
     */
    private const KEEP_FILE = '.keep';

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly StorageSettings $storage,
    ) {}

    /** The disk the drive currently lives on. */
    public function diskName(): string
    {
        $active = $this->storage->activeDisk();

        return $active === 'local' ? self::LOCAL_DISK : $active;
    }

    /** True when the drive is on an object store rather than this server. */
    private function isRemote(): bool
    {
        return $this->diskName() !== self::LOCAL_DISK;
    }

    /** A drive-relative path as the underlying disk sees it. */
    private function full(string $path): string
    {
        if (!$this->isRemote()) {
            return $path;
        }

        return trim(self::REMOTE_ROOT . '/' . $path, '/');
    }

    /** The inverse of full(): what the UI should be shown. */
    private function relative(string $path): string
    {
        if (!$this->isRemote()) {
            return $path;
        }

        $prefix = self::REMOTE_ROOT . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

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
        $here = $this->full($path);

        // On an object store the root is implicit and exists() answers false for
        // it, so only a named sub-folder is worth checking.
        if ($path !== '' && !$disk->exists($here) && !$disk->directoryExists($here)) {
            throw new RuntimeException('Folder not found.');
        }

        $folders = collect($disk->directories($here))
            ->map(fn ($full) => [
                'name'     => basename($full),
                'path'     => $this->relative($full),
                'is_dir'   => true,
                'size'     => null,
                'modified' => null,
            ]);

        $files = collect($disk->files($here))
            // The placeholder that keeps an empty folder alive is plumbing.
            ->reject(fn ($full) => basename($full) === self::KEEP_FILE)
            ->map(function ($full) use ($disk) {
                $mime = $disk->mimeType($full) ?: null;

                return [
                    'name'     => basename($full),
                    'path'     => $this->relative($full),
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
        $full = $this->full($target);

        $disk = $this->disk();
        if ($disk->exists($full) || $disk->directoryExists($full)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        if ($this->isRemote()) {
            // makeDirectory is a no-op where directories are only prefixes, so
            // the folder would vanish the moment the page reloaded. Carries a
            // byte of content because some object stores reject a zero-length
            // upload outright.
            $disk->put($full . '/' . self::KEEP_FILE, "folder placeholder\n");
        } else {
            $disk->makeDirectory($full);
        }

        $this->activityLog->log('File Manager', 'Folder Created', null, null, ['path' => $target], null);
    }

    public function upload(?string $path, UploadedFile $file): string
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();

        $name = $this->uniqueName($disk, $path, $this->sanitizeName($file->getClientOriginalName()));
        $disk->putFileAs($this->full($path), $file, $name);

        $stored = trim($path . '/' . $name, '/');
        $this->activityLog->log('File Manager', 'File Uploaded', null, null, ['path' => $stored], null);

        return $stored;
    }

    /**
     * Stream a file back to the browser.
     *
     * Streamed rather than served from an absolute path: ->path() exists only
     * on local disks, and the drive may be on R2 or Cloudinary.
     */
    public function download(string $path): StreamedResponse
    {
        $full = $this->existingFile($path);

        return $this->disk()->download($full, basename($full));
    }

    public function preview(string $path): StreamedResponse
    {
        $full = $this->existingFile($path);
        $mime = $this->disk()->mimeType($full) ?: 'application/octet-stream';

        return $this->disk()->response($full, basename($full), [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($full) . '"',
        ]);
    }

    /** Resolve a drive path to a real file, or refuse. */
    private function existingFile(string $path): string
    {
        $path = $this->sanitizePath($path);
        $full = $this->full($path);
        $disk = $this->disk();

        if ($path === '' || basename($path) === self::KEEP_FILE || !$disk->exists($full) || $disk->directoryExists($full)) {
            throw new RuntimeException('File not found.');
        }

        return $full;
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
        $target = $this->full(trim($folder . '/' . $filename, '/'));
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
        $full = $this->full($path);

        if ($path === '' || (!$disk->exists($full) && !$disk->directoryExists($full))) {
            throw new RuntimeException('Item not found.');
        }

        $target = trim(dirname($path) === '.' ? $newName : dirname($path) . '/' . $newName, '/');
        $fullTarget = $this->full($target);

        if ($disk->exists($fullTarget) || $disk->directoryExists($fullTarget)) {
            throw new RuntimeException('A file or folder with that name already exists.');
        }

        $disk->move($full, $fullTarget);
        $this->activityLog->log('File Manager', 'Renamed', null, ['path' => $path], ['path' => $target], null);
    }

    public function delete(string $path): void
    {
        $path = $this->sanitizePath($path);
        $disk = $this->disk();
        $full = $this->full($path);

        if ($path === '' || (!$disk->exists($full) && !$disk->directoryExists($full))) {
            throw new RuntimeException('Item not found.');
        }

        if ($disk->directoryExists($full)) {
            $disk->deleteDirectory($full);
        } else {
            $disk->delete($full);
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

        while ($disk->exists($this->full(trim($path . '/' . $candidate, '/')))) {
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
        return Storage::disk($this->diskName());
    }
}
