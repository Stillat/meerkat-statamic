<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Exceptions;

use RuntimeException;

/**
 * Thrown when a spam guard cannot reach a verdict
 *
 * e.g. the upstream API is unreachable, errors, or returns an unparseable body
 */
class SpamGuardException extends RuntimeException {}
