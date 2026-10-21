<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\NoAnswer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Settings · The assistant, as the designer's panel lays it out: whether it answers at all (off turns
 * off all three places), where shoppers may ask, the daily limits that keep the monthly allowance, and
 * what a shopper sees when the site has no answer (NoAnswer). Under the title, what it did in the last
 * 30 days, counted as the questions screen counts them.
 */
class AssistantSettings extends Page
{
    use EditsShopSettings;

    private const PLACES = ['on_products', 'on_content', 'on_search'];

    public static string $sideGroup = 'shoppers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'settings/assistant';

    protected string $view = 'assistant::merchant.settings.assistant';

    /** @var array{on: bool, asked: int, answered: int, waiting: int} what it did, as saved */
    public array $status = ['on' => false, 'asked' => 0, 'answered' => 0, 'waiting' => 0];

    /** The most questions in one day last month: what the shop's daily limit should stay above. */
    public int $busiest = 0;

    public static function getNavigationLabel(): string
    {
        return __('assistant::shop_settings.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [
            'on_products' => 'feature:assistant.on_products',
            'on_content' => 'feature:assistant.on_content',
            'on_search' => 'feature:assistant.on_search',
            'visitor_limit' => 'feature:assistant.visitor_limit',
            'per_visitor' => 'setting:assistant.questions_per_visitor_per_day',
            'per_shop' => 'setting:assistant.questions_per_shop_per_day',
            'no_answer' => 'setting:assistant.no_answer',
            'not_sure' => 'setting:search.not_sure',
        ];
    }

    protected function loadMore(): void
    {
        $this->data['on'] = $this->anyPlace();
        $this->measure();
    }

    /**
     * The master switch: off turns off every place a shopper may ask; on, with no place on, turns them
     * all on (the switch would otherwise say "on" while the assistant answers nowhere).
     */
    protected function checkMore(): void
    {
        if (! (bool) ($this->data['on'] ?? false)) {
            foreach (self::PLACES as $place) {
                $this->data[$place] = false;
            }
        } elseif (! $this->anyPlace()) {
            foreach (self::PLACES as $place) {
                $this->data[$place] = true;
            }
        }

        if (! in_array($this->data['no_answer'] ?? null, NoAnswer::ALL, true)) {
            $this->data['no_answer'] = NoAnswer::TEAM;
        }

        if (! in_array($this->data['not_sure'] ?? null, ['inside', 'outside'], true)) {
            $this->data['not_sure'] = 'inside';
        }
    }

    protected function saved(): void
    {
        $this->data['on'] = $this->anyPlace();
        $this->measure();
    }

    /** @return array{min: int, max: int} a limit's bounds, as its module declares them */
    public function bounds(string $key): array
    {
        $definition = app(SettingRegistry::class)->get($key);

        return ['min' => (int) ($definition->min ?? 0), 'max' => (int) ($definition->max ?? 9999)];
    }

    private function anyPlace(): bool
    {
        foreach (self::PLACES as $place) {
            if ((bool) ($this->data[$place] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last 30 days as the questions screen shows them (each question once; "waiting" is its open
     * filter), and the busiest day of last month: search questions that were not a saved answer, the
     * ones that count toward the shop's daily limit.
     */
    private function measure(): void
    {
        $shop = $this->shopId();

        if ($shop === '') {
            return;
        }

        $inbox = new ShopQuestions;
        $inbox->shop = $shop;
        $counts = $inbox->counts($inbox->rows());
        $this->status = ['on' => $this->anyPlace(), 'asked' => $counts['all'], 'answered' => $counts['answered'], 'waiting' => $counts['open']];

        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $this->busiest = app(TenantContext::class)->run($shop, fn (): int => (int) (AssistantSearchAsk::query()
            ->whereDate('day', '>=', $from->toDateString())->whereDate('day', '<=', $to->toDateString())
            ->where('from', '!=', 'bank')
            ->select('day', DB::raw('count(*) as asks'))->groupBy('day')
            ->get()->max('asks') ?? 0));
    }
}
