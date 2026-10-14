<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ImageDriver;
use App\Modules\Ai\Contracts\ImageReply;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pictures through Replicate's HTTP API (there is no official PHP SDK): one prediction of an
 * official model, waited for in the same request (`Prefer: wait`), polled until done if it takes
 * longer, then the picture fetched.
 *
 * Models name their inputs differently; INPUTS maps a model to how it takes the pictures. A model not
 * listed gets the common form (`image_input`, a list). Input pictures go as data URIs, so the caller
 * keeps them small (about 1024px).
 */
final class ReplicateImages implements ImageDriver
{
    private const API = 'https://api.replicate.com/v1';

    /** How long one request waits for the model, and how long in all before giving up. */
    private const WAIT_SECONDS = 60;

    private const GIVE_UP_SECONDS = 240;

    /**
     * model => the input field for the pictures, whether it takes a list or a single picture, and
     * what else it is always sent. `aspect_ratio: match_input_image` keeps the first picture's shape
     * and framing: without it a model may draw a new picture of its own shape instead of editing
     * the one it was given (a landscape hand came back as a portrait with a face that was not there).
     */
    public const INPUTS = [
        'google/nano-banana' => ['field' => 'image_input', 'list' => true, 'with' => ['aspect_ratio' => 'match_input_image']],
        'google/nano-banana-pro' => ['field' => 'image_input', 'list' => true, 'with' => ['aspect_ratio' => 'match_input_image']],
        'bytedance/seedream-4' => ['field' => 'image_input', 'list' => true, 'with' => ['aspect_ratio' => 'match_input_image']],
        'black-forest-labs/flux-kontext-pro' => ['field' => 'input_image', 'list' => false, 'with' => ['aspect_ratio' => 'match_input_image']],
        'black-forest-labs/flux-kontext-max' => ['field' => 'input_image', 'list' => false, 'with' => ['aspect_ratio' => 'match_input_image']],
    ];

    public function provider(): AiProviderName
    {
        return AiProviderName::Replicate;
    }

    public function generate(string $apiKey, string $model, string $prompt, array $images): ImageReply
    {
        if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $model) !== 1) {
            throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
        }

        $shape = self::INPUTS[$model] ?? ['field' => 'image_input', 'list' => true];
        $uris = array_map(fn (array $image): string => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']), $images);
        $input = ['prompt' => $prompt, 'output_format' => 'jpg'];
        if ($uris !== []) {
            $input[$shape['field']] = $shape['list'] ? $uris : $uris[0];
            $input += $shape['with'] ?? [];
        }

        $started = microtime(true);

        try {
            $response = Http::withToken($apiKey)->withHeaders(['Prefer' => 'wait='.self::WAIT_SECONDS])->timeout(self::WAIT_SECONDS + 15)
                ->post(self::API.'/models/'.$model.'/predictions', ['input' => $input]);
            $prediction = $response->json();

            if (! $response->successful() || ! is_array($prediction)) {
                throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
            }

            // Not done within the wait: ask again until it is, or until it has taken too long.
            while (in_array($prediction['status'] ?? null, ['starting', 'processing'], true)) {
                if (microtime(true) - $started > self::GIVE_UP_SECONDS || ! is_string($prediction['urls']['get'] ?? null)) {
                    throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
                }
                usleep(app()->runningUnitTests() ? 0 : 2_000_000);
                $prediction = (array) Http::withToken($apiKey)->timeout(20)->get($prediction['urls']['get'])->json();
            }

            $output = $prediction['output'] ?? null;
            $url = is_array($output) ? ($output[0] ?? null) : $output;

            if (($prediction['status'] ?? null) !== 'succeeded' || ! is_string($url)) {
                throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
            }

            $picture = Http::timeout(30)->get($url);
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $picture->successful() || $picture->body() === '') {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
        }

        return new ImageReply(
            $picture->body(),
            (string) ($picture->header('Content-Type') ?: 'image/jpeg'),
            (float) ($prediction['metrics']['predict_time'] ?? round(microtime(true) - $started, 2)),
            is_string($prediction['id'] ?? null) ? $prediction['id'] : null,
        );
    }
}
