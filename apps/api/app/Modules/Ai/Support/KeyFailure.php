<?php

namespace App\Modules\Ai\Support;

use Throwable;

/**
 * Whether a failed call says the key itself does not work: refused (401), not allowed (403), or out of
 * credit. Not an outage or a slow reply, which pass; these stay until someone changes the key. Read
 * from the provider's own status and words, through the whole chain of exceptions.
 */
final class KeyFailure
{
    public const NO_CREDIT = 'no_credit';

    /** @return string|null a ProviderCheckFailed code, or NO_CREDIT; null when the key is not the trouble */
    public static function of(Throwable $e): ?string
    {
        for ($at = $e; $at !== null; $at = $at->getPrevious()) {
            $message = strtolower($at->getMessage());

            if (preg_match('/credit balance is too low|insufficient_quota|exceeded your current quota|billing/', $message) === 1) {
                return self::NO_CREDIT;
            }

            if (preg_match('/invalid x-api-key|incorrect api key|invalid_api_key|authentication_error/', $message) === 1) {
                return ProviderCheckFailed::INVALID_KEY;
            }

            $status = self::status($at);
            if ($status === 401) {
                return ProviderCheckFailed::INVALID_KEY;
            }
            if ($status === 403) {
                return ProviderCheckFailed::PERMISSION_DENIED;
            }
        }

        return null;
    }

    /** The HTTP status an SDK's exception carries, however it names it. */
    private static function status(Throwable $e): ?int
    {
        foreach (['getStatusCode', 'statusCode', 'getStatus'] as $method) {
            if (method_exists($e, $method)) {
                $status = $e->{$method}();

                return is_numeric($status) ? (int) $status : null;
            }
        }

        foreach (['status', 'statusCode'] as $property) {
            if (property_exists($e, $property) && is_numeric($e->{$property} ?? null)) {
                return (int) $e->{$property};
            }
        }

        return null;
    }
}
