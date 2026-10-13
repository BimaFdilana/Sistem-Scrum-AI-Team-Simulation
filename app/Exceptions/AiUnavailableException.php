<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AI tidak dapat memenuhi permintaan setelah upaya terbaik (retry + fallback).
 * $errorCode membedakan penyebabnya agar pesan ke pengguna spesifik:
 * UPSTREAM_OVERLOADED | RATE_LIMITED | TIMEOUT | GATEWAY_UNREACHABLE |
 * AUTH_FAILED | MODEL_NOT_FOUND | BAD_REQUEST | EMPTY_RESPONSE | DISABLED
 */
class AiUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'AI unavailable: '.$errorCode);
    }
}
