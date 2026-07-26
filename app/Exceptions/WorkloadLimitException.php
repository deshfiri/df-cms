<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when strict_workload_limit is enabled and a task would be assigned to
 * an already-overloaded employee. Rendered globally in bootstrap/app.php.
 */
class WorkloadLimitException extends RuntimeException
{
}
