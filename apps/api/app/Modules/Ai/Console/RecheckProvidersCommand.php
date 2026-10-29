<?php

namespace App\Modules\Ai\Console;

use App\Modules\Ai\Actions\TestAiProvider;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\KeyFailure;
use App\Modules\Ai\Support\ProviderCheckFailed;
use Illuminate\Console\Command;

/**
 * A provider set aside for what passes outside of it (out of credit, a rate limit) is checked again every hour: credit
 * added on the provider's site brings it back with no one opening the providers screen (29.10: OpenAI ran out of credit,
 * and stayed set aside after it was added). A key refused or not allowed waits for a new key, as before. The check lists
 * the provider's models; when the credit is still out, the next live call sets it aside again.
 */
final class RecheckProvidersCommand extends Command
{
    protected $signature = 'ai:recheck-providers';

    protected $description = 'Check again the model providers set aside for credit or a rate limit';

    /** What passes without a new key. */
    private const PASSING = [KeyFailure::NO_CREDIT, ProviderCheckFailed::RATE_LIMITED];

    public function handle(TestAiProvider $test): int
    {
        foreach (AiProvider::query()->where('status', ProviderStatus::Failed)->whereIn('last_error_code', self::PASSING)->get() as $provider) {
            $run = $test->handle($provider);
            $this->line($provider->provider->value.': '.$run->status->value);
        }

        return self::SUCCESS;
    }
}
