<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\ImageDriver;
use App\Modules\Ai\Contracts\ImageModel;
use App\Modules\Ai\Contracts\ImageReply;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;

/** Hands a picture request to the driver of the provider asked for, with that provider's key. */
final class ProviderImageModel implements ImageModel
{
    /** @param iterable<ImageDriver> $drivers */
    public function __construct(private readonly iterable $drivers) {}

    public function generate(AiProviderName $provider, string $model, string $prompt, array $images): ImageReply
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver->generate(ProviderKey::for($provider), $model, $prompt, $images);
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
