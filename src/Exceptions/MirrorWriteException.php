<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Exceptions;

use RuntimeException;

/**
 * Thrown when the filesystem mirror cannot be written.
 */
class MirrorWriteException extends RuntimeException {}
