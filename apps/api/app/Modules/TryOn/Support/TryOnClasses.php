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

    /** The fingers a ring may go on, in the order a shopper reads them. */
    public const FINGERS = ['index', 'middle', 'ring', 'pinky', 'thumb'];

    public static function group(string $class): ?string
    {
        return self::GROUPS[$class] ?? null;
    }

    public static function accepts(string $class, string $subject): bool
    {
        return in_array($subject, self::SUBJECTS[$class] ?? [], true);
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
