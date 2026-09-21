<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * The largest file an upload field can really take.
 *
 * The app's own rule is only half of it: PHP drops anything over
 * upload_max_filesize / post_max_size before Laravel ever sees the request, and
 * the person gets a vague "failed to upload". Telling the browser the true
 * limit lets it refuse an oversized file up front, with the real number.
 */
final class UploadLimit
{
    /** @param int $appMaxKb the field's own validation limit, in KB (as in 'max:20480') */
    public static function bytes(int $appMaxKb): int
    {
        return (int) min($appMaxKb * 1024, UploadedFile::getMaxFilesize());
    }

    /** "20 MB", "2 MB", "512 KB". */
    public static function label(int $bytes): string
    {
        return $bytes >= 1048576
            ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}
