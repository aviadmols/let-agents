<?php

namespace App\Modules\Enrichment\Enums;

enum FactKind: string
{
    /** Product type from the vocabulary, such as jigsaw. */
    case Type = 'type';

    /** A number with a unit, such as weight_kg = 2.3. */
    case Spec = 'spec';

    /** One value of a fixed choice, such as power_source = cordless. */
    case Choice = 'choice';

    /** A yes/no spec that is true, such as brushless. */
    case Flag = 'flag';

    /** A job or project the product is good for, such as deck or pergola. Shown as "good for". */
    case Use = 'use';

    /** The maker, normalized: Makita, Stanley, Blanchon. Read by code from store fields and text. */
    case Brand = 'brand';

    /** A tag from the vocabulary, such as compact. */
    case Tag = 'tag';

    /** What an article is: buying guide, how-to, store page. */
    case ContentKind = 'content_kind';

    /** How much an article helps a shopper: high, medium, low, none. */
    case ShopperValue = 'shopper_value';

    /** A category an article helps shoppers with. */
    case Category = 'category';

    /** What a shopper should know, from the product's own text: a short phrase (key) and one sentence. */
    case Highlight = 'highlight';

    /**
     * Something that helps a shopper decide, quoted from the store's own words: a refund window
     * or free delivery from the shop's pages, hand work or a material from the product's text.
     * The key says which promise it is and the value holds the detail, exactly as written.
     */
    case Promise = 'promise';

    /**
     * Whether the product could be tried on a person or placed in a room: key `class`, value one of
     * TryOnEligibility::CLASSES or `none`. Decided in code from the vocabulary (ADR 0012).
     */
    case TryOn = 'tryon';

    public function label(): string
    {
        return __("enrichment::enrichment.fact_kinds.{$this->value}");
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $k): string => $k->value, self::cases()),
            array_map(fn (self $k): string => $k->label(), self::cases()),
        );
    }
}
