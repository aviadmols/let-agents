<?php

namespace App\Modules\Taxonomy\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Support\Bank;
use App\Modules\Taxonomy\Support\Models;

/**
 * Which groups of a bank a picture may be the evidence for, decided for a bank that was built before
 * its groups said so (a bank of tag_bank v1: every group "no", and the tags its pictures gave kept
 * all the same). The bank's reviewer reads the groups and says, of each, whether what it says is
 * seen (a colour, a shape, a style) or only the product's own information says it (a stone, a
 * metal, a karat, an occasion). Then ApplyTagRules takes out what no longer holds by today's rules.
 *
 * Never asked twice: the same groups, the same tags, the same prompt are skipped.
 */
final class ReviewPictureGroups
{
    public const AGENT = 'taxonomy.bank_builder';

    public const ACTION = 'taxonomy.picture_groups';

    public const PROMPT_VERSION = 1;

    /** Tags of each group shown to the reviewer: enough to tell what it holds. */
    private const SAMPLE = 12;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly Models $models,
        private readonly ApplyTagRules $rules,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Manual): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->review($run, $shopId)),
        );
    }

    private function review(RunContext $run, string $shopId): void
    {
        $groups = Bank::groups();
        if ($groups->isEmpty()) {
            $run->output(['skipped' => 'no_bank'])->summary('taxonomy::runs.no_bank');

            return;
        }

        $input = $groups->map(fn (TaxonomyGroup $g): array => [
            'group' => $g->key, 'name' => $g->label_he.' / '.$g->label_en, 'kind' => $g->kind, 'about' => (string) $g->about,
            'tags' => $g->tags->sortBy('label_he')->take(self::SAMPLE)->pluck('label_he')->values()->all(),
        ])->sortBy('group')->values()->all();
        // the groups and their tags sorted, so the same bank is the same fingerprint on every database
        $fingerprint = hash('sha256', self::PROMPT_VERSION.'|'.json_encode($input, JSON_UNESCAPED_UNICODE));
        $last = Run::query()->where('action', self::ACTION)->where('shop_id', $shopId)->where('status', RunStatus::Succeeded)->latest('id')->first();

        if (($last?->output['fingerprint'] ?? null) === $fingerprint) {
            $applied = $this->rules->apply();
            $run->output(['skipped' => 'unchanged', 'fingerprint' => $fingerprint, 'removed' => $applied])
                ->summary('taxonomy::runs.picture_groups', ['allowed' => (string) $groups->where('from_picture', true)->count(), 'removed' => (string) $applied]);

            return;
        }

        $reply = $this->models->call($run, 'bank_picture', self::PROMPT_VERSION, 'bank_reviewer', ['groups' => $input]);
        if ($reply === null) {
            return;
        }

        $byKey = $groups->keyBy('key');
        $changed = [];
        foreach ((array) ($reply->data['groups'] ?? []) as $g) {
            $group = $byKey->get((string) ($g['group'] ?? ''));
            if ($group === null || ! is_bool($g['from_picture'] ?? null)) {
                continue;
            }
            if ($group->from_picture !== $g['from_picture']) {
                $changed[$group->key] = $g['from_picture'];
            }
            $group->forceFill(['from_picture' => $g['from_picture']])->save();
        }

        $removed = $this->rules->apply();
        $allowed = TaxonomyGroup::query()->where('from_picture', true)->orderBy('key')->pluck('key')->all();
        $run->output(['fingerprint' => $fingerprint, 'allowed' => $allowed, 'changed' => $changed, 'removed' => $removed])
            ->summary('taxonomy::runs.picture_groups', ['allowed' => (string) count($allowed), 'removed' => (string) $removed]);
    }
}
