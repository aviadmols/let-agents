<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Enums\ColorShade;
use App\Modules\Catalog\Enums\FabricPattern;
use App\Modules\Catalog\Events\ColourNamesPlaced;
use App\Modules\Catalog\Models\CatalogColourName;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;

/**
 * A shop's own colour names that code's words do not know and no swatch of the store's tells
 * ("Ocean Mist", "חול מדברי"), placed among the shades so its products are found by colour. Code
 * does what it can first (VariantRows: the words, then the swatch); only what is left comes here.
 *
 * Once a night, every name not yet placed in one request (up to enrichment.colour_names_per_night):
 * the reader (one family) places each among the shades and patterns, code keeps only keys on the
 * list, and the reviewer (the other family, checked in code before any call) says whether each is
 * right. Placed when both agree; a name the reader could not place is "unknown", one the reviewer
 * did not accept "disputed". Every name is kept with its outcome, so none is asked again. Each call
 * asks SpendGuard first and is recorded on the run. The products named so are then read again
 * (ColourNamesPlaced), and the search follows.
 */
final class PlaceColourNames
{
    public const AGENT = 'enrichment.colour_namer';

    public const ACTION = 'enrichment.place_colour_names';

    public const PROMPT_VERSION = 1;

    /** Roughly what a name costs in the request and in the answer, before asking whether there is room. */
    private const TOKENS_PER_NAME = 40;

    private const PROMPT_TOKENS = 1600;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Schedule): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->place($run, $shopId)),
        );
    }

    private function place(RunContext $run, string $shopId): void
    {
        $names = Features::enabled('enrichment.colour_names', $shopId) ? $this->unplaced((int) Settings::get('enrichment.colour_names_per_night', $shopId)) : [];

        if ($names === []) {
            $run->output(['asked' => 0])->summary('enrichment::runs.colour_names_none');

            return;
        }

        $reader = AgentModel::from('enrichment.reader_provider', 'enrichment.reader_model');
        $reviewer = AgentModel::from('enrichment.reviewer_provider', 'enrichment.reviewer_model');

        // What one family writes, the other checks: the same family checking itself proves nothing.
        if ($reader->provider->family() === $reviewer->provider->family()) {
            $run->fail('enrichment::runs.colour_names_same_family');

            return;
        }

        $maxOutput = (int) Settings::get('enrichment.colour_names_max_output_tokens', $shopId);
        $estimate = fn (string $role): float => ((self::PROMPT_TOKENS + count($names) * self::TOKENS_PER_NAME) * $this->price($role, 'input')
            + min($maxOutput, count($names) * self::TOKENS_PER_NAME) * $this->price($role, 'output')) / 1_000_000;

        try {
            $this->spend->assertCanSpend($estimate('reader'));
            $read = $this->models->json($reader->provider, $reader->model, self::prompt('colour_names'), (string) json_encode([
                'names' => $names, 'shades' => self::shades(), 'patterns' => self::patterns(),
            ], JSON_UNESCAPED_UNICODE), $maxOutput);
            $run->usage($reader->provider->value, $reader->model, $read->inputTokens, $read->outputTokens, 0, $read->costUsd($this->price('reader', 'input'), $this->price('reader', 'output')));

            $placed = $this->placements($names, (array) ($read->data['names'] ?? []));
            $toCheck = array_filter($placed, fn (array $p): bool => $p['shades'] !== [] || $p['pattern'] !== null);
            $checks = [];

            if ($toCheck !== []) {
                $this->spend->assertCanSpend($estimate('reviewer'));
                $check = $this->models->json($reviewer->provider, $reviewer->model, self::prompt('colour_names_check'), (string) json_encode([
                    'placed' => array_values(array_map(fn (array $p): array => ['name' => $p['name'], 'shades' => array_map(fn (string $s): array => self::shade(ColorShade::from($s)), $p['shades']), 'pattern' => $p['pattern']], $toCheck)),
                ], JSON_UNESCAPED_UNICODE), $maxOutput);
                $run->usage($reviewer->provider->value, $reviewer->model, $check->inputTokens, $check->outputTokens, 0, $check->costUsd($this->price('reviewer', 'input'), $this->price('reviewer', 'output')));

                foreach ((array) ($check->data['checks'] ?? []) as $entry) {
                    if (is_array($entry) && isset($entry['name'])) {
                        $checks[(string) $entry['name']] = ($entry['ok'] ?? false) === true;
                    }
                }
            }
        } catch (SpendCapReached) {
            // Nothing is kept: the same names are asked when there is room again.
            $run->output(['asked' => 0, 'stopped' => 'spend_cap'])->summary('enrichment::runs.colour_names_stopped');

            return;
        } catch (ModelCallFailed $e) {
            $run->fail('enrichment::runs.colour_names_failed', [], $e->getMessage());

            return;
        }

        $counts = [CatalogColourName::PLACED => 0, CatalogColourName::UNKNOWN => 0, CatalogColourName::DISPUTED => 0];
        $learned = [];

        foreach ($placed as $p) {
            $status = match (true) {
                $p['shades'] === [] && $p['pattern'] === null => CatalogColourName::UNKNOWN,
                $checks[$p['name']] ?? false => CatalogColourName::PLACED,
                default => CatalogColourName::DISPUTED,
            };
            $counts[$status]++;

            CatalogColourName::query()->updateOrCreate(['shop_id' => $shopId, 'name_key' => CatalogColourName::keyOf($p['name'])], [
                'name' => mb_substr($p['name'], 0, 120),
                'shades' => $p['shades'] === [] ? null : $p['shades'],
                'pattern' => $p['pattern'],
                'status' => $status,
                'source' => 'model',
                'models' => mb_substr($reader->model.' · '.$reviewer->model, 0, 160),
                'run_id' => $run->runId,
            ]);

            if ($status === CatalogColourName::PLACED) {
                $learned[] = $p['name'];
            }
        }

        $run->output(['asked' => count($names), 'reader' => $reader->model, 'reviewer' => $reviewer->model] + $counts + ['placed_names' => array_slice($learned, 0, 30)])
            ->summary('enrichment::runs.colour_names', ['asked' => (string) count($names), 'placed' => (string) $counts[CatalogColourName::PLACED], 'unknown' => (string) $counts[CatalogColourName::UNKNOWN], 'disputed' => (string) $counts[CatalogColourName::DISPUTED]]);

        if ($learned !== []) {
            event(new ColourNamesPlaced($shopId, $learned));
        }
    }

    /**
     * The shop's colour names nothing placed yet: no shade from the words or a swatch, no pattern, and
     * not asked before. In a fixed order, so the same names are the same request.
     *
     * @return list<string>
     */
    private function unplaced(int $limit): array
    {
        $asked = array_flip(CatalogColourName::query()->pluck('name_key')->all());
        $names = [];

        foreach (CatalogVariant::query()->whereNotNull('color_label')->whereNull('color_shades')->whereNull('pattern')
            ->whereHas('product', fn ($q) => $q->whereNull('removed_at'))->distinct()->orderBy('color_label')->pluck('color_label') as $name) {
            $key = CatalogColourName::keyOf((string) $name);
            if ($key !== '' && ! isset($asked[$key]) && ! isset($names[$key])) {
                $names[$key] = (string) $name;
            }
        }

        ksort($names, SORT_STRING);

        return array_slice(array_values($names), 0, max(1, $limit));
    }

    /**
     * The reader's places, kept only as code allows: a name it was asked, shade and pattern keys on the
     * list, two shades at most. A name it skipped is unknown.
     *
     * @param  list<string>  $names
     * @param  array<int, mixed>  $reply
     * @return list<array{name: string, shades: list<string>, pattern: string|null}>
     */
    private function placements(array $names, array $reply): array
    {
        $byName = [];
        foreach ($reply as $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $byName[(string) $entry['name']] = $entry;
            }
        }

        return array_map(function (string $name) use ($byName): array {
            $entry = $byName[$name] ?? [];
            $shades = array_values(array_unique(array_filter(array_map(fn ($s): ?string => ColorShade::tryFrom((string) $s)?->value, (array) ($entry['shades'] ?? [])))));

            return ['name' => $name, 'shades' => array_slice($shades, 0, 2), 'pattern' => FabricPattern::tryFrom((string) ($entry['pattern'] ?? ''))?->value];
        }, $names);
    }

    /** @return list<array<string, mixed>> */
    private static function shades(): array
    {
        return array_map(fn (ColorShade $s): array => self::shade($s), ColorShade::cases());
    }

    /** @return array<string, mixed> */
    private static function shade(ColorShade $shade): array
    {
        return [
            'key' => $shade->value,
            'he' => (string) __('catalog::colors.shades.'.$shade->value, [], 'he'),
            'en' => (string) __('catalog::colors.shades.'.$shade->value, [], 'en'),
            'families' => array_map(fn ($f): string => $f->value, $shade->families()),
        ];
    }

    /** @return list<array<string, string>> */
    private static function patterns(): array
    {
        return array_map(fn (FabricPattern $p): array => ['key' => $p->value, 'he' => (string) __('catalog::colors.patterns.'.$p->value, [], 'he'), 'en' => (string) __('catalog::colors.patterns.'.$p->value, [], 'en')], FabricPattern::cases());
    }

    private function price(string $role, string $direction): float
    {
        return (float) Settings::get("enrichment.{$role}_{$direction}_usd_per_million");
    }

    public static function prompt(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.md');
    }
}
