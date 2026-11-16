<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\StructuredChatModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;

/**
 * The store's tone of voice, read once in its scan from how it writes to its customers (its product descriptions, a few
 * posts and pages), into the tone its assistant answers in (assistant.answer_tone and answer_tone_note), so every store
 * is answered in its own voice (as Aviad asked, 16.11). Code gathers a few short pieces of the store's own words; a small
 * model says the style and a sentence on the voice, against a schema; code keeps only a style of the four and a note with
 * no link. A tone the owner chose is never changed: only one still as it was set by default, or as this step set it. The
 * same pieces are never asked about twice (assistant.voice_read keeps their hash). Paid, capped and counted like any call.
 */
final class ReadShopVoice
{
    public const AGENT = 'assistant.voice_reader';

    public const ACTION = 'assistant.read_voice';

    // v2: the manner only, never whom it addresses or how (a note asked for the singular against the writer's plural)
    public const PROMPT = 'shop_voice.v2';

    public const STYLES = ['warm', 'friendly', 'professional', 'brief'];

    /** Pieces read, at most, and how long each: enough to hear the voice, little to pay. */
    private const PRODUCTS = 8;

    private const CONTENT = 3;

    private const PIECE = 360;

    /** A description shorter than this says too little of a voice. */
    private const MIN_PIECE = 60;

    private const NOTE = 280;

    public function __construct(
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * Call inside the shop's tenant.
     *
     * @return array{status: 'set'|'kept'|'same'|'few'|'off'|'failed', style?: string, note?: string}
     */
    public function handle(string $shopId): array
    {
        if (! Features::enabled('assistant.read_voice', $shopId)) {
            return ['status' => 'off'];
        }
        $read = json_decode((string) Settings::get('assistant.voice_read', $shopId), true);
        $read = is_array($read) ? $read : [];
        $style = (string) (Settings::get('assistant.answer_tone', $shopId) ?: AnswerSiteQuestion::TONE_DEFAULT);
        $note = trim((string) Settings::get('assistant.answer_tone_note', $shopId));
        if (self::ownersChoice($style, $note, $read)) {
            return ['status' => 'kept', 'style' => $style, 'note' => $note];
        }

        $samples = self::samples();
        if (count($samples) < 3) {
            return ['status' => 'few'];
        }
        // the prompt is part of what was asked: a new one reads the same pieces again
        $hash = substr(hash('sha256', self::PROMPT."\n".implode("\n", $samples)), 0, 20);
        if (($read['hash'] ?? null) === $hash) {
            return ['status' => 'same', 'style' => $style, 'note' => $note];
        }

        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.voice_provider'))));
        $model = trim((string) Settings::get('assistant.voice_model'));
        $system = (string) @file_get_contents(__DIR__.'/../Prompts/'.self::PROMPT.'.md');
        $schema = json_decode((string) @file_get_contents(__DIR__.'/../Prompts/'.self::PROMPT.'.schema.json'), true);
        if ($provider === null || $model === '' || $system === '') {
            return ['status' => 'failed'];
        }
        $locale = (string) (Shop::query()->whereKey($shopId)->value('content_locale') ?: 'he');
        $user = (string) json_encode(['store' => (string) Shop::query()->whereKey($shopId)->value('name'), 'language' => $locale, 'samples' => $samples], JSON_UNESCAPED_UNICODE);
        $in = (float) Settings::get('assistant.voice_input_usd_per_million');
        $out = (float) Settings::get('assistant.voice_output_usd_per_million');
        $max = (int) Settings::get('assistant.voice_max_output_tokens');

        try {
            $this->spend->assertCanSpend(estimatedUsd: ((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
            $reply = is_array($schema) && $this->chat instanceof StructuredChatModel
                ? $this->chat->structured($provider, $model, $system, $user, $schema, $max)
                : $this->chat->json($provider, $model, $system, $user, $max);
        } catch (SpendCapReached|ModelCallFailed) {
            return ['status' => 'failed'];
        }
        Run::recordUsage($shopId, self::AGENT, self::ACTION, $provider->value, $model, $reply->inputTokens, $reply->outputTokens, $reply->costUsd($in, $out));

        // code's gate: a style of the four, a note of the voice alone (one paragraph, no link, not too long)
        $said = (string) ($reply->data['style'] ?? '');
        $newStyle = in_array($said, self::STYLES, true) ? $said : AnswerSiteQuestion::TONE_DEFAULT;
        $newNote = self::cleanNote((string) ($reply->data['note'] ?? ''));

        Settings::set('assistant.answer_tone', $newStyle, $shopId);
        Settings::set('assistant.answer_tone_note', $newNote, $shopId);
        Settings::set('assistant.voice_read', (string) json_encode(['hash' => $hash, 'style' => $newStyle, 'note' => $newNote, 'model' => $model.'@'.self::PROMPT], JSON_UNESCAPED_UNICODE), $shopId);

        return ['status' => 'set', 'style' => $newStyle, 'note' => $newNote];
    }

    /**
     * Whether the tone is the owner's: neither as it is by default (warm, no words) nor as this step last set it.
     *
     * @param  array<string, mixed>  $read  what this step last set (assistant.voice_read)
     */
    public static function ownersChoice(string $style, string $note, array $read): bool
    {
        if ($style === AnswerSiteQuestion::TONE_DEFAULT && $note === '') {
            return false;
        }

        return ! (isset($read['style']) && $read['style'] === $style && (string) ($read['note'] ?? '') === $note);
    }

    /** A note on the voice: one paragraph, no link, at most NOTE characters, cut at a sentence's end where it can be. */
    public static function cleanNote(string $note): string
    {
        $note = trim((string) preg_replace(['~https?://\S+|www\.\S+~iu', '/\s+/u'], ['', ' '], $note));
        if (mb_strlen($note) <= self::NOTE) {
            return $note;
        }
        $cut = mb_substr($note, 0, self::NOTE);
        $end = max((int) mb_strrpos($cut, '.'), (int) mb_strrpos($cut, '!'));

        return trim($end > self::NOTE / 2 ? mb_substr($cut, 0, $end + 1) : $cut);
    }

    /**
     * A few short pieces of the store's own words to its customers: product descriptions of different products (the
     * voice it sells in), then a few of its posts.
     * Sorted, so the same store gives the same pieces whatever order the database returns.
     *
     * @return list<string>
     */
    public static function samples(): array
    {
        $plain = fn (?string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5)));
        $pieces = [];
        foreach (CatalogProduct::query()->active()->orderBy('external_id')->limit(400)->get(['id', 'external_id', 'payload']) as $product) {
            $payload = (array) $product->payload;
            $text = $plain((string) ($payload['description'] ?? '')) ?: $plain((string) ($payload['short_description'] ?? ''));
            if (mb_strlen($text) >= self::MIN_PIECE) {
                $pieces[$text] = true;
            }
        }
        $products = array_keys($pieces);
        sort($products);
        // spread over the catalogue, never the first few of one line
        $step = max(1, intdiv(count($products), self::PRODUCTS));
        $chosen = [];
        for ($i = 0; $i < count($products) && count($chosen) < self::PRODUCTS; $i += $step) {
            $chosen[] = mb_substr($products[$i], 0, self::PIECE);
        }

        // its posts, written to its readers; its pages are most often terms and policies, the same dry words in every store
        $content = [];
        foreach (CatalogContent::query()->whereNull('removed_at')->where('type', 'post')->orderBy('external_id')->limit(60)->get(['title', 'excerpt', 'body']) as $item) {
            $text = $plain($item->excerpt) ?: $plain($item->body);
            if (mb_strlen($text) >= self::MIN_PIECE) {
                $content[] = mb_substr($text, 0, self::PIECE);
            }
        }
        sort($content);

        return [...$chosen, ...array_slice(array_values(array_unique($content)), 0, self::CONTENT)];
    }
}
