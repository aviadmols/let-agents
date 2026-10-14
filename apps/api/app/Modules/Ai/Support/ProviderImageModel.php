<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ImageDriver;
use App\Modules\Ai\Contracts\ImageModel;
use App\Modules\Ai\Contracts\ImageReply;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Runs\Contracts\CallJournal;
use Throwable;

/**
 * Hands a picture request to the driver of the provider asked for, with that provider's key.
 * Each request is written to the call journal: the prompt and the pictures' types and sizes,
 * never their bytes (Try-On keeps the pictures themselves, in its own log).
 */
final class ProviderImageModel implements ImageModel
{
    /** @param iterable<ImageDriver> $drivers */
    public function __construct(
        private readonly iterable $drivers,
        private readonly ?CallJournal $journal = null,
    ) {}

    public function generate(AiProviderName $provider, string $model, string $prompt, array $images): ImageReply
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                $sent = $prompt."\n";
                foreach ($images as $image) {
                    $sent .= "\n".ProviderChatModel::picture($image['mime'], $image['bytes']);
                }
                $clock = hrtime(true);

                try {
                    $reply = $driver->generate(ProviderKey::for($provider), $model, $prompt, $images);
                } catch (Throwable $e) {
                    $this->journal?->record(CallJournal::IMAGE, $provider->value, $model, null, $sent, null, class_basename($e).': '.$e->getMessage(), 0, 0, ProviderChatModel::ms($clock));

                    throw $e;
                }

                $this->journal?->record(CallJournal::IMAGE, $provider->value, $model, null, $sent,
                    ['image' => ProviderChatModel::picture($reply->mime, $reply->bytes), 'seconds' => $reply->seconds, 'prediction' => $reply->predictionId],
                    null, 0, 0, ProviderChatModel::ms($clock));

                return $reply;
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
