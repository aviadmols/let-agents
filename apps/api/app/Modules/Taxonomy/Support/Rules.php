<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Taxonomy\Actions\ApplyRule;
use App\Modules\Taxonomy\Jobs\ReadRuleJob;
use App\Modules\Taxonomy\Models\TaxonomyRule;

/** The shop's rules of its products, as the knowledge screen works them (Enrichment\Contracts\ShopRules). Inside the tenant. */
final class Rules implements ShopRules
{
    /** Rules shown at most. */
    private const MAX = 200;

    public function list(): array
    {
        return TaxonomyRule::query()->orderByDesc('id')->limit(self::MAX)->get()->map(fn (TaxonomyRule $rule): array => [
            'id' => (string) $rule->id,
            'text' => (string) $rule->text,
            'status' => (string) $rule->status,
            'note' => $rule->note,
            'link' => ($rule->plan['link'] ?? '') !== '' ? (string) $rule->plan['link'] : null,
            'at' => $rule->created_at->toIso8601String(),
            'changes' => array_values(array_map(fn (array $c): array => [
                'external_id' => (string) ($c['external_id'] ?? ''),
                'title' => (string) ($c['title'] ?? ''),
                'image' => $c['image'] ?? null,
                'url' => $c['url'] ?? null,
                'add' => array_values(array_map('strval', (array) ($c['add'] ?? []))),
                'remove' => array_values(array_map('strval', (array) ($c['remove'] ?? []))),
                'apart' => array_values(array_map('strval', (array) ($c['apart'] ?? []))),
                'alike' => array_values(array_map('strval', (array) ($c['alike'] ?? []))),
            ], (array) ($rule->plan['changes'] ?? []))),
        ])->all();
    }

    public function add(string $text, ?int $userId): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > TaxonomyRule::MAX_TEXT) {
            return null;
        }

        $shopId = app(TenantContext::class)->require();
        $rule = TaxonomyRule::query()->create(['shop_id' => $shopId, 'text' => $text, 'status' => TaxonomyRule::READING, 'created_by' => $userId]);
        ReadRuleJob::dispatch($shopId, (string) $rule->id);

        return (string) $rule->id;
    }

    public function decide(string $id, bool $approve, ?int $userId): void
    {
        $rule = TaxonomyRule::query()->find($id);

        if ($rule === null || $rule->status !== TaxonomyRule::PROPOSED) {
            return;
        }

        if ($approve) {
            app(ApplyRule::class)->handle($rule, $userId);

            return;
        }

        $rule->forceFill(['status' => TaxonomyRule::REJECTED, 'decided_by' => $userId, 'decided_at' => now()])->save();
    }

    public function retry(string $id): void
    {
        $rule = TaxonomyRule::query()->find($id);

        if ($rule === null || ! in_array($rule->status, [TaxonomyRule::FAILED, TaxonomyRule::UNCLEAR], true)) {
            return;
        }

        $rule->forceFill(['status' => TaxonomyRule::READING, 'note' => null, 'plan' => null, 'fingerprint' => null])->save();
        ReadRuleJob::dispatch((string) $rule->shop_id, (string) $rule->id);
    }

    public function remove(string $id): void
    {
        TaxonomyRule::query()->whereKey($id)->delete();
    }
}
