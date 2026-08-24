<?php

namespace App\Services\Storage\Cloudinary;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;

/**
 * Cloudinary behind Flysystem, so the application never has to know.
 *
 * The point of this class is that nothing else changes: every controller and
 * service keeps calling Storage::disk($file->disk)->download(...) exactly as it
 * did when everything was on the local filesystem.
 *
 * Where the object-store model and the filesystem model disagree, this leans on
 * the filesystem side being advisory: Cloudinary has no real directories, so
 * folders are implied by the path and creating one is a no-op, and every asset
 * is delivered publicly, so visibility is reported honestly rather than faked.
 */
class CloudinaryAdapter implements FilesystemAdapter
{
    public function __construct(
        private readonly CloudinaryClient $client,
    ) {
    }

    public function fileExists(string $path): bool
    {
        return $this->client->head($path) !== null;
    }

    /**
     * Cloudinary folders exist only as a prefix on stored assets, so a folder
     * "exists" exactly when something is filed under it.
     */
    public function directoryExists(string $path): bool
    {
        try {
            return $this->client->listResources(rtrim($path, '/') . '/') !== [];
        } catch (CloudinaryException) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload($path, $contents);
        } catch (CloudinaryException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $body   = stream_get_contents($stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $body === false ? '' : $body;
    }

    public function readStream(string $path)
    {
        try {
            return $this->client->readStream($path);
        } catch (CloudinaryException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->destroy($path);
        } catch (CloudinaryException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /** No directory objects to remove — clearing one means clearing its assets. */
    public function deleteDirectory(string $path): void
    {
        foreach ($this->listContents($path, true) as $item) {
            if ($item->isFile()) {
                $this->delete($item->path());
            }
        }
    }

    /** Nothing to create: the folder appears when the first asset is filed under it. */
    public function createDirectory(string $path, Config $config): void
    {
    }

    /**
     * Raw assets are delivered from the public CDN, and this adapter does not
     * pretend otherwise. Confidentiality here comes from unguessable stored
     * names plus the application proxying every download, not from the disk.
     */
    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: Visibility::PUBLIC);
    }

    public function mimeType(string $path): FileAttributes
    {
        $headers = $this->headOrFail($path, 'mimeType');

        return new FileAttributes($path, mimeType: $headers['mime']);
    }

    public function lastModified(string $path): FileAttributes
    {
        $headers  = $this->headOrFail($path, 'lastModified');
        $modified = $headers['last_modified'] !== '' ? strtotime($headers['last_modified']) : false;

        return new FileAttributes($path, lastModified: $modified ?: null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $headers = $this->headOrFail($path, 'fileSize');

        return new FileAttributes($path, fileSize: (int) $headers['size']);
    }

    /**
     * @return iterable<FileAttributes|DirectoryAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';

        try {
            $resources = $this->client->listResources($prefix);
        } catch (CloudinaryException) {
            return;
        }

        // Cloudinary returns a flat list of assets; folders exist only as the
        // slashes inside their ids. A shallow listing therefore has to derive
        // its sub-folders here, or a browsable drive would show no folders at
        // all — only the files at the very top.
        $directories = [];

        foreach ($resources as $resource) {
            $itemPath = $this->client->toPath((string) ($resource['public_id'] ?? ''));

            if ($itemPath === '' || !str_starts_with($itemPath, $prefix)) {
                continue;
            }

            $remainder = substr($itemPath, strlen($prefix));

            if (!$deep && str_contains($remainder, '/')) {
                // Not a file here, but it proves a folder here.
                $directories[$prefix . strstr($remainder, '/', true)] = true;
                continue;
            }

            yield new FileAttributes(
                $itemPath,
                fileSize: isset($resource['bytes']) ? (int) $resource['bytes'] : null,
                lastModified: isset($resource['created_at']) ? (strtotime($resource['created_at']) ?: null) : null,
                mimeType: $resource['resource_type'] ?? null,
            );
        }

        foreach (array_keys($directories) as $directory) {
            yield new DirectoryAttributes($directory);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->rename($source, $destination);
        } catch (CloudinaryException $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $stream = $this->client->readStream($source);
            $this->client->upload($destination, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (CloudinaryException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Laravel's Storage::url() picks this up when the disk config has no 'url'
     * of its own, which is how a stored path turns into a CDN link.
     */
    public function getUrl(string $path): string
    {
        return $this->client->url($path);
    }

    /** @return array<string,string> */
    private function headOrFail(string $path, string $metadata): array
    {
        $headers = $this->client->head($path);

        if ($headers === null) {
            throw UnableToRetrieveMetadata::create($path, $metadata, 'Cloudinary has no asset at this path.');
        }

        return $headers;
    }
}
