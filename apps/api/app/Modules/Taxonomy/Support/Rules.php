<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Search\Contracts\ProductsForQuery;
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
            'mentions' => array_values(array_map(fn (array $m): string => (string) ($m['label'] ?? ''), (array) $rule->mentions)),
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

    public function add(string $text, ?int $userId, array $mentions = []): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > TaxonomyRule::MAX_TEXT) {
            return null;
        }

        // what it names with "@", only while the name is still in the text, each once
        $kept = [];
        foreach ($mentions as $m) {
            $type = (string) ($m['type'] ?? '');
            $id = (string) ($m['id'] ?? '');
            $label = trim((string) ($m['label'] ?? ''));
            if (in_array($type, TaxonomyRule::MENTIONS, true) && $id !== '' && $label !== '' && str_contains($text, '@'.$label)) {
                $kept[$type.':'.$id] = ['type' => $type, 'id' => $id, 'label' => mb_substr($label, 0, 200)];
            }
        }

        $shopId = app(TenantContext::class)->require();
        $rule = TaxonomyRule::query()->create(['shop_id' => $shopId, 'text' => $text, 'mentions' => $kept === [] ? null : array_values($kept), 'status' => TaxonomyRule::READING, 'created_by' => $userId]);
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

    public function mentionables(string $query, int $limit = 8): array
    {
        $query = trim(mb_substr($query, 0, 60));
        if (mb_strlen($query) < 1) {
            return [];
        }
        $shopId = app(TenantContext::class)->require();
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $out = [];

        // the products as the store's own search finds them (its spelling, its typos), then by their names
        $ids = app()->bound(ProductsForQuery::class) ? array_map('strval', app(ProductsForQuery::class)->find($shopId, $query, $limit)) : [];
        $found = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $ids)->get(['external_id', 'title', 'image_url'])->keyBy('external_id');
        $products = array_values(array_filter(array_map(fn (string $id): ?CatalogProduct => $found[$id] ?? null, $ids)));
        if (count($products) < $limit) {
            $products = [...$products, ...CatalogProduct::query()->whereNull('removed_at')->where('title', 'like', $like)->whereNotIn('external_id', $ids)
                ->orderBy('title')->limit($limit - count($products))->get(['external_id', 'title', 'image_url'])->all()];
        }
        foreach (array_slice($products, 0, max(1, $limit - 3)) as $p) {
            $out[] = ['type' => 'product', 'id' => (string) $p->external_id, 'label' => (string) $p->title, 'sub' => null, 'image' => $p->image_url];
        }
        foreach (CatalogCategory::query()->whereNull('removed_at')->where('product_count', '>', 0)->where('name', 'like', $like)->orderByDesc('product_count')->limit(2)->get(['external_id', 'name', 'product_count']) as $c) {
            $out[] = ['type' => 'category', 'id' => (string) $c->external_id, 'label' => (string) $c->name, 'sub' => (string) $c->product_count, 'image' => null];
        }
        foreach (CatalogContent::query()->whereNull('removed_at')->whereIn('type', [CatalogContent::POST, CatalogContent::PAGE])->where('title', 'like', $like)->orderBy('title')->limit(2)->get(['type', 'external_id', 'title', 'image_url']) as $c) {
            $out[] = ['type' => (string) $c->type, 'id' => (string) $c->external_id, 'label' => (string) $c->title, 'sub' => null, 'image' => $c->image_url];
        }

        return array_slice($out, 0, $limit);
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
