<?php

namespace App\Modules\Assistant\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every question shoppers asked in one store, by the page it was asked on: the ones nobody could answer first, so
 * the store team can write the answer, then the answered ones. A team answer is what the next
 * shopper who asks gets, and it never gets replaced by a model.
 */
class ShopQuestions extends Page
{
    public const TEAM = 'team';

    private const RECENT_DAYS = 90;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'assistant/questions';

    protected string $view = 'assistant::operator.questions';

    #[Url]
    public ?string $shop = null;

    /** @var array<string, string> answer drafts by question id */
    public array $drafts = [];

    public static function getNavigationLabel(): string
    {
        return __('assistant::ui.questions.title');
    }

    public function getTitle(): string
    {
        return __('assistant::ui.questions.title');
    }

    public function getSubheading(): ?string
    {
        return __('assistant::ui.questions.subheading');
    }

    public function mount(): void
    {
        // The shop the panel is inside, so every screen agrees; the first by name when it is
        // looking across every shop.
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array{open: Collection<string, Collection<int, AssistantAnswer>>, answered: Collection<string, Collection<int, AssistantAnswer>>, refused: Collection<int, AssistantAnswer>, totals: array<string, int>}|null
     */
    public function questions(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $all = AssistantAnswer::query()->with(['product:id,external_id,title,url', 'content:id,external_id,title,url'])
                ->where('last_asked_at', '>=', now()->subDays(self::RECENT_DAYS))
                ->orderByDesc('asked_count')->orderByDesc('last_asked_at')
                ->get();

            $shown = $all->where('status', AssistantAnswer::SHOWN);
            // By the page the question was asked on: a product, or a guide.
            $byProduct = fn (Collection $rows): Collection => $rows->groupBy(fn (AssistantAnswer $row): string => (string) ($row->product_id ?? $row->content_id))
                ->sortByDesc(fn (Collection $group): array => [$group->sum('asked_count'), (string) $group->max('last_asked_at')]);

            return [
                'open' => $byProduct($shown->where('outcome', AssistantAnswer::NO_INFO)),
                'answered' => $byProduct($shown->where('outcome', AssistantAnswer::ANSWERED)),
                'refused' => $shown->where('outcome', AssistantAnswer::OUT_OF_SCOPE)->values(),
                'hidden' => $all->where('status', AssistantAnswer::HIDDEN)->values(),
                'totals' => [
                    'questions' => (int) $all->sum('asked_count'),
                    'distinct' => $all->count(),
                    'open' => $shown->where('outcome', AssistantAnswer::NO_INFO)->count(),
                    'from_team' => $shown->where('source', self::TEAM)->count(),
                    'cost' => (float) $all->sum('cost_usd'),
                ],
            ];
        });
    }

    /** The team's answer: shown to the next shopper, never replaced by a model. */
    public function answer(string $id): void
    {
        $text = trim((string) ($this->drafts[$id] ?? ''));

        if ($this->shop === null || mb_strlen($text) < 2) {
            return;
        }

        app(TenantContext::class)->run($this->shop, fn () => AssistantAnswer::query()->whereKey($id)->update([
            'answer' => mb_substr($text, 0, 1200),
            'outcome' => AssistantAnswer::ANSWERED,
            'source' => self::TEAM,
            'status' => AssistantAnswer::SHOWN,
            'updated_at' => now(),
        ]));

        unset($this->drafts[$id]);
        Notification::make()->success()->title(__('assistant::ui.questions.answered'))->send();
    }

    /** Not shown to shoppers and not suggested; asked again, it stays hidden. */
    public function hide(string $id): void
    {
        $this->setStatus($id, AssistantAnswer::HIDDEN);
    }

    public function show(string $id): void
    {
        $this->setStatus($id, AssistantAnswer::SHOWN);
    }

    private function setStatus(string $id, string $status): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, fn () => AssistantAnswer::query()->whereKey($id)->update(['status' => $status, 'updated_at' => now()]));
        Notification::make()->success()->title(__('assistant::ui.questions.saved'))->send();
    }
}
