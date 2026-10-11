<?php

namespace App\Modules\Search\Support;

/**
 * What a shopper's photo turned out to show, after the reader and the shop's own words placed it:
 * the tags the shopper sees, and how they were reached, for the photo log and the operator.
 */
final class PhotoLook
{
    /** Why a second look was taken. */
    public const UNPLACED = 'unplaced';

    public const UNSURE = 'unsure';

    public const PICTURES_DISAGREE = 'pictures_disagree';

    /** The team marked the reading wrong: the stronger model reads it again with their word. */
    public const TEAM_WRONG = 'team_wrong';

    /**
     * @param  list<array{title: string, kind: string, url?: string, id?: string, main?: bool}>|null  $tags  category tags first, then words; an
     *                                                                                                       empty list when the photo shows nothing the shop sells; null when it could not be read
     * @param  string|null  $object  what the shopper photographed, in the reader's words
     * @param  list<string>  $around  other things in the photo
     * @param  float|null  $sure  how sure the reader was, 0 to 1
     * @param  string|null  $doubt  why a second look was wanted, or null when the first look stood
     * @param  bool  $second  whether the second look was taken
     * @param  string|null  $main  the main tag's title, after everything
     * @param  list<string>  $details  what tells the object apart from others of its kind ("אבן אובלית בצבע תכלת")
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $box  where it is in the photo, 0 to 1: left, top, right, bottom
     * @param  list<string>  $items  every separate thing in the photo the shopper might mean, the object first; empty when one
     * @param  int  $item  which of them was searched: 0 the object, 1 the first of the others
     */
    public function __construct(
        public readonly ?array $tags,
        public readonly ?string $object = null,
        public readonly array $around = [],
        public readonly ?float $sure = null,
        public readonly ?string $doubt = null,
        public readonly bool $second = false,
        public readonly ?string $main = null,
        public readonly array $details = [],
        public readonly ?array $box = null,
        public readonly array $items = [],
        public readonly int $item = 0,
    ) {}

    /** The photo could not be read: reading is off, no model, or the call failed. */
    public static function unread(): self
    {
        return new self(null);
    }

    /** The photo shows nothing the shop sells. */
    public static function nothing(?string $object, ?float $sure, bool $second = false, ?string $doubt = null): self
    {
        return new self([], $object, [], $sure, $doubt, $second);
    }

    /** The same reading, with what tells the object apart and where it is in the photo. */
    public function seen(array $details, ?array $box): self
    {
        return new self($this->tags, $this->object, $this->around, $this->sure, $this->doubt, $this->second, $this->main, $details, $box, $this->items, $this->item);
    }

    /** The same reading, knowing the separate things in the photo and which of them was searched. */
    public function among(array $items, int $item): self
    {
        return new self($this->tags, $this->object, $this->around, $this->sure, $this->doubt, $this->second, $this->main, $this->details, $this->box, $items, $item);
    }

    /** The shop's own tags for the photo, the main one first. */
    public function with(array $tags, ?string $doubt = null, bool $second = false): self
    {
        $main = null;

        foreach ($tags as $tag) {
            if (($tag['main'] ?? false) === true) {
                $main = (string) $tag['title'];
                break;
            }
        }

        return new self($tags, $this->object, $this->around, $this->sure, $doubt, $second, $main, $this->details, $this->box, $this->items, $this->item);
    }
}
