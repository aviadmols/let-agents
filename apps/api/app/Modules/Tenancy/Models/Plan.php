<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Support\ShopPlanCeilings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A plan a shop pays for: its price a month, and its limits as ceilings on other modules' features
 * and settings, by key (PlanCeilings). Not public: sold by agreement (Enterprise), never offered.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $price_usd null: by agreement
 * @property int $sort
 * @property bool $public
 * @property array{features?: array<string, bool>, settings?: array<string, int|float|string|bool>} $limits
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price_usd', 'sort', 'public', 'limits'];

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:2',
            'sort' => 'integer',
            'public' => 'boolean',
            'limits' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => ShopPlanCeilings::flush());
        static::deleted(fn () => ShopPlanCeilings::flush());
    }

    /** @param Builder<Plan> $query */
    public function scopeOffered(Builder $query): void
    {
        $query->where('public', true)->whereNotNull('price_usd')->orderBy('sort');
    }

    public static function byCode(?string $code): ?self
    {
        return $code === null || $code === '' ? null : self::query()->where('code', $code)->first();
    }

    /** The plan as the store's bill names it: "Lets Growth". Shopify's subscription is found by it again. */
    public function billingName(): string
    {
        return 'Lets '.$this->name;
    }

    public function price(): ?float
    {
        return $this->price_usd === null ? null : (float) $this->price_usd;
    }

    /** A setting's ceiling on this plan, or null when the plan leaves it as the shop has it. */
    public function ceiling(string $key): int|float|string|bool|null
    {
        return $this->limits['settings'][$key] ?? null;
    }

    /** Whether the plan includes a feature (all do, unless the plan names it off). */
    public function includes(string $feature): bool
    {
        return ($this->limits['features'][$feature] ?? true) !== false;
    }

    /**
     * What the plan gives, a line each, in the merchant's language: the month's answers and photo
     * searches, the catalog, the knowledge pages, the team, how far back the data goes, and what only
     * some plans include.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $t = fn (string $key, array $params = []): string => __('tenancy::plans.lines.'.$key, $params);
        $count = fn (string $key, int $n): string => trans_choice('tenancy::plans.lines.'.$key, $n, ['count' => number_format($n)]);
        $cap = fn (string $key): ?int => is_numeric($this->ceiling($key)) ? (int) $this->ceiling($key) : null;
        $lines = [];

        $answers = $cap('assistant.answers_per_month');
        $lines[] = $answers === null ? $t('answers_unlimited') : $count('answers', $answers);
        $photos = $this->includes('search.photos') ? $cap('search.photos_per_month') : 0;
        $lines[] = $photos === null ? $t('photos_unlimited') : ($photos === 0 ? $t('photos_none') : $count('photos', $photos));
        $products = $cap('catalog.max_products');
        $lines[] = $products === null ? $t('products_unlimited') : $count('products', $products);
        $pages = $cap('catalog.max_content_items');
        $lines[] = $pages === null ? $t('pages_unlimited') : $count('pages', $pages);
        $team = $cap('admin.max_team_members');
        $lines[] = $team === null ? $t('team_unlimited') : $count('team', $team);
        $days = $cap('analytics.history_days');
        $lines[] = $days === null ? $t('history_unlimited') : ($days >= 365 ? $count('history_months', intdiv($days, 30)) : $count('history_days', $days));

        foreach (['analytics.sessions' => 'sessions', 'widget.on_products' => 'about_product', 'shortlist.wishlist_phone' => 'saved_by_phone',
            'assistant.ask_review' => 'daily_report', 'improvement.daily_review' => 'site_check', 'widget.whatsapp' => 'whatsapp'] as $feature => $line) {
            if ($this->includes($feature)) {
                $lines[] = $t($line);
            }
        }

        if ($this->ceiling('search.theme') === null) {
            $lines[] = $t('store_look');
        }

        return $lines;
    }
}
