<?php

namespace App\Modules\Recovery\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Recovery\Models\RecoveryCart;
use App\Modules\Recovery\Support\CartEvidence;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;

/**
 * Every hour, for one shop: the carts left with an email and not paid within the shop's wait get
 * a short report for the shop: what the shopper was interested in, what they searched, how they
 * reached the cart, what may have held them, and what could bring them back. Nothing is sent to
 * the shopper; this stage only explains.
 *
 *   1. paid      a cart whose order (or whose browser) ordered since is marked converted, no model
 *   2. evidence  code condenses the visit into coded products and numbered facts
 *   3. thin      a cart with no visit or search to read gets a code-written line, no model
 *   4. writer    one model puts the facts into a few words, as JSON, citing codes it was given
 *   5. checker   a model of the other family checks every sentence against the facts
 *
 * Minimum tokens: the input is the facts only, the answer is capped in words, and a cart is
 * reported once; it is asked again only when the shopper changes the cart.
 */
final class ReportAbandonedCarts
{
    public const AGENT = 'recovery.reporter';

    public const ACTION = 'recovery.report';

    public const PROMPT_VERSION = 1;

    private const PROMPTS = __DIR__.'/../Prompts/';

    private const MAX_SUGGESTIONS = 3;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, RunTrigger $trigger = RunTrigger::Schedule): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $trigger,
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->report($run, $shopId)),
        );
    }

    private function report(RunContext $run, string $shopId): void
    {
        $stats = ['converted' => 0, 'reported' => 0, 'thin' => 0, 'refused' => 0, 'stopped' => null];
        $open = RecoveryCart::query()->where('status', RecoveryCart::OPEN)->orderBy('captured_at')->get();

        foreach ($open as $cart) {
            if ($this->paid($cart)) {
                $cart->forceFill(['status' => RecoveryCart::CONVERTED, 'converted_at' => now()])->save();
                $stats['converted']++;
            }
        }

        $due = $open->where('status', RecoveryCart::OPEN)
            ->filter(fn (RecoveryCart $c): bool => $c->captured_at->lte(now()->subMinutes((int) Settings::get('recovery.wait_minutes', $shopId))))
            ->take((int) Settings::get('recovery.reports_per_run'));

        if ($due->isEmpty() || ! Features::enabled('recovery.report', $shopId)) {
            $run->output($stats)->summary('recovery::runs.reported', $this->params($stats));

            return;
        }

        $writer = $this->role('writer');
        $checker = $this->role('checker');

        if ($writer['provider'] === null || $checker['provider'] === null) {
            $run->fail('recovery::runs.no_provider');

            return;
        }

        // What one family writes the other checks; the same family on both sides fails the run.
        if ($writer['provider']->family() === $checker['provider']->family()) {
            $run->fail('recovery::runs.same_family', ['family' => $writer['provider']->label()]);

            return;
        }

        foreach ($due as $cart) {
            $evidence = CartEvidence::for($cart);

            if ($evidence['thin']) {
                $this->save($cart, $this->thinReport($evidence), null);
                $stats['thin']++;

                continue;
            }

            try {
                $report = $this->write($run, $writer, $evidence);

                if ($report === null) {
                    $stats['refused']++;

                    continue;
                }

                $this->save($cart, $report, $this->check($run, $checker, $evidence, $report));
                $stats['reported']++;
            } catch (SpendCapReached) {
                $stats['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $stats['stopped'] = $e->reason;
                break;
            }
        }

        $run->output($stats)->summary('recovery::runs.reported', $this->params($stats));
    }

    /** Paid since: the same order, or any order from the same browser after the email was left. */
    private function paid(RecoveryCart $cart): bool
    {
        return AnalyticsOrder::query()
            ->where(fn ($q) => $q->where('order_ref', $cart->order_ref)
                ->when($cart->visitor_hash !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('visitor_hash', $cart->visitor_hash)->where('ordered_at', '>=', $cart->captured_at))))
            ->exists();
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>|null null when the answer cited what it was not given
     */
    private function write(RunContext $run, array $role, array $evidence): ?array
    {
        $reply = $this->ask($run, 'writer', $role, $this->prompt('report'), [
            'products' => $evidence['products'],
            'facts' => $evidence['facts'],
            'searches' => $evidence['searches'],
        ]);

        $data = $reply->data;
        $codes = array_keys($evidence['products']);
        $facts = array_values(array_filter((array) ($data['facts'] ?? []), fn ($f): bool => is_string($f) && isset($evidence['facts'][$f])));

        // Code checks what it can: every product and fact cited is one it was given.
        $suggestions = [];
        foreach (array_slice((array) ($data['suggestions'] ?? []), 0, self::MAX_SUGGESTIONS) as $s) {
            $refs = array_values(array_filter((array) ($s['refs'] ?? []), 'is_string'));

            if (! is_array($s) || trim((string) ($s['what'] ?? '')) === '' || array_diff($refs, $codes) !== []) {
                continue;
            }

            $suggestions[] = ['what' => $this->words((string) $s['what'], 30), 'refs' => $refs];
        }

        $cited = array_merge(...array_map(fn (array $s): array => $s['refs'], $suggestions ?: [['refs' => []]]));

        if ($facts === [] || trim((string) ($data['interest'] ?? '')) === '') {
            return null;
        }

        return [
            'interest' => $this->words((string) $data['interest'], 30),
            'searched' => $this->words((string) ($data['searched'] ?? ''), 25),
            'path' => $this->words((string) ($data['path'] ?? ''), 35),
            'hesitation' => $this->words((string) ($data['hesitation'] ?? ''), 25),
            'suggestions' => $suggestions,
            'facts' => $facts,
            'products' => array_intersect_key($evidence['products'], array_flip(array_unique(array_merge($cited, array_keys(array_filter($evidence['products'], fn (array $p): bool => $p['cart'])))))),
            'external_ids' => $evidence['codes'],
        ];
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $report
     */
    private function check(RunContext $run, array $role, array $evidence, array $report): bool
    {
        $reply = $this->ask($run, 'checker', $role, $this->prompt('check'), [
            'facts' => $evidence['facts'],
            'searches' => $evidence['searches'],
            'products' => array_map(fn (array $p): string => $p['name'], $evidence['products']),
            'report' => array_intersect_key($report, array_flip(['interest', 'searched', 'path', 'hesitation', 'suggestions'])),
        ]);

        return ($reply->data['supported'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function thinReport(array $evidence): array
    {
        return [
            'thin' => true,
            'products' => array_filter($evidence['products'], fn (array $p): bool => $p['cart']),
            'external_ids' => $evidence['codes'],
            'facts' => array_keys($evidence['facts']),
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function save(RecoveryCart $cart, array $report, ?bool $checked): void
    {
        $cart->forceFill([
            'status' => RecoveryCart::REPORTED,
            'report' => $report,
            'report_version' => self::PROMPT_VERSION,
            'report_checked' => $checked,
            'reported_at' => now(),
        ])->save();
    }

    /**
     * @param  array{provider: AiProviderName, name: string, model: string}  $role
     * @param  array<string, mixed>  $input
     */
    private function ask(RunContext $run, string $name, array $role, string $system, array $input): ModelReply
    {
        $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxOutput = (int) Settings::get("recovery.{$name}_max_output_tokens");
        $in = (float) Settings::get("recovery.{$name}_input_usd_per_million");
        $out = (float) Settings::get("recovery.{$name}_output_usd_per_million");

        // Hebrew runs at about two characters a token; estimating high is the safe side.
        $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $maxOutput * $out) / 1_000_000);

        $reply = $this->chat->json($role['provider'], $role['model'], $system, $user, $maxOutput, 'low');
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

        return $reply;
    }

    /** @return array{provider: AiProviderName|null, name: string, model: string} */
    private function role(string $name): array
    {
        $provider = strtolower(trim((string) Settings::get("recovery.{$name}_provider")));

        return ['provider' => AiProviderName::tryFrom($provider), 'name' => $provider, 'model' => trim((string) Settings::get("recovery.{$name}_model"))];
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(self::PROMPTS.$name.'.v'.self::PROMPT_VERSION.'.md');
    }

    /** At most this many words, whatever the model wrote. */
    private function words(string $text, int $max): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, $max));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, string>
     */
    private function params(array $stats): array
    {
        return ['converted' => (string) $stats['converted'], 'reported' => (string) $stats['reported'], 'thin' => (string) $stats['thin'], 'refused' => (string) $stats['refused']];
    }
}
