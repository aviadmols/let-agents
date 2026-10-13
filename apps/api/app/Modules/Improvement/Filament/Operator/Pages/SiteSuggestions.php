<?php

namespace App\Modules\Improvement\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Improvement\Actions\DecideProposal;
use App\Modules\Improvement\Actions\RunDailyReview;
use App\Modules\Improvement\Jobs\DraftAnswerJob;
use App\Modules\Improvement\Jobs\DraftPostJob;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * What the daily review proposes for the site, waiting for the team: words shoppers use that the
 * store calls otherwise, questions worth answering on the site, and things shoppers look for that
 * the store does not have. Each shows the searches and questions behind it. A question comes with
 * a drafted answer the team edits and publishes; a missing topic gets a post written on request,
 * which the team edits and publishes as a guide of the store's own.
 */
class SiteSuggestions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'improvement/suggestions';

    protected string $view = 'improvement::operator.suggestions';

    #[Url]
    public ?string $shop = null;

    /** @var array<string, string> the answer or the post body as the team leaves it, by proposal */
    public array $drafts = [];

    /** @var array<string, string> the post's title as the team leaves it, by proposal */
    public array $titles = [];

    public static function getNavigationLabel(): string
    {
        return __('improvement::ui.title');
    }

    public function getTitle(): string
    {
        return __('improvement::ui.title');
    }

    public function getSubheading(): ?string
    {
        return __('improvement::ui.subheading');
    }

    public function mount(): void
    {
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

    /** @return array{pending: Collection<int, ImprovementProposal>, decided: Collection<int, ImprovementProposal>, latest: ImprovementReview|null}|null */
    public function suggestions(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        $s = app(TenantContext::class)->run($this->shop, fn (): array => [
            'pending' => ImprovementProposal::query()->with('post')->where('status', ImprovementProposal::PENDING)->latest()->orderBy('id')->get(),
            'decided' => ImprovementProposal::query()->with('post')->whereIn('status', [ImprovementProposal::APPLIED, ImprovementProposal::APPROVED, ImprovementProposal::REJECTED])->latest('updated_at')->limit(30)->get(),
            'latest' => ImprovementReview::query()->latest('day')->first(),
        ]);

        // The drafts as written, until the team edits them.
        foreach ($s['pending'] as $p) {
            if ($p->kind === ImprovementProposal::FAQ) {
                // While a model writes it, the box waits for the new answer rather than keeping the old one.
                if (! $this->drafting($p->id)) {
                    $this->drafts[$p->id] ??= (string) ($p->detail['answer'] ?? '');
                }
            } elseif ($p->post !== null) {
                $this->drafts[$p->id] ??= (string) $p->post->body;
                $this->titles[$p->id] ??= (string) $p->post->title;
            }
        }

        return $s;
    }

    /** Whether a post is being written for this proposal right now. */
    public function drafting(string $id): bool
    {
        return Cache::has(DraftPostJob::WRITING.$id) || Cache::has(DraftAnswerJob::WRITING.$id);
    }

    /** Asks a model for the answer to a question, from the store's own text, written in the background. */
    public function draftAnswer(string $id): void
    {
        if ($this->shop === null || $this->drafting($id)) {
            return;
        }

        $proposal = app(TenantContext::class)->run($this->shop, fn (): ?ImprovementProposal => ImprovementProposal::query()->whereKey($id)->where('kind', ImprovementProposal::FAQ)->first());

        if ($proposal === null) {
            return;
        }

        unset($this->drafts[$id]);
        Cache::put(DraftAnswerJob::WRITING.$id, now()->timestamp, now()->addSeconds(DraftAnswerJob::WRITING_SECONDS));
        DraftAnswerJob::dispatch($this->shop, $id);
        Notification::make()->success()->title(__('improvement::ui.answer.writing'))->send();
    }

    /** Asks for a post on a missing topic, written in the background; the screen shows it when it is there. */
    public function draftPost(string $id): void
    {
        if ($this->shop === null || $this->drafting($id)) {
            return;
        }

        $proposal = app(TenantContext::class)->run($this->shop, fn (): ?ImprovementProposal => ImprovementProposal::query()->whereKey($id)->where('kind', ImprovementProposal::CONTENT_GAP)->first());

        if ($proposal === null) {
            return;
        }

        unset($this->drafts[$id], $this->titles[$id]);
        Cache::put(DraftPostJob::WRITING.$id, now()->timestamp, now()->addSeconds(DraftPostJob::WRITING_SECONDS));
        DraftPostJob::dispatch($this->shop, $id);
        Notification::make()->success()->title(__('improvement::ui.post.writing'))->send();
    }

    public function approve(string $id): void
    {
        $this->decide($id, true);
    }

    public function reject(string $id): void
    {
        $this->decide($id, false);
    }

    /** Runs today's review now, without waiting for the night. */
    public function reviewNow(): void
    {
        if ($this->shop === null) {
            return;
        }

        $run = app(RunDailyReview::class)->handle($this->shop, RunTrigger::Manual);

        $run->status->value === 'succeeded'
            ? Notification::make()->success()->title(__('improvement::ui.reviewed'))->body($run->summary())->send()
            : Notification::make()->danger()->title(__('improvement::ui.review_failed'))->send();
    }

    private function decide(string $id, bool $approve): void
    {
        if ($this->shop === null) {
            return;
        }

        $problem = app(TenantContext::class)->run($this->shop, function () use ($id, $approve): ?string {
            $proposal = ImprovementProposal::query()->with('post')->find($id);

            if ($proposal === null) {
                return null;
            }

            if (! $approve) {
                app(DecideProposal::class)->reject($proposal, auth()->id());

                return null;
            }

            return app(DecideProposal::class)->approve($proposal, auth()->id(), $this->drafts[$id] ?? null, $this->titles[$id] ?? null);
        });

        if ($problem !== null) {
            Notification::make()->warning()->title(__($problem))->send();

            return;
        }

        unset($this->drafts[$id], $this->titles[$id]);
        Notification::make()->success()->title(__($approve ? 'improvement::ui.approved' : 'improvement::ui.rejected'))->send();
    }

    /** Where a published post is read. */
    public function guideUrl(ImprovementPost $post): string
    {
        return route('improvement.guide', ['shop' => Shop::query()->whereKey($post->shop_id)->value('slug'), 'post' => $post->id]);
    }
}
