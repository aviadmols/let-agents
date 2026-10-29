<?php

namespace App\Modules\Ai\Contracts;

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
}
