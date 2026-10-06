<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Enums\MatchKind;
use App\Modules\Retrieval\Enums\MatchStatus;

/**
 * Code's verdict on every pick the matching model made. The model chooses; this decides.
 *
 *   unknown       a ref code never offered: the model invented it
 *   both_kinds    already picked as the other kind: a product is a complement or an alternative
 *   unavailable   out of stock or not for sale any more
 *   not_similar   an alternative whose text is not close enough in meaning to be one
 *   over_limit    past the shop's number per kind
 *
 * A pick repeated within one list is dropped without a row: it is the same pick.
 */
final class MatchCheck
{
    public const UNKNOWN = 'unknown';

    public const BOTH_KINDS = 'both_kinds';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_SIMILAR = 'not_similar';

    public const OVER_LIMIT = 'over_limit';

    private const REASON_CHARS = 200;

    /**
     * @param  array<string, mixed>  $answer  the model's JSON
     * @param  array<string, array{product_id: string, signals: array<string, mixed>, sources: list<string>}>  $candidates  by ref
     * @param  array<string, int>  $limits  kind value => most to accept
     * @param  array<string, bool>  $available  product ID => in stock and for sale
     * @return list<array{kind: MatchKind, ref: string, product_id: string|null, status: MatchStatus, rejected_because: string|null, position: int, reason: string|null, signals: array<string, mixed>|null}>
     */
    public static function judge(array $answer, array $candidates, array $limits, array $available, float $minSimilarity): array
    {
        $verdicts = [];
        $chosen = [];
        $accepted = [];

        foreach ([MatchKind::Complement, MatchKind::Alternative] as $kind) {
            $list = $answer[$kind->value.'s'] ?? [];
            $seen = [];
            $position = 0;

            foreach (is_array($list) ? $list : [] as $pick) {
                $ref = is_array($pick) ? trim((string) ($pick['ref'] ?? '')) : (is_string($pick) ? trim($pick) : '');

                if ($ref === '' || isset($seen[$ref])) {
                    continue;
                }

                $seen[$ref] = true;
                $candidate = $candidates[$ref] ?? null;
                $productId = $candidate['product_id'] ?? null;

                $because = match (true) {
                    $candidate === null => self::UNKNOWN,
                    isset($chosen[$productId]) => self::BOTH_KINDS,
                    ! ($available[$productId] ?? false) => self::UNAVAILABLE,
                    $kind === MatchKind::Alternative && (float) ($candidate['signals']['similarity'] ?? 0) < $minSimilarity => self::NOT_SIMILAR,
                    ($accepted[$kind->value] ?? 0) >= ($limits[$kind->value] ?? 0) => self::OVER_LIMIT,
                    default => null,
                };

                if ($because === null) {
                    $chosen[$productId] = true;
                    $accepted[$kind->value] = ($accepted[$kind->value] ?? 0) + 1;
                }

                $verdicts[] = [
                    'kind' => $kind,
                    'ref' => mb_substr($ref, 0, 20),
                    'product_id' => $productId,
                    'status' => $because === null ? MatchStatus::Accepted : MatchStatus::Rejected,
                    'rejected_because' => $because,
                    'position' => $position++,
                    'reason' => self::reason(is_array($pick) ? ($pick['why'] ?? null) : null),
                    'signals' => $candidate['signals'] ?? null,
                ];
            }
        }

        return $verdicts;
    }

    private static function reason(mixed $why): ?string
    {
        $why = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_scalar($why) ? (string) $why : '')));

        return $why === '' ? null : mb_substr($why, 0, self::REASON_CHARS);
    }
}
