<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomySubject;
use App\Modules\Taxonomy\Models\TaxonomyTag;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Models;
use App\Modules\Taxonomy\Support\Norm;
use App\Modules\Taxonomy\Support\Sample;
use App\Modules\Taxonomy\Support\Subjects;
use App\Modules\Tenancy\Models\Shop;

/**
 * A store's tag bank, built once from what it sells, for any kind of store.
 *
 * 1. Code samples the store across what it holds (Sample): each product type, each of its deepest
 *    categories in turn, and its posts across their kinds.
 * 2. One family designs the bank from the sample: the kinds of tag its products are told apart by
 *    (genre, occasion, material), each factual or inferred, scoped to the store's categories it
 *    fits in a store that sells more than one kind of thing, and what it is for (searched, a filter,
 *    recommendations); and the tags of each, with the other words shoppers use.
 * 3. Code keeps what it can: one tag per wording in a group, groups of two tags at least, a scope of
 *    the store's own categories only, at most taxonomy.max_groups and taxonomy.max_tags.
 * 4. A model of the other family reviews it: what to remove, merge, set right, and the few tags it
 *    clearly lacks. Code applies what names keys it gave.
 * 5. Saved as the bank's next version. A store with a bank keeps it unless asked to start afresh,
 *    which takes its tags off every product too.
 */
final class BuildTagBank
{
    public const AGENT = 'taxonomy.bank_builder';

    public const ACTION = 'taxonomy.build_bank';

    /** tag_bank and tag_bank_review */
    public const PROMPT_VERSION = 1;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
    ) {}

    public function handle(string $shopId, bool $fresh = false, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            input: ['fresh' => $fresh],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->build($run, $shopId, $fresh)),
        );
    }

    private function build(RunContext $run, string $shopId, bool $fresh): void
    {
        if (TaxonomyGroup::query()->exists()) {
            if (! $fresh) {
                $run->output(['skipped' => 'has_bank', 'version' => Bank::version()])->summary('taxonomy::runs.bank_kept', ['version' => (string) Bank::version()]);

                return;
            }
            TaxonomyGroup::query()->delete();
            TaxonomySubject::query()->delete();
        }

        if (! Models::crossed('bank_writer', 'bank_reviewer')) {
            $run->fail('taxonomy::runs.same_family');

            return;
        }

        $products = Subjects::products(Sample::products((int) Settings::get('taxonomy.sample_products', $shopId), (int) Settings::get('taxonomy.sample_per_group', $shopId)));
        $posts = Subjects::posts(Sample::posts((int) Settings::get('taxonomy.sample_posts', $shopId)));
        $strip = fn (array $item): array => array_diff_key($item, ['type' => true, 'id' => true, 'hash' => true]);
        $store = ['name' => (string) Shop::query()->whereKey($shopId)->value('name'), 'languages' => ['he', 'en']];

        $designed = $this->models->call($run, 'tag_bank', self::PROMPT_VERSION, 'bank_writer', [
            'store' => $store,
            'products' => array_values(array_map($strip, $products)),
            'posts' => array_values(array_map(fn (array $p): array => array_diff_key($strip($p), ['attributes' => true, 'picture' => true]), $posts)),
        ]);
        if ($designed === null) {
            return;
        }

        $bank = $this->clean((array) ($designed->data['groups'] ?? []), $shopId);
        $designedCount = [count($bank), array_sum(array_map(fn (array $g): int => count($g['tags']), $bank))];

        $review = $this->models->call($run, 'tag_bank_review', self::PROMPT_VERSION, 'bank_reviewer', [
            'store' => ['name' => $store['name']],
            'sample' => array_values(array_map(fn (array $i): array => ['title' => $i['title'], 'groups' => $i['groups']], [...array_values($products), ...array_values($posts)])),
            'bank' => array_values(array_map(fn (array $g): array => ['tags' => array_values($g['tags'])] + $g, $bank)),
        ]);
        if ($review === null) {
            return;
        }

        $changes = $this->apply($bank, (array) $review->data, $shopId);
        $version = Bank::version() + 1;

        foreach (array_values($bank) as $position => $g) {
            $group = TaxonomyGroup::query()->create([
                'shop_id' => $shopId, 'key' => $g['key'], 'label_he' => $g['label_he'], 'label_en' => $g['label_en'], 'kind' => $g['kind'],
                'multiple' => $g['multiple'], 'searchable' => $g['searchable'], 'filter' => $g['filter'], 'recommend' => $g['recommend'],
                'about' => $g['about'] ?: null, 'scope' => $g['scope'] ?: null, 'position' => $position, 'version' => $version,
            ]);
            $made = [];
            foreach ($g['tags'] as $t) {
                $made[$t['key']] = TaxonomyTag::query()->create([
                    'shop_id' => $shopId, 'group_id' => $group->id, 'key' => $t['key'], 'label_he' => $t['label_he'], 'label_en' => $t['label_en'],
                    'words' => $t['words'] ?: null, 'about' => $t['about'] ?: null, 'status' => TaxonomyTag::ACTIVE, 'version' => $version,
                ]);
            }
            // each tag's broader tag, of the same group and kept; never itself
            foreach ($g['tags'] as $t) {
                if ($t['parent'] !== '' && $t['parent'] !== $t['key'] && isset($made[$t['parent']])) {
                    $made[$t['key']]->forceFill(['parent_id' => $made[$t['parent']]->id])->save();
                }
            }
        }

        $tags = array_sum(array_map(fn (array $g): int => count($g['tags']), $bank));
        $run->output(['version' => $version, 'sample' => ['products' => count($products), 'posts' => count($posts)], 'designed' => ['groups' => $designedCount[0], 'tags' => $designedCount[1]],
            'review' => $changes, 'groups' => count($bank), 'tags' => $tags, 'filters' => array_values(array_column(array_filter($bank, fn (array $g): bool => $g['filter']), 'key'))])
            ->summary('taxonomy::runs.bank_built', ['groups' => (string) count($bank), 'tags' => (string) $tags, 'products' => (string) count($products), 'posts' => (string) count($posts)]);
    }

    /**
     * The writer's bank as code keeps it.
     *
     * @param  list<mixed>  $groups
     * @return array<string, array<string, mixed>> by group key
     */
    private function clean(array $groups, string $shopId): array
    {
        $categories = CatalogCategory::query()->whereNull('removed_at')->pluck('name')->mapWithKeys(fn ($n): array => [mb_strtolower((string) $n) => (string) $n])->all();
        $maxTags = (int) Settings::get('taxonomy.max_tags', $shopId);
        $bank = [];

        foreach ($groups as $g) {
            if (! is_array($g) || count($bank) >= (int) Settings::get('taxonomy.max_groups', $shopId)) {
                continue;
            }
            $key = Norm::key((string) ($g['key'] ?? $g['label_en'] ?? ''));
            if (isset($bank[$key]) || trim((string) ($g['label_he'] ?? '')) === '') {
                continue;
            }
            $tags = [];
            $seen = [Norm::label((string) $g['label_he']) => true];
            foreach ((array) ($g['tags'] ?? []) as $t) {
                $tag = is_array($t) ? $this->tag($t) : null;
                if ($tag === null || isset($tags[$tag['key']]) || isset($seen[Norm::label($tag['label_he'])]) || count($tags) >= $maxTags) {
                    continue;
                }
                $seen[Norm::label($tag['label_he'])] = true;
                $tags[$tag['key']] = $tag;
            }
            if (count($tags) < 2) {
                continue;
            }
            $bank[$key] = [
                'key' => $key,
                'label_he' => self::line($g['label_he'], 120),
                'label_en' => self::line($g['label_en'] ?? $g['label_he'], 120),
                'kind' => ($g['kind'] ?? '') === TaxonomyGroup::FACTUAL ? TaxonomyGroup::FACTUAL : TaxonomyGroup::INFERRED,
                'multiple' => (bool) ($g['multiple'] ?? true),
                'searchable' => (bool) ($g['searchable'] ?? true),
                'filter' => (bool) ($g['filter'] ?? false),
                'recommend' => (bool) ($g['recommend'] ?? false),
                'about' => self::line($g['about'] ?? '', 300),
                // only the store's own category names
                'scope' => array_values(array_unique(array_filter(array_map(fn ($c): ?string => $categories[mb_strtolower(trim((string) $c))] ?? null, (array) ($g['scope'] ?? []))))),
                'tags' => $tags,
            ];
        }

        return $bank;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array{key: string, label_he: string, label_en: string, words: list<string>, about: string, parent: string}|null
     */
    private function tag(array $t): ?array
    {
        $he = self::line($t['label_he'] ?? '', 120);

        if ($he === '') {
            return null;
        }

        return [
            'key' => Norm::key((string) ($t['key'] ?? '') ?: (string) ($t['label_en'] ?? $he)),
            'label_he' => $he,
            'label_en' => self::line($t['label_en'] ?? $he, 120),
            'words' => array_slice(array_values(array_unique(array_filter(array_map(fn ($w): string => self::line($w, 60), (array) ($t['words'] ?? []))))), 0, 6),
            'about' => self::line($t['about'] ?? '', 300),
            'parent' => trim((string) ($t['parent'] ?? '')) === '' ? '' : Norm::key((string) $t['parent']),
        ];
    }

    /**
     * The reviewer's changes, on the keys it was given.
     *
     * @param  array<string, array<string, mixed>>  $bank
     * @param  array<string, mixed>  $review
     * @return array{removed: int, merged: int, fixed: int, added: int}
     */
    private function apply(array &$bank, array $review, string $shopId): array
    {
        $count = ['removed' => 0, 'merged' => 0, 'fixed' => 0, 'added' => 0];
        $categories = CatalogCategory::query()->whereNull('removed_at')->pluck('name')->mapWithKeys(fn ($n): array => [mb_strtolower((string) $n) => (string) $n])->all();

        foreach ((array) ($review['merge'] ?? []) as $m) {
            $g = (string) ($m['group'] ?? '');
            [$from, $into] = [(string) ($m['tag'] ?? ''), (string) ($m['into'] ?? '')];
            if ($from !== $into && isset($bank[$g]['tags'][$from], $bank[$g]['tags'][$into])) {
                $bank[$g]['tags'][$into]['words'] = array_slice(array_values(array_unique([...$bank[$g]['tags'][$into]['words'], $bank[$g]['tags'][$from]['label_he'], $bank[$g]['tags'][$from]['label_en'], ...$bank[$g]['tags'][$from]['words']])), 0, 10);
                unset($bank[$g]['tags'][$from]);
                $count['merged']++;
            }
        }

        foreach ((array) ($review['remove'] ?? []) as $r) {
            $g = (string) ($r['group'] ?? '');
            if (isset($bank[$g]['tags'][(string) ($r['tag'] ?? '')])) {
                unset($bank[$g]['tags'][(string) $r['tag']]);
                $count['removed']++;
            }
        }

        foreach ((array) ($review['fix_groups'] ?? []) as $f) {
            $g = (string) ($f['group'] ?? '');
            if (isset($bank[$g])) {
                $bank[$g]['kind'] = ($f['kind'] ?? '') === TaxonomyGroup::FACTUAL ? TaxonomyGroup::FACTUAL : TaxonomyGroup::INFERRED;
                foreach (['searchable', 'filter', 'recommend'] as $flag) {
                    $bank[$g][$flag] = (bool) ($f[$flag] ?? $bank[$g][$flag]);
                }
                $bank[$g]['scope'] = array_values(array_unique(array_filter(array_map(fn ($c): ?string => $categories[mb_strtolower(trim((string) $c))] ?? null, (array) ($f['scope'] ?? [])))));
                $count['fixed']++;
            }
        }

        foreach (array_slice((array) ($review['add'] ?? []), 0, 10) as $a) {
            $g = (string) ($a['group'] ?? '');
            $tag = is_array($a) ? $this->tag($a) : null;
            $taken = isset($bank[$g]) ? array_map(fn (array $t): string => Norm::label($t['label_he']), $bank[$g]['tags']) : [];
            if ($tag !== null && isset($bank[$g]) && ! isset($bank[$g]['tags'][$tag['key']]) && ! in_array(Norm::label($tag['label_he']), $taken, true)
                && count($bank[$g]['tags']) < (int) Settings::get('taxonomy.max_tags', $shopId)) {
                $bank[$g]['tags'][$tag['key']] = $tag;
                $count['added']++;
            }
        }

        $bank = array_filter($bank, fn (array $g): bool => count($g['tags']) >= 2);

        return $count;
    }

    private static function line(mixed $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_string($text) ? $text : ''))), 0, $max);
    }
}
