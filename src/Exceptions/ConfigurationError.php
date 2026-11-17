<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Exceptions;

use RuntimeException;

/** A setting is missing or malformed. The message says which one, never its value. */
final class ConfigurationError extends RuntimeException {}
