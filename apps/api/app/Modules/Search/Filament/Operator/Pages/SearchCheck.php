<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\ExplainSearch;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Search check: one search, or one question to the store's AI, run as a shopper's would be, with why each result is
 * there (its name, a category, a tag, the meaning, the picture), what was found and left out, and what the store could
 * change so the next shopper finds better. A search is never counted; a question is asked the whole way a shopper's
 * is, nothing of it kept, held to search.check_questions_per_day. Here the operator also sees the mechanics: the
 * meaning's scores and floors, the models, the checker's verdict, the cost and what the writer was given.
 */
class SearchCheck extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'search/check';

    protected string $view = 'search::operator.search-check';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public string $q = '';

    /** @var array<string, mixed>|null the search explained (ExplainSearch::search()) */
    public ?array $explained = null;

    /** @var array<string, mixed>|null the question's way, once asked */
    public ?array $answer = null;

    /** The day's questions from this screen are used up: their number. */
    public ?int $limit = null;

    public static function getNavigationLabel(): string
    {
        return __('search::check.title');
    }

    public function getTitle(): string
    {
        return __('search::check.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::check.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    public function picksShop(): bool
    {
        return true;
    }

    /** The mechanics too: scores, floors, models, the verdict, the cost. The store's own screen leaves them out. */
    public function deep(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function updatedShop(): void
    {
        $this->explained = null;
        $this->answer = null;
        $this->limit = null;
    }

    public function check(): void
    {
        $this->answer = null;
        $this->limit = null;
        $query = trim($this->q);
        $this->explained = $query === '' || $this->shop === null ? null : app(ExplainSearch::class)->search((string) $this->shop, mb_substr($query, 0, 200));
    }

    public function ask(): void
    {
        $question = trim($this->q);
        $this->limit = null;

        if ($question === '' || $this->shop === null) {
            return;
        }

        $asked = app(ExplainSearch::class)->question((string) $this->shop, mb_substr($question, 0, 300), app()->getLocale());

        if (isset($asked['limit'])) {
            $this->limit = (int) $asked['cap'];

            return;
        }

        $this->explained = $asked['search'];
        $this->answer = $asked['answer'];
    }

    /** Where the store's words for its products are added, so a shopper's word finds them. */
    public function synonymsUrl(): ?string
    {
        return ShopSearches::getUrl(['shop' => $this->shop]);
    }

    /** The knowledge centre: where the store adds what its pages do not say. Found by its place, not imported. */
    public function knowledgeUrl(): ?string
    {
        foreach (Filament::getCurrentPanel()?->getPages() ?? [] as $page) {
            if (is_subclass_of($page, Page::class) && ($page::getNavigationGroup() === 'knowledge' || $page::getSlug() === 'about-store') && $page::canAccess()) {
                return $this->picksShop() ? $page::getUrl(['shop' => $this->shop]) : $page::getUrl();
            }
        }

        return null;
    }
}
