<?php

namespace App\Modules\Runs\Filament\Operator\Pages;

use App\Modules\Runs\Filament\Operator\Resources\Runs\RunResource;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

/**
 * Every model call, one line each, the latest first, for the operator only: when, for which shop,
 * which agent and what for, the provider and model, the tokens and what the call cost. Read from
 * the ledger, where every call is a row; each line opens the run it belongs to.
 */
final class ModelCalls extends Page
{
    use WithPagination;

    public const PER_PAGE = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'model-calls';

    protected string $view = 'runs::operator.model-calls';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $shop = '';

    /** "provider|model", or every model when empty. */
    #[Url]
    public string $model = '';

    #[Url]
    public string $agent = '';

    public static function getNavigationLabel(): string
    {
        return __('runs::calls.title');
    }

    public function getTitle(): string
    {
        return __('runs::calls.title');
    }

    public function getSubheading(): ?string
    {
        return __('runs::calls.subheading');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'shop', 'model', 'agent'], true)) {
            $this->resetPage();
        }
    }

    /** @return array{0: string, 1: string} the first and last day shown: the last week until days are chosen */
    public function range(): array
    {
        $valid = fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $from = $valid($this->from) ? $this->from : now()->subDays(6)->toDateString();
        $to = $valid($this->to) ? $this->to : now()->toDateString();

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> "provider|model" => "provider · model", every model in the ledger */
    public function models(): array
    {
        return DB::table('ai_usage')->select('provider', 'model')->distinct()->orderBy('provider')->orderBy('model')->get()
            ->mapWithKeys(fn (object $r): array => [$r->provider.'|'.$r->model => trim($r->provider.' · '.$r->model, ' ·')])->all();
    }

    /** @return array<string, string> agent => its name, every agent in the ledger */
    public function agents(): array
    {
        return DB::table('ai_usage')->distinct()->orderBy('agent')->pluck('agent')
            ->mapWithKeys(fn ($agent): array => [(string) $agent => Run::labelFor('agents', (string) $agent)])->all();
    }

    /** @return array{cost: float, input: int, output: int, cache: int, calls: int} of every call the filters hold */
    public function totals(): array
    {
        $row = $this->ledger()->selectRaw('count(*) as calls, sum(input_tokens) as input, sum(output_tokens) as output, sum(cache_read_tokens) as cache, sum(cost_usd) as cost')->first();

        return ['cost' => (float) ($row->cost ?? 0), 'input' => (int) ($row->input ?? 0), 'output' => (int) ($row->output ?? 0), 'cache' => (int) ($row->cache ?? 0), 'calls' => (int) ($row->calls ?? 0)];
    }

    /** The calls, the latest first, a page at a time, each with its shop's name, its agent's and the run's link. */
    public function calls(): LengthAwarePaginator
    {
        $names = $this->shops();
        $page = $this->ledger()
            ->select(['id', 'run_id', 'shop_id', 'agent', 'action', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cost_usd', 'created_at'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $page->getCollection()->transform(function (object $call) use ($names): object {
            $call->shop = $call->shop_id === null ? __('runs::spend.platform') : ($names[$call->shop_id] ?? __('runs::spend.deleted'));
            $call->agent_label = Run::labelFor('agents', (string) $call->agent);
            $call->action_label = Run::labelFor('actions', (string) $call->action);
            $call->url = $call->run_id === null ? null : RunResource::getUrl('view', ['record' => $call->run_id]);

            return $call;
        });

        return $page;
    }

    /** The ledger for the days, shop, model and agent chosen. */
    private function ledger(): Builder
    {
        [$from, $to] = $this->range();
        [$provider, $model] = array_pad(explode('|', $this->model, 2), 2, '');

        return DB::table('ai_usage')
            ->whereBetween('day', [$from, $to])
            ->when($this->shop !== '', fn (Builder $q) => $q->where('shop_id', $this->shop))
            ->when($this->model !== '', fn (Builder $q) => $q->where('provider', $provider)->where('model', $model))
            ->when($this->agent !== '', fn (Builder $q) => $q->where('agent', $this->agent));
    }
}
