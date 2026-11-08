<?php

namespace App\Modules\Search\Support;

/**
 * What the drawer showed when the shopper shared it (Aviad, 8.11: a shared link opened another search than the one on
 * screen), read from what the storefront sent, so the link opens it as it was: a search (its words and products), an
 * answer to a question (its text, the products it picked and why, the pages it came from), a photo's results (what
 * the photo was read as), or the like of one product. Everything is bounded and plain: the text is shown as text, a
 * source is an http(s) address, a pick names a product by its id. Nothing from the browser is trusted beyond that.
 */
final class ShareView
{
    public const KINDS = ['results', 'answer', 'photo', 'similar'];

    private const ANSWER_CHARS = 1500;

    private const WHY_CHARS = 200;

    private const PICKS = 12;

    private const SOURCES = 5;

    /**
     * @param  mixed  $sent  the body's "view"
     * @return array<string, mixed>
     */
    public static function from(mixed $sent): array
    {
        if (! is_array($sent)) {
            return ['kind' => 'results'];
        }
        $kind = in_array($sent['kind'] ?? null, self::KINDS, true) ? (string) $sent['kind'] : 'results';
        $text = fn (mixed $v, int $max): string => mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', is_scalar($v) ? (string) $v : '')), 0, $max);
        $id = fn (mixed $v): ?string => is_scalar($v) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', (string) $v) === 1 ? (string) $v : null;

        return match ($kind) {
            'answer' => array_filter([
                'kind' => 'answer',
                'answer' => $text($sent['answer'] ?? '', self::ANSWER_CHARS),
                'picks' => array_values(array_filter(array_map(fn ($p): ?array => is_array($p) && ($pid = $id($p['id'] ?? null)) !== null
                    ? ['id' => $pid, 'why' => $text($p['why'] ?? '', self::WHY_CHARS)] : null, array_slice((array) ($sent['picks'] ?? []), 0, self::PICKS)))),
                'sources' => array_values(array_filter(array_map(fn ($s): ?array => is_array($s) && preg_match('#^https?://#i', (string) ($s['url'] ?? '')) === 1
                    ? ['title' => $text($s['title'] ?? '', 200), 'url' => mb_substr((string) $s['url'], 0, 500)] : null, array_slice((array) ($sent['sources'] ?? []), 0, self::SOURCES)))),
            ], fn ($v): bool => $v !== '' && $v !== []),
            'photo' => array_filter(['kind' => 'photo', 'seen' => $text($sent['seen'] ?? '', 200)], fn ($v): bool => $v !== ''),
            'similar' => array_filter(['kind' => 'similar', 'of' => $id($sent['of'] ?? null)], fn ($v): bool => $v !== null),
            default => ['kind' => 'results'],
        };
    }
}
