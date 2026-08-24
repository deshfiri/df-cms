<?php

namespace App\Services\Storage\Cloudinary;

use RuntimeException;

/** Cloudinary said no. Carries a message fit to show an administrator. */
class CloudinaryException extends RuntimeException
{
}
