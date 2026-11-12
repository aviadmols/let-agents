<?php

namespace App\Modules\Search\Support;

/**
 * How alike two pieces of clothing or shoes look, as one score of what a shopper sees (Vans, 12.11): its picture's
 * nearness (the order its pictures and words gave), its colour, its print (which, and how much of it it covers: a print on
 * the chest is nearer another on the chest than stripes all over), its side (short beside short), and whom it is for (a
 * kids' sneaker beside kids' sneakers). Each a share from 0 to 1, weighed; no signal decides alone, so a far picture of
 * the right colour no longer comes first. Code only, any store whose titles name no colour.
 */
final class AlikeScore
{
    /** How much each signal weighs. Whom it is for weighs most: a kids' shoe is no adult's alike, however it looks. */
    public const WEIGHTS = ['look' => 1.0, 'colour' => 1.0, 'print' => 2.0, 'side' => 0.8, 'audience' => 2.0];

    /** A print that covers at least this much (Patterns' levels: 3 most, 4 all) is all over; less is a print on a part. */
    private const ALL_OVER = 3;

    /**
     * The print match: plain beside plain, or the same print, is alike; a print on a part beside another on a part nearly
     * so; one all over beside a plain piece or a print on a part, not at all.
     *
     * @param  array{family: int|null, level: int}|null  $own
     * @param  array{family: int|null, level: int}|null  $other
     */
    public static function print(?array $own, ?array $other): float
    {
        if ($own === null || $other === null) {
            return 0.5;
        }
        $ownPlain = $own['family'] === null || $own['level'] <= 1;
        $otherPlain = $other['family'] === null || $other['level'] <= 1;
        $ownAll = ! $ownPlain && $own['level'] >= self::ALL_OVER;
        $otherAll = ! $otherPlain && $other['level'] >= self::ALL_OVER;

        return match (true) {
            $ownPlain && $otherPlain => 1.0,
            ! $ownPlain && ! $otherPlain && $own['family'] === $other['family'] => 1.0,
            $ownAll !== $otherAll => 0.0,
            $ownPlain !== $otherPlain => 0.4,
            $ownAll && $otherAll => 0.3,
            default => 0.75,
        };
    }

    /**
     * The colour match: the same colour as each one is seen (its own filed colour when it has one, else its picture's),
     * alike; its colour among the other's filed colours, half.
     *
     * @param  list<string>  $ownFiled
     * @param  list<string>  $otherFiled
     */
    public static function colour(array $ownFiled, ?array $ownSeen, array $otherFiled, ?array $otherSeen): float
    {
        if (count($ownFiled) === 1 && count($otherFiled) === 1) {
            return $ownFiled[0] === $otherFiled[0] ? 1.0 : (Colours::near($ownSeen, $otherSeen) >= 0.8 ? 0.6 : 0.0);
        }
        if ($ownSeen !== null && $otherSeen !== null && Colours::near($ownSeen, $otherSeen) >= 0.8) {
            return 1.0;
        }

        return array_intersect($ownFiled, $otherFiled) !== [] ? 0.5 : 0.0;
    }

    /**
     * Whom it is for: the same, or either unknown, alike; kids beside adults, not at all.
     *
     * @param  list<string>  $own  of women, men, kids, babies (ProductKinds::audiences)
     * @param  list<string>  $other
     */
    public static function audience(array $own, array $other): float
    {
        if ($own === [] || $other === []) {
            return 1.0;
        }
        $young = fn (array $a): bool => array_intersect($a, ['kids', 'babies']) !== [];

        return $young($own) === $young($other) ? 1.0 : 0.0;
    }

    /**
     * The weighed score, from each signal's share.
     *
     * @param  array{look: float, colour: float, print: float, side: float, audience: float}  $shares
     */
    public static function of(array $shares): float
    {
        $sum = 0.0;
        foreach (self::WEIGHTS as $signal => $weight) {
            $sum += $weight * (float) ($shares[$signal] ?? 0.0);
        }

        return $sum;
    }
}
