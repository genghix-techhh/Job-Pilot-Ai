<?php

namespace App\Services\Http;

use RuntimeException;

/** Thrown when a URL is refused for safety reasons (SSRF guard) or a fetch breaks a limit. */
class UnsafeUrlException extends RuntimeException {}
