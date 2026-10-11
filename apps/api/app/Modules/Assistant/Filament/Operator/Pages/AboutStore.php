<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Jobs\BuildShopKnowledgeJob;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What the assistant knows about the store before it reads any page: what the product pages
 * repeat, the store's policies, what the team wrote, and the team's answers. The team sees each
 * part, removes a sentence that is not the store's, and adds what the site does not say.
 */
class AboutStore extends Page
{
    /** Words in a page title that make it one of the store's policies. */
    private const POLICY_WORDS = '/מדיניות|החזר|ביטול|משלוח|אחריות|תקנון|פרטיות|החלפ|policy|refund|return|shipping|terms|warranty|privacy/iu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'about-store';

    protected string $view = 'assistant::operator.about-store';

    #[Url]
    public ?string $shop = null;

    /** What the team writes about the store. */
    public string $own = '';

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.knowledge.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.knowledge.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.knowledge.subheading');
    }

    /** False in the merchant panel, where the shop is the one in the address. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
        $this->load();
    }

    public function updatedShop(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->own = $this->shop === null ? '' : (string) $this->inShop(fn (): ?string => app(BuildShopKnowledge::class)->entries()[BuildShopKnowledge::OWN]->body ?? '');
    }

    /**
     * Each part, ready for the screen.
     *
     * @return array{repeated: list<string>, read_at: string|null, pages: int, policies: list<array{title: string, url: string|null}>, team: list<array{question: string, answer: string}>}|null
     */
    public function knowledge(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return $this->inShop(function (): array {
            $entries = app(BuildShopKnowledge::class)->entries();
            $repeated = $entries[BuildShopKnowledge::REPEATED] ?? null;
            $team = $entries[BuildShopKnowledge::TEAM] ?? null;

            return [
                'repeated' => $repeated === null || $repeated->removed_at !== null ? [] : array_values(array_filter(array_map(
                    fn (string $line): string => trim((string) preg_replace('/^-\s*/u', '', $line)),
                    explode("\n", (string) $repeated->body),
                ))),
                'read_at' => $repeated?->terms['read_at'] ?? null,
                'pages' => (int) ($repeated?->terms['pages'] ?? 0),
                'policies' => CatalogContent::query()->active()->where('type', CatalogContent::PAGE)->orderBy('title')->get(['title', 'url'])
                    ->filter(fn (CatalogContent $page): bool => preg_match(self::POLICY_WORDS, (string) $page->title) === 1)
                    ->map(fn (CatalogContent $page): array => ['title' => (string) $page->title, 'url' => $page->url])
                    ->values()->all(),
                'team' => $team === null || $team->removed_at !== null ? [] : array_values(array_filter(array_map(
                    function (string $pair): ?array {
                        $lines = explode("\n", trim($pair), 2);

                        return count($lines) === 2 ? ['question' => self::unlabel($lines[0]), 'answer' => self::unlabel($lines[1])] : null;
                    },
                    preg_split('/\n{2,}/u', (string) $team->body) ?: [],
                ))),
            ];
        });
    }

    public function saveOwn(): void
    {
        if ($this->shop === null) {
            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->own(mb_substr($this->own, 0, 6000)));
        Notification::make()->success()->title(__('assistant::ui.knowledge.saved'))->send();
        $this->load();
    }

    public function remove(string $sentence): void
    {
        if ($this->shop === null) {
            return;
        }

        $this->inShop(fn () => app(BuildShopKnowledge::class)->hide($sentence));
        Notification::make()->success()->title(__('assistant::ui.knowledge.removed'))->send();
    }

    public function readAgain(): void
    {
        if ($this->shop === null) {
            return;
        }

        BuildShopKnowledgeJob::dispatch($this->shop);
        Notification::make()->success()->title(__('assistant::ui.knowledge.reading'))->body(__('assistant::ui.knowledge.reading_body'))->send();
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop, $callback);
    }

    /** "שאלה: ..." without its label. */
    private static function unlabel(string $line): string
    {
        return trim((string) preg_replace('/^[^:]{1,12}:\s*/u', '', $line));
    }
}
