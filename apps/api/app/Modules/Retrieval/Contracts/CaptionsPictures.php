<?php

namespace App\Modules\Retrieval\Contracts;

use App\Modules\Runs\Models\Run;

/** Writes what a shop's scanned pictures show, in words and as a text vector, a part at a time. */
interface CaptionsPictures
{
    /**
     * Names of stones and gems, in Hebrew and English: never a word of how a picture looks, on
     * either side (a product's picture, a shopper's photo). A photo cannot tell a ruby from a
     * garnet, and a wrong name hides the product; its colour, light and shape do not.
     */
    public const STONE_NAMES = '/(?<![\p{L}])(?:רובי|אופל|ספיר|אמרלד|אזמרגד|טופז|בלו טופז|יהלום|יהלומים|זירקון|פנינה|פנינים|אקוומרין|אמטיסט|גרנט|טורמלין|ציטרין|פרידוט|טנזנייט|מורגנייט|אוניקס|טורקיז|ג\'ייד|לאפיס|מוסניט|ruby|opal|sapphire|emerald|topaz|diamonds?|zircon|pearls?|aquamarine|amethyst|garnet|tourmaline|citrine|peridot|tanzanite|morganite|onyx|turquoise|jade|lapis|moissanite)(?![\p{L}])/iu';

    /** One part; its output's "stopped" is "more" while pictures are left. */
    public function captions(string $shopId): Run;
}
