<?php

namespace App\Modules\Ai\Contracts;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/** A model call that did not produce a usable answer. The reason is a short code for logs and runs. */
final class ModelCallFailed extends RuntimeException
{
    public const NO_KEY = 'no_key';

    public const PROVIDER_ERROR = 'provider_error';

    /** The provider said too many requests (429), still after a wait: worth asking again later. */
    public const RATE_LIMITED = 'rate_limited';

    public const NOT_JSON = 'not_json';

    public const UNSUPPORTED = 'unsupported';

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($previous?->getMessage() ?? $reason, 0, $previous);
    }

    /**
     * Whether it is the provider's moment, not what was asked: too many requests, the connection or its time running
     * out, or the provider's own error (5xx). Asked again later, the same input goes through; it says nothing of it.
     */
    public function transient(): bool
    {
        return $this->reason === self::RATE_LIMITED
            || $this->getPrevious() instanceof ConnectionException
            || preg_match('/\b(?:429|408|50[0-4])\b|timed out|cURL error 28/i', $this->getMessage()) === 1;
    }
}
