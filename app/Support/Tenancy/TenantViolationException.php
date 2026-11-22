<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when code attempts to read or write data across tenant
 * boundaries. This always indicates a programming error or an attack and
 * must never be caught and ignored.
 */
class TenantViolationException extends RuntimeException {}
