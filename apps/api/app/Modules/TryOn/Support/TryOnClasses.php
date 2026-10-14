<?php

namespace App\Modules\TryOn\Support;

/**
 * What each try-on class is and what it needs from the shopper's photo (docs/try-on.md). The classes
 * are the ones the catalog scan marks on a product (a fact of kind `tryon`, key `class`): clothes,
 * jewelry and whole pieces of furniture.
 *
 * Before a picture is made, whatever the photo leaves open is asked: a hand with several fingers
 * showing asks which finger the ring goes on; two hands, which one; two ears, which ear; a room with
 * room in more than one place, where the piece stands. Code asks, from what the gate saw; the
 * shopper answers; only then a picture is made.
 */
final class TryOnClasses
{
    public const APPAREL = 'apparel';

    public const JEWELRY = 'jewelry';

    public const FURNITURE = 'furniture';

    /** class => group */
    public const GROUPS = [
        'top' => self::APPAREL,
        'bottom' => self::APPAREL,
        'dress' => self::APPAREL,
        'outerwear' => self::APPAREL,
        'ring' => self::JEWELRY,
        'bracelet' => self::JEWELRY,
        'necklace' => self::JEWELRY,
        'earrings' => self::JEWELRY,
        'watch' => self::JEWELRY,
        'furniture' => self::FURNITURE,
    ];

    /** class => what the photo must show (the gate's `subject`) */
    public const SUBJECTS = [
        'top' => ['body_upper', 'body_full'],
        'outerwear' => ['body_upper', 'body_full'],
        'bottom' => ['body_full'],
        'dress' => ['body_full'],
        'ring' => ['hand'],
        'bracelet' => ['hand', 'wrist'],
        'watch' => ['hand', 'wrist'],
        'necklace' => ['neck', 'body_upper'],
        'earrings' => ['face', 'ear'],
        'furniture' => ['room'],
    ];

    /**
     * The usual real size of a piece, against the body it is worn on, for when the store states none.
     * The product picture is cut to the product, so it no longer shows how small the piece is; an
     * image model left to guess draws it as large as the picture it was given.
     */
    public const SIZES = [
        'ring' => 'A band 2 to 4 mm wide; its top, stones included, no wider than the finger it is on.',
        'necklace' => 'A thin chain, 1 to 2 mm; a pendant 15 to 25 mm tall, about the size of a fingertip: less than half the height of the person\'s ear and narrower than the bridge of their nose to the corner of their eye.',
        'earrings' => 'A stud no larger than the earlobe; a drop earring shorter than the ear itself.',
        'bracelet' => 'A band 3 to 8 mm wide that circles the wrist closely.',
        'watch' => 'A case 34 to 44 mm across, about the width of the wrist, on a strap that circles it.',
    ];

    /** The fingers a ring may go on, in the order a shopper reads them. */
    public const FINGERS = ['index', 'middle', 'ring', 'pinky', 'thumb'];

    /**
     * Words in a product's name that say its class, Hebrew and English. Only for the store team's
     * test on a product the scan did not mark (let_agents_tryon=1): shoppers never get a guess.
     */
    private const NAMES = [
        'earrings' => '/עגיל|earring|stud/iu',
        'ring' => '/טבעת|טבעות|\bring\b|\brings\b/iu',
        'bracelet' => '/צמיד|bracelet|bangle|anklet|רגלית/iu',
        'necklace' => '/שרשר|תליון|קולייה|קולר|necklace|pendant|chain|choker/iu',
        'watch' => '/שעון|\bwatch/iu',
        'dress' => '/שמלה|שמלת|\bdress/iu',
        'bottom' => '/מכנס|חצאית|ג׳ינס|ג\'ינס|טייץ|שורט|\bpants|trousers|skirt|jeans|shorts|leggings/iu',
        'outerwear' => '/מעיל|ז׳קט|ז\'קט|קרדיגן|וסט|coat|jacket|cardigan|blazer/iu',
        'top' => '/חולצ|טופ|סוודר|גופי|בלוז|טי.?שירט|shirt|\btop\b|sweater|hoodie|blouse/iu',
        'furniture' => '/ספה|כורס|שולחן|כיסא|כסא|מיטה|ארון|שידה|מדף|ספרייה|מזנון|\bsofa|couch|armchair|table|chair|\bbed\b|wardrobe|dresser|shelf|cabinet/iu',
    ];

    /** The class a product's name says: the word that comes first in it ("שרשרת עם תליון טבעת" is a necklace). */
    public static function guess(string $title): ?string
    {
        $best = null;
        $at = PHP_INT_MAX;

        foreach (self::NAMES as $class => $pattern) {
            if (preg_match($pattern, $title, $match, PREG_OFFSET_CAPTURE) === 1 && $match[0][1] < $at) {
                $best = $class;
                $at = $match[0][1];
            }
        }

        return $best;
    }

    public static function group(string $class): ?string
    {
        return self::GROUPS[$class] ?? null;
    }

    /** The parts of a person the photo check reports as visible. */
    public const PARTS = ['hand', 'wrist', 'neck', 'ear', 'face', 'body_upper', 'body_full', 'foot'];

    public static function accepts(string $class, string $subject): bool
    {
        return in_array($subject, self::SUBJECTS[$class] ?? [], true);
    }

    /**
     * Whether a checked photo serves this class: its main subject, or any part it shows clearly, is
     * one the class needs. A head-and-neck photo (main subject: face) serves a necklace.
     *
     * @param  array<string, mixed>  $gate
     */
    public static function fits(string $class, array $gate): bool
    {
        $shown = array_merge([(string) ($gate['subject'] ?? '')], array_map('strval', (array) ($gate['parts_visible'] ?? [])));

        return array_intersect($shown, self::SUBJECTS[$class] ?? []) !== [];
    }

    /**
     * What must be asked before a picture is made, from what the gate saw in the photo.
     *
     * @param  array<string, mixed>  $gate
     * @return list<array{key: string, options: list<array{key: string, label: string|null}>}> label is null for a fixed option (translated by key)
     */
    public static function questions(string $class, array $gate): array
    {
        $questions = [];
        $hands = (int) ($gate['hands_visible'] ?? 0);

        if ($class === 'ring') {
            $fingers = array_values(array_intersect(self::FINGERS, array_map('strval', (array) ($gate['fingers_visible'] ?? []))));
            if (count($fingers) > 1) {
                $questions[] = ['key' => 'finger', 'options' => array_map(fn (string $f): array => ['key' => $f, 'label' => null], $fingers)];
            }
        }

        if (in_array($class, ['ring', 'bracelet', 'watch'], true) && $hands >= 2) {
            $questions[] = ['key' => 'hand', 'options' => [['key' => 'left', 'label' => null], ['key' => 'right', 'label' => null]]];
        }

        if ($class === 'earrings' && (int) ($gate['ears_visible'] ?? 0) >= 2) {
            $questions[] = ['key' => 'ear', 'options' => [['key' => 'both', 'label' => null], ['key' => 'left', 'label' => null], ['key' => 'right', 'label' => null]]];
        }

        if ($class === 'furniture') {
            $spots = [];
            foreach (array_slice((array) ($gate['free_spots'] ?? []), 0, 4) as $i => $spot) {
                $label = trim((string) (is_array($spot) ? ($spot['label'] ?? '') : $spot));
                if ($label !== '') {
                    $spots[] = ['key' => 's'.($i + 1), 'label' => mb_substr($label, 0, 60)];
                }
            }
            if (count($spots) > 1) {
                $questions[] = ['key' => 'spot', 'options' => $spots];
            }
        }

        return $questions;
    }

    /**
     * The answers that answer every question, or null when one is missing or not one of its options.
     *
     * @param  list<array{key: string, options: list<array{key: string, label: string|null}>}>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, string>|null
     */
    public static function answered(array $questions, array $answers): ?array
    {
        $out = [];

        foreach ($questions as $question) {
            $value = $answers[$question['key']] ?? null;

            if (! is_string($value) || ! in_array($value, array_column($question['options'], 'key'), true)) {
                return null;
            }

            $out[$question['key']] = $value;
        }

        ksort($out);

        return $out;
    }
}
