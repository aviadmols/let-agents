<?php

namespace App\Modules\Admin\Contracts;

/**
 * One thing waiting for the shop owner on Today's "Waiting for you" (the designer's redesign, 11.2026): what
 * shoppers ran into, why it matters, the products it touches, and the one tap that fixes it. Written for the owner:
 * never a model, a cost or a mechanism. Ranked by $impact across every module that brings fixes (TodayImprovements).
 */
final readonly class TodayFix
{
    /** One tap and it is done ("Quick win"). */
    public const QUICK = 'quick';

    /** Shoppers ask it again and again ("Asked often"). */
    public const ASKED = 'asked';

    /** Small, worth doing when there is time ("Nice to have"). */
    public const NICE = 'nice';

    /** More than a tap: a page, a post ("Bigger idea"). */
    public const BIGGER = 'bigger';

    public const CHIPS = [self::QUICK, self::ASKED, self::NICE, self::BIGGER];

    /**
     * @param  string  $key  stable across requests, sent back with approve(): "proposal:12", "family:…", "gap:…"
     * @param  string  $unit  what $count counts: searches, times or products (the chip beside the kind)
     * @param  string  $title  the headline, already in the owner's language; shoppers' words stay as typed
     * @param  string|null  $struck  a word inside $title shown struck through: what shoppers typed that found nothing
     * @param  list<string>  $pictures  product photos, at most two are shown
     * @param  bool  $oneTap  true: the button approves here (approve()); false: it opens $url
     * @param  int  $impact  searches or questions it would help, for the ranking
     * @param  string  $kind  what shoppers ran into, for the Improvements screen's filter: nothing (found nothing), noclick
     *                        (found what nobody opens), question, info (a product detail) or idea
     * @param  array<string, mixed>  $suggestion  what the fix would do, shown before it is approved: type rule (from, to and
     *                                            the products they'll see), products, answer (a draft the owner may edit), facts
     *                                            (each product with what its photo showed) or idea; plain values only
     * @param  list<array{text: string, n: int}>  $typed  what exactly shoppers typed or asked, the most first
     * @param  array{label: string, url: string}|null  $secondary  the other way to fix it, on its own screen
     * @param  bool  $editable  the owner may change the suggestion's text before approving (an answer)
     */
    public function __construct(
        public string $key,
        public string $chip,
        public int $count,
        public string $unit,
        public string $title,
        public string $text,
        public string $action,
        public bool $oneTap,
        public ?string $url = null,
        public ?string $struck = null,
        public array $pictures = [],
        public int $impact = 0,
        public string $kind = 'nothing',
        public array $suggestion = [],
        public array $typed = [],
        public ?array $secondary = null,
        public bool $editable = false,
    ) {}

    public const KINDS = ['nothing', 'noclick', 'question', 'info', 'idea'];
}
