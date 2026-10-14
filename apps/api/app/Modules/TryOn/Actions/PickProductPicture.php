<?php

namespace App\Modules\TryOn\Actions;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Support\Picture;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The product picture a try is made from: one that shows the product alone. A store's main picture
 * is often the piece worn by a model, and the image model then edits the model's hand instead of
 * the shopper's photo. So the product's pictures (its main one, and on Shopify its gallery from the
 * public product JSON) are looked at in order by the small vision model, each once (kept by its
 * address), and the first that shows the product alone and clearly is taken. When none does, the
 * main one is used and the writer is told it shows the piece worn, to take the piece only.
 * Inside the shop's tenant.
 */
final class PickProductPicture
{
    public const PROMPT = 'tryon_product_pick';

    public const PROMPT_VERSION = 1;

    /** Pictures looked at, at most: the main one and a few of the gallery. */
    private const CANDIDATES = 5;

    private const OUTPUT_TOKENS = 120;

    public function __construct(
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{bytes: string, url: string, worn: bool, looked: list<array{url: string, product_only: bool|null}>}|null
     */
    public function handle(TryOnJob $job, RunContext $run): ?array
    {
        $looked = [];
        $first = null;

        foreach ($this->candidates($job) as $url) {
            $bytes = self::download($url);

            if ($bytes === null) {
                continue;
            }

            $first ??= ['bytes' => $bytes, 'url' => $url];
            $alone = $this->alone($url, $bytes, $run);
            $looked[] = ['url' => $url, 'product_only' => $alone];

            if ($alone === true) {
                return ['bytes' => $bytes, 'url' => $url, 'worn' => false, 'looked' => $looked];
            }
        }

        return $first === null ? null : $first + ['worn' => true, 'looked' => $looked];
    }

    /** @return list<string> the main picture first, then the gallery's, each once */
    private function candidates(TryOnJob $job): array
    {
        $urls = [(string) $job->productImage()];
        $page = $job->product?->url;
        $connection = StoreConnection::query()->first();

        if (is_string($page) && $page !== '' && $connection?->platform === 'shopify') {
            try {
                $json = Http::timeout(10)->acceptJson()->get(rtrim($page, '/').'.js')->json();
                foreach ((array) ($json['images'] ?? []) as $image) {
                    $urls[] = is_string($image) && str_starts_with($image, '//') ? 'https:'.$image : (string) $image;
                }
            } catch (Throwable) {
                // The main picture is enough to go on.
            }
        }

        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            $key = strtok($url, '?');
            if (! preg_match('#^https?://#i', $url) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $url;
        }

        return array_slice($out, 0, self::CANDIDATES);
    }

    /** Whether the picture shows the product alone and clearly; null when it could not be told. */
    private function alone(string $url, string $bytes, RunContext $run): ?bool
    {
        $key = 'try_on:product_pick:v'.self::PROMPT_VERSION.':'.hash('sha256', (string) strtok($url, '?'));
        $kept = Cache::get($key);

        if (is_bool($kept)) {
            return $kept;
        }

        $model = AgentModel::from('try_on.gate_provider', 'try_on.gate_model');
        $in = (float) Settings::get('try_on.gate_input_usd_per_million');
        $out = (float) Settings::get('try_on.gate_output_usd_per_million');

        try {
            $this->spend->assertCanSpend((1500 * $in + self::OUTPUT_TOKENS * $out) / 1_000_000, true);
            $reply = $this->vision->jsonWithImage($model->provider, $model->model, self::prompt(), '{"task": "is the product alone"}', 'image/jpeg', Picture::forModel($bytes, 768), self::OUTPUT_TOKENS, 'low');
        } catch (SpendCapReached|ModelCallFailed) {
            return null;
        }

        $run->usage($model->provider->value, $model->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
        $alone = ($reply->data['product_only'] ?? false) === true && ($reply->data['product_clear'] ?? true) !== false;
        Cache::forever($key, $alone);

        return $alone;
    }

    private static function download(string $url): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }

    public static function prompt(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Prompts/'.self::PROMPT.'.v'.self::PROMPT_VERSION.'.md');
    }
}
