<?php

namespace App\Modules\Assistant\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Events\CatalogLinksMoved;

/**
 * The store moved: a kept answer's sources and the links in its words point at the new address, as the drawer shows
 * them (Sela Meir, 28.10: answers still sent shoppers to its staging copy). Saved quietly: the catalogue update that
 * follows reads what the store says again, once.
 */
final class MoveAnswerLinks
{
    public function handle(CatalogLinksMoved $event): void
    {
        app(TenantContext::class)->run($event->shopId, function () use ($event): void {
            $like = '%'.$event->from.'%';

            AssistantAnswer::query()
                ->where(fn ($q) => $q->where('answer', 'like', $like)->orWhereRaw('CAST(sources AS TEXT) LIKE ?', [$like]))
                ->chunkById(500, function ($rows) use ($event): void {
                    foreach ($rows as $answer) {
                        if ($answer->answer !== null) {
                            $answer->answer = $event->inText($answer->answer);
                        }

                        if (is_array($answer->sources)) {
                            $answer->sources = array_map(fn (mixed $s): mixed => is_array($s) && isset($s['url']) && is_string($s['url'])
                                ? array_replace($s, ['url' => $event->link($s['url'])]) : $s, $answer->sources);
                        }

                        $answer->isDirty() && $answer->saveQuietly();
                    }
                });
        });
    }
}
