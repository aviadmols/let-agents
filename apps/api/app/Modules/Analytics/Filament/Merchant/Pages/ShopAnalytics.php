<?php

namespace App\Modules\Analytics\Filament\Merchant\Pages;

use App\Core\Filament\HasPeriod;
use App\Modules\Admin\Contracts\ReportRow;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * The shop's analytics, as the designer's panel lays them out: everything shoppers did with Lets in one
 * place. The store's numbers with adds by day; popular products; the wishlist; the words searched most;
 * the questions asked most; the searches that failed (nothing found, or nothing clicked); and each list
 * in full ("the whole list", searched and sorted). Every module gives its part (ShopReport); nothing
 * here knows a module. Counts only: no model, no cost.
 */
final class ShopAnalytics extends Page
{
    use HasPeriod;

    /** How many rows each list shows on the screen; its whole list shows the rest. */
    public const SHOWN = ['adds' => 5, 'wish' => 3, 'words' => 6, 'questions' => 5, 'empty' => 5, 'noclick' => 5, 'ips' => 4];

    private const PAGE = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'analytics';

    protected string $view = 'analytics::merchant.analytics';

    /** One list in full, or none: the screen. */
    #[Url]
    public string $list = '';

    #[Url(as: 'find')]
    public string $search = '';

    #[Url]
    public string $sort = 'value';

    /** The failed searches card: nothing found, or nothing clicked. */
    public string $failed = 'empty';

    public int $limit = self::PAGE;

    /** @var array<string, mixed>|null */
    private ?array $figuresCache = null;

    public static function getNavigationLabel(): string
    {
        return __('analytics::report.title');
    }

    public function getTitle(): string
    {
        return __('analytics::report.title');
    }

    public function getSubheading(): ?string
    {
        return __('analytics::report.subheading');
    }

    public function getHeader(): ?View
    {
        return view('analytics::merchant.analytics-head', [
            'title' => $this->getTitle(),
            'subheading' => $this->getSubheading(),
            'back' => false,
        ]);
    }

    public function open(string $list): void
    {
        $this->list = in_array($list, array_keys(self::SHOWN), true) ? $list : '';
        $this->search = '';
        $this->sort = 'value';
        $this->limit = self::PAGE;
    }

    public function showFailed(string $which): void
    {
        $this->failed = $which === 'noclick' ? 'noclick' : 'empty';
    }

    public function sortBy(string $sort): void
    {
        $this->sort = $sort === 'name' ? 'name' : 'value';
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    /** A row's button: a synonym for a word that found nothing, blocking an IP. */
    public function act(string $list, string $key, string $action, string $value = ''): void
    {
        foreach ($this->sources() as $source) {
            if (in_array($list, $source->lists(), true) && ($said = $source->act($this->shop(), $list, $key, $action, $value)) !== null) {
                Notification::make()->success()->title($said)->send();
                $this->figuresCache = null;

                return;
            }
        }
    }

    /** @return array<string, mixed> every module's numbers for the period */
    public function figures(): array
    {
        if ($this->figuresCache !== null) {
            return $this->figuresCache;
        }

        [$since, $until] = $this->range();
        $figures = [];

        foreach ($this->sources() as $source) {
            try {
                $figures = array_replace_recursive($figures, $source->figures($this->shop(), $since, $until));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->figuresCache = $figures;
    }

    /** @return array{rows: list<ReportRow>, total: int} */
    public function rows(string $list, ?int $limit = null): array
    {
        [$since, $until] = $this->range();

        foreach ($this->sources() as $source) {
            if (in_array($list, $source->lists(), true)) {
                try {
                    return $source->rows($this->shop(), $list, $since, $until, $limit ?? (self::SHOWN[$list] ?? 5), $this->list === $list ? $this->search : '', $this->list === $list ? $this->sort : 'value');
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return ['rows' => [], 'total' => 0];
    }

    /** The sessions that searched a word, over the same period. */
    public function sessionsFor(string $word): string
    {
        return ShopSessions::getUrl(array_filter(['q' => $word, 'days' => $this->days, 'from' => $this->from, 'to' => $this->to]));
    }

    public function has(string $list): bool
    {
        foreach ($this->sources() as $source) {
            if (in_array($list, $source->lists(), true)) {
                return true;
            }
        }

        return false;
    }

    public static function money(float $amount, string $currency): string
    {
        $symbol = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency.' ';

        return $symbol.number_format($amount, $amount >= 1000 ? 0 : 2);
    }

    protected function periodChanged(): void
    {
        $this->figuresCache = null;
        $this->limit = self::PAGE;
    }

    /** @return list<ShopReport> */
    private function sources(): array
    {
        return array_values(array_filter(iterator_to_array(app()->tagged(ShopReport::REPORT_TAG)), fn ($s): bool => $s instanceof ShopReport));
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
