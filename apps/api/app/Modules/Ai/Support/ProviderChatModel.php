<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Contracts\WebAnswer;
use App\Modules\Ai\Contracts\WebSearchDriver;
use App\Modules\Ai\Contracts\WebSearchModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\CallJournal;
use Throwable;

/**
 * Hands a chat request to the driver of the provider asked for, with that provider's key. The
 * drivers are the ones AiServiceProvider registers; a provider without one is refused as
 * unsupported, the same way as before there were drivers.
 *
 * Every call, answered or failed, is written to the call journal with its prompt and reply, so
 * the operator can open it from the pipeline. A picture sent is noted by type and size only.
 */
final class ProviderChatModel implements ChatModel, VisionModel, WebSearchModel
{
    /** @param iterable<ChatDriver> $drivers */
    public function __construct(
        private readonly iterable $drivers,
        private readonly ?CallJournal $journal = null,
    ) {}

    public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $this->logged(CallJournal::CHAT, $provider, $model, $system, $user,
                    fn (): ModelReply => $driver->json(ProviderKey::for($provider), $model, $system, $user, $maxOutputTokens, $reasoningEffort));
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }

    public function research(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, int $maxSearches): WebAnswer
    {
        foreach ($this->drivers as $driver) {
            if ($driver instanceof WebSearchDriver && $driver->provider() === $provider) {
                return $this->logged(CallJournal::WEB, $provider, $model, $system, $user,
                    fn (): WebAnswer => $driver->research(ProviderKey::for($provider), $model, $system, $user, $maxOutputTokens, $maxSearches));
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }

    public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        foreach ($this->drivers as $driver) {
            if ($driver instanceof VisionDriver && $driver->provider() === $provider) {
                return $this->logged(CallJournal::VISION, $provider, $model, $system, $user."\n\n".self::picture($mime, $bytes),
                    fn (): ModelReply => $driver->jsonWithImage(ProviderKey::for($provider), $model, $system, $user, $mime, $bytes, $maxOutputTokens, $reasoningEffort));
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }

    /** How a picture sent appears in the journal: its type and size, never its bytes. */
    public static function picture(string $mime, string $bytes): string
    {
        return '[image '.$mime.', '.(int) ceil(strlen($bytes) / 1024).' KB]';
    }

    /**
     * @param  callable(): (ModelReply|WebAnswer)  $call
     */
    private function logged(string $kind, AiProviderName $provider, string $model, string $system, string $sent, callable $call): ModelReply|WebAnswer
    {
        $clock = hrtime(true);

        try {
            $answer = $call();
        } catch (Throwable $e) {
            $this->journal?->record($kind, $provider->value, $model, $system, $sent, null, class_basename($e).': '.$e->getMessage(), 0, 0, self::ms($clock));

            throw $e;
        }

        $reply = $answer instanceof WebAnswer ? $answer->reply : $answer;
        $received = $answer instanceof WebAnswer
            ? ['reply' => $reply->data, 'sources' => $answer->urls, 'searches' => $answer->searches]
            : $reply->data;

        $this->journal?->record($kind, $provider->value, $model, $system, $sent, $received, null, $reply->inputTokens, $reply->outputTokens, self::ms($clock));

        return $answer;
    }

    public static function ms(int|float $clock): int
    {
        return (int) round((hrtime(true) - $clock) / 1_000_000);
    }
}
