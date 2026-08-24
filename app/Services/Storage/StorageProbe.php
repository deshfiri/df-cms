<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a provider actually works before anyone trusts it with a document.
 *
 * A credentials check that only validates the shape of a key tells you nothing
 * — the interesting failures are a bucket that does not exist, a token without
 * write permission, and a delivery URL that 404s. So this does the round trip
 * for real: write a small object, read it back, compare it, delete it.
 *
 * The probe file is named with a random token under a dedicated folder, so a
 * test can never collide with, or overwrite, a stored document.
 */
class StorageProbe
{
    private const FOLDER = '_connection-test';

    /**
     * @return array{ok:bool, message:string, steps:array<int,array{label:string, ok:bool}>}
     */
    public function run(string $disk): array
    {
        $path     = self::FOLDER . '/' . Str::random(32) . '.txt';
        $expected = 'dfcp storage probe ' . now()->toIso8601String();
        $steps    = [];

        try {
            $filesystem = Storage::disk($disk);
        } catch (Throwable $e) {
            return $this->failure('Could not open that disk: ' . $e->getMessage(), $steps);
        }

        try {
            $filesystem->put($path, $expected);
            $steps[] = ['label' => 'Upload a test file', 'ok' => true];
        } catch (Throwable $e) {
            $steps[] = ['label' => 'Upload a test file', 'ok' => false];

            return $this->failure($this->explain($e), $steps);
        }

        // From here on the probe file exists, so every exit has to clean up.
        try {
            $readBack = $filesystem->get($path);
            $matches  = $readBack === $expected;
            $steps[]  = ['label' => 'Read it back', 'ok' => $matches];

            if (!$matches) {
                return $this->failure(
                    'The file was stored but came back different or empty. Check that the delivery URL points at this bucket.',
                    $steps,
                    fn () => $this->cleanUp($filesystem, $path),
                );
            }
        } catch (Throwable $e) {
            $steps[] = ['label' => 'Read it back', 'ok' => false];

            return $this->failure($this->explain($e), $steps, fn () => $this->cleanUp($filesystem, $path));
        }

        try {
            $filesystem->delete($path);
            $steps[] = ['label' => 'Delete it', 'ok' => true];
        } catch (Throwable $e) {
            $steps[] = ['label' => 'Delete it', 'ok' => false];

            return $this->failure(
                'Uploads and downloads work, but the credentials cannot delete. Grant delete permission, or removing a file here will leave it behind on the provider. ' . $this->explain($e),
                $steps,
            );
        }

        return [
            'ok'      => true,
            'message' => 'Connected. Uploaded, read back and deleted a test file successfully.',
            'steps'   => $steps,
        ];
    }

    private function cleanUp(mixed $filesystem, string $path): void
    {
        try {
            $filesystem->delete($path);
        } catch (Throwable) {
            // Best effort — the caller is already reporting a failure.
        }
    }

    /**
     * @param  array<int,array{label:string, ok:bool}>  $steps
     * @return array{ok:bool, message:string, steps:array<int,array{label:string, ok:bool}>}
     */
    private function failure(string $message, array $steps, ?callable $cleanUp = null): array
    {
        if ($cleanUp) {
            $cleanUp();
        }

        return ['ok' => false, 'message' => $message, 'steps' => $steps];
    }

    /**
     * Flysystem wraps the useful part of a failure in a previous exception —
     * an administrator needs "bucket does not exist", not "unable to write".
     */
    private function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        if ($previous = $e->getPrevious()) {
            $message .= ' — ' . $previous->getMessage();
        }

        return Str::limit($message, 400);
    }
}
