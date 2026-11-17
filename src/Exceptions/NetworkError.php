<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Exceptions;

use RuntimeException;

/** No answer came back: DNS, TLS, timeout or a refused connection. */
final class NetworkError extends RuntimeException {}
