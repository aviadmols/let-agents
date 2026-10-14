<?php

namespace App\Modules\TryOn\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Files;
use App\Modules\TryOn\Support\Picture;
use App\Modules\TryOn\Support\TryOnClasses;

/**
 * A shopper's photo, checked before anything is made of it (docs/try-on.md §3, steps 1 and 2).
 *
 * Code first: the bytes must be a picture, large enough, light enough and sharp enough; it is
 * turned upright and saved again, which drops its EXIF. Then one cheap look by a vision model, which
 * only says what it sees; code decides. Refused: two people or more, anyone who looks under 18,
 * anything unfit for a store, a photo the model cannot read, a room with people in it for furniture.
 * The same photo again is not looked at again.
 *
 * Nothing is sent to a model without the shopper's consent. Inside the shop's tenant.
 */
final class GatePhoto
{
    private const PROMPT = 'tryon_gate';

    // v2: every part of the person that is visible, not only the main one (a head-and-neck photo serves a necklace).
    // v3: a child only when a face or body clearly shows one; a hand alone shows no age (it refused hands at random).
    private const PROMPT_VERSION = 3;

    private const OUTPUT_TOKENS = 600;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{photo: TryOnPhoto|null, status: string, reject_code: string|null, usable: list<string>}
     */
    public function handle(string $visitorHash, string $bytes, bool $consent): array
    {
        $shopId = $this->tenant->require();

        if (! $consent) {
            return ['photo' => null, 'status' => TryOnPhoto::REJECTED, 'reject_code' => 'consent_required', 'usable' => []];
        }

        $hash = hash('sha256', $bytes);
        $known = TryOnPhoto::query()->where('visitor_hash', $visitorHash)->where('photo_hash', $hash)->latest()->first();

        // Looked at before: the same answer, unless it was the model or the budget that failed then, or
        // the look was by an older version of the prompt (what it reports changed).
        // A photo accepted before whose file is gone (kept on a container that no longer is) is saved again.
        $current = $known !== null && ($known->gate === null || (int) ($known->gate['prompt_version'] ?? 1) === self::PROMPT_VERSION)
            && ($known->status !== TryOnPhoto::OK || Files::exists($known->path));
        if ($known !== null && $current && ! in_array($known->reject_code, ['model_failed', 'busy'], true)) {
            return $this->outcome($known);
        }

        $expires = now()->addDays((int) Settings::get('try_on.retention_days', $shopId));
        $checked = Picture::inspect($bytes);
        $photo = TryOnPhoto::query()->create([
            'shop_id' => $shopId, 'visitor_hash' => $visitorHash, 'photo_hash' => $hash,
            'path' => $checked['ok'] ? Files::put($shopId, 'photo', (string) $checked['jpeg']) : null,
            'width' => $checked['width'], 'height' => $checked['height'],
            'status' => $checked['ok'] ? TryOnPhoto::OK : TryOnPhoto::REJECTED, 'reject_code' => $checked['reason'],
            'consent_at' => now(), 'expires_at' => $expires,
        ]);
        TryOnStep::query()->create(['shop_id' => $shopId, 'photo_id' => $photo->id, 'step' => 'code_gate', 'status' => $checked['ok'] ? 'passed' : 'refused',
            'output' => ['reason' => $checked['reason'], 'width' => $checked['width'], 'height' => $checked['height']], 'images' => $photo->path ? ['photo' => $photo->path] : null]);

        if (! $checked['ok']) {
            return $this->outcome($photo);
        }

        $model = AgentModel::from('try_on.gate_provider', 'try_on.gate_model');
        $system = self::prompt();
        $small = Picture::forModel((string) $checked['jpeg']);

        try {
            $this->spend->assertCanSpend($this->estimate(), true);
            $reply = $this->vision->jsonWithImage($model->provider, $model->model, $system, '{"task": "describe the photo"}', 'image/jpeg', $small, self::OUTPUT_TOKENS, 'low');
        } catch (SpendCapReached) {
            return $this->refuse($photo, 'busy', $model, $system);
        } catch (ModelCallFailed $e) {
            return $this->refuse($photo, 'model_failed', $model, $system, $e->reason);
        }

        $cost = $reply->costUsd($this->price('input'), $this->price('output'));
        $gate = $this->facts($reply->data) + ['prompt_version' => self::PROMPT_VERSION];
        [$reason, $usable] = $this->decide($gate);

        $photo->update(['gate' => $gate, 'usable' => $usable, 'status' => $reason === null ? TryOnPhoto::OK : TryOnPhoto::REJECTED, 'reject_code' => $reason, 'cost_usd' => round($cost, 6)]);
        TryOnStep::query()->create(['shop_id' => $shopId, 'photo_id' => $photo->id, 'step' => 'vision_gate', 'status' => $reason === null ? 'passed' : 'refused',
            'prompt' => $system, 'output' => $gate + ['decision' => $reason ?? 'ok', 'usable' => $usable], 'provider' => $model->provider->value, 'model' => $model->model,
            'tokens_in' => $reply->inputTokens, 'tokens_out' => $reply->outputTokens, 'cost_usd' => round($cost, 6), 'images' => ['photo' => $photo->path]]);

        return $this->outcome($photo->refresh());
    }

    /**
     * What code decides from what the model saw.
     *
     * @param  array<string, mixed>  $gate
     * @return array{0: string|null, 1: list<string>} the reason it is refused, and the classes it can serve
     */
    public function decide(array $gate): array
    {
        $reason = match (true) {
            $gate['unsafe'] => 'unsafe',
            $gate['quality'] !== 'ok' => 'unclear',
            // Children's photos are allowed unless the shop refuses them (try_on.refuse_minors, off by default).
            $gate['minor_present'] && (bool) Settings::get('try_on.refuse_minors', $this->tenant->require()) => 'minor',
            $gate['people_count'] >= 2 => 'several_people',
            $gate['subject'] === 'room' && $gate['people_count'] > 0 => 'people_in_room',
            default => null,
        };

        if ($reason !== null) {
            return [$reason, []];
        }

        // What the photo can serve, by its main subject or any part it shows; nothing (a foot, a mug): no subject.
        $usable = array_values(array_filter(array_keys(TryOnClasses::SUBJECTS), fn (string $class): bool => TryOnClasses::fits($class, $gate)));

        return [$usable === [] ? 'no_subject' : null, $usable];
    }

    /**
     * The model's answer, read strictly: anything missing or odd counts against the photo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function facts(array $data): array
    {
        $subjects = ['hand', 'wrist', 'neck', 'ear', 'face', 'body_upper', 'body_full', 'foot', 'room', 'other'];

        return [
            'subject' => in_array($data['subject'] ?? null, $subjects, true) ? $data['subject'] : 'other',
            'parts_visible' => array_values(array_intersect(TryOnClasses::PARTS, array_map('strval', (array) ($data['parts_visible'] ?? [])))),
            'people_count' => max(0, (int) ($data['people_count'] ?? 0)),
            'minor_present' => ($data['minor_present'] ?? false) === true,
            'quality' => in_array($data['quality'] ?? null, ['ok', 'blurry', 'dark', 'cropped', 'occluded'], true) ? $data['quality'] : 'blurry',
            'unsafe' => ($data['unsafe'] ?? false) === true,
            'hands_visible' => max(0, (int) ($data['hands_visible'] ?? 0)),
            'fingers_visible' => array_values(array_intersect(TryOnClasses::FINGERS, array_map('strval', (array) ($data['fingers_visible'] ?? [])))),
            'ears_visible' => max(0, (int) ($data['ears_visible'] ?? 0)),
            'free_spots' => array_values(array_filter(array_map(fn ($s): array => ['label' => mb_substr(trim((string) (is_array($s) ? ($s['label'] ?? '') : $s)), 0, 60)], array_slice((array) ($data['free_spots'] ?? []), 0, 4)), fn (array $s): bool => $s['label'] !== '')),
            'scale' => mb_substr(trim((string) ($data['scale'] ?? '')), 0, 120),
        ];
    }

    /** @return array{photo: TryOnPhoto, status: string, reject_code: string|null, usable: list<string>} */
    private function refuse(TryOnPhoto $photo, string $reason, AgentModel $model, string $system, ?string $error = null): array
    {
        $photo->update(['status' => TryOnPhoto::REJECTED, 'reject_code' => $reason]);
        TryOnStep::query()->create(['shop_id' => $photo->shop_id, 'photo_id' => $photo->id, 'step' => 'vision_gate', 'status' => 'failed', 'prompt' => $system,
            'provider' => $model->provider->value, 'model' => $model->model, 'error' => $error ?? $reason, 'images' => ['photo' => $photo->path]]);

        return $this->outcome($photo->refresh());
    }

    /** @return array{photo: TryOnPhoto, status: string, reject_code: string|null, usable: list<string>} */
    private function outcome(TryOnPhoto $photo): array
    {
        return ['photo' => $photo, 'status' => $photo->status, 'reject_code' => $photo->reject_code, 'usable' => (array) ($photo->usable ?? [])];
    }

    private function estimate(): float
    {
        return (1500 * $this->price('input') + self::OUTPUT_TOKENS * $this->price('output')) / 1_000_000;
    }

    private function price(string $side): float
    {
        return (float) Settings::get("try_on.gate_{$side}_usd_per_million");
    }

    public static function prompt(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Prompts/'.self::PROMPT.'.v'.self::PROMPT_VERSION.'.md');
    }
}
