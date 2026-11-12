<?php

namespace App\Modules\Assistant\Filament\Merchant\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\TeamAnswers;
use App\Modules\Assistant\Jobs\CheckOwnKnowledgeJob;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\SourceLines;
use Filament\Notifications\Notification;

/**
 * A line a source says, fixed where it is written, from wherever it is shown (a question's "why", a line's check on the
 * knowledge screen): the team's own line changed or removed (and checked again against the site), the team's answer
 * changed or taken away, a sentence the product pages repeat or a fact read from a post never used again. A page or
 * a post itself is edited on the store's own site (SourceLines' edit link). The screen keeps its shop in $shop.
 */
trait EditsSourceLines
{
    /** The line being changed, as "kind|key". */
    public ?string $lineEditing = null;

    public string $lineText = '';

    public function editLine(string $kind, string $key, string $text): void
    {
        if (! in_array($kind, [SourceLines::OWN, SourceLines::TEAM], true)) {
            return;
        }

        $this->lineEditing = $kind.'|'.$key;
        $this->lineText = $text;
    }

    public function cancelLine(): void
    {
        $this->lineEditing = null;
        $this->lineText = '';
    }

    public function saveLine(): void
    {
        if ($this->lineEditing === null || $this->shop === null || ! $this->mayFixLines()) {
            return;
        }

        [$kind, $key] = explode('|', $this->lineEditing, 2);
        $text = trim($this->lineText);
        $shop = (string) $this->shop;

        app(TenantContext::class)->run($shop, function () use ($kind, $key, $text, $shop): void {
            if ($kind === SourceLines::OWN) {
                app(BuildShopKnowledge::class)->editOwn($key, $text);
                if ($text !== '') {
                    CheckOwnKnowledgeJob::dispatch($shop, $key);
                }

                return;
            }

            $answer = AssistantAnswer::query()->whereKey($key)->where('source', TeamAnswers::TEAM)->first();
            if ($answer !== null && app(TeamAnswers::class)->answer($answer, $text) === 'saved') {
                app(BuildShopKnowledge::class)->team();
            }
        });

        $this->cancelLine();
        Notification::make()->success()->title(__('assistant::ui.inbox.why.line_saved'))->send();
    }

    /** A line taken away where it is written: not read, said or used again. */
    public function removeLine(string $kind, string $key): void
    {
        if ($this->shop === null || ! $this->mayFixLines()) {
            return;
        }

        app(TenantContext::class)->run((string) $this->shop, function () use ($kind, $key): void {
            $knowledge = app(BuildShopKnowledge::class);

            match ($kind) {
                SourceLines::OWN => $knowledge->removeOwn($key),
                SourceLines::REPEATED => $knowledge->hide($key),
                SourceLines::POST_FACT => $knowledge->hidePostFact($key),
                // a page or post of the store's site, stopped whole (brought back on the store's knowledge screen)
                SourceLines::PAGE => $knowledge->ignore($key, auth()->id() === null ? null : (int) auth()->id()),
                SourceLines::TEAM => (function () use ($key, $knowledge): void {
                    AssistantAnswer::query()->whereKey($key)->where('source', TeamAnswers::TEAM)->update(['status' => AssistantAnswer::HIDDEN]);
                    $knowledge->team();
                })(),
                default => null,
            };
        });

        $this->cancelLine();
        Notification::make()->success()->title(__('assistant::ui.inbox.why.line_removed'))->send();
    }

    /**
     * The lines a source says, where they are written (SourceLines).
     *
     * @param  array{title: string, url?: string|null}  $source
     * @param  list<string>  $exceptOwn
     * @return list<array{kind: string, key: string, text: string, label: string|null, url: string|null, edit_url: string|null}>
     */
    public function linesOf(array $source, string $near, array $exceptOwn = []): array
    {
        return $this->shop === null ? [] : app(TenantContext::class)->run((string) $this->shop, fn (): array => SourceLines::of((string) $this->shop, $source, $near, $exceptOwn));
    }

    /** Who may fix a line: who edits the shop, or an operator. */
    abstract public function mayFixLines(): bool;
}
