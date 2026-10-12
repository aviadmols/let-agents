<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\Verdict;
use App\Modules\Enrichment\Scanning\Measurement;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shop_id
 * @property string|null $product_id
 * @property string|null $content_id
 * @property string|null $vocabulary_id
 * @property FactKind $kind
 * @property string $key
 * @property string|null $value_number
 * @property string|null $value_text
 * @property string|null $unit
 * @property string|null $quote
 * @property FactOrigin $origin
 * @property FactStatus $status
 * @property string|null $status_reason
 * @property string|null $batch_id
 * @property string $input_hash
 * @property string|null $model
 * @property Verdict|null $review_verdict
 * @property string|null $review_model
 * @property int $review_tier
 * @property string|null $review_batch_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
class EnrichmentFact extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => FactKind::class,
            'origin' => FactOrigin::class,
            'status' => FactStatus::class,
            'review_verdict' => Verdict::class,
            'review_tier' => 'integer',
            'decided_at' => 'datetime',
            'confidence' => 'float',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** @return BelongsTo<CatalogContent, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(CatalogContent::class, 'content_id');
    }

    /** @return BelongsTo<EnrichmentVocabulary, $this> */
    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(EnrichmentVocabulary::class, 'vocabulary_id');
    }

    /** @return BelongsTo<EnrichmentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EnrichmentBatch::class, 'batch_id');
    }

    /** @param Builder<EnrichmentFact> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', FactStatus::Superseded);
    }

    public function subjectTitle(): string
    {
        return (string) ($this->product?->title ?? $this->content?->title ?? '');
    }

    /** The key in the viewer's language, from the vocabulary when there is one. */
    public function keyLabel(): string
    {
        $locale = app()->getLocale();
        $vocabulary = $this->vocabulary?->definition();

        return match ($this->kind) {
            FactKind::Type => __('enrichment::enrichment.fact_kinds.type'),
            FactKind::Tag => __('enrichment::enrichment.fact_kinds.tag'),
            FactKind::Spec, FactKind::Choice, FactKind::Flag => $vocabulary?->label('attribute', $this->key, $locale) ?? $this->key,
            default => $this->kind->label(),
        };
    }

    /** The value in the viewer's language: "190.5 mm", "Cordless", "Jigsaw". */
    public function valueLabel(): string
    {
        $locale = app()->getLocale();
        $vocabulary = $this->vocabulary?->definition();

        return match ($this->kind) {
            FactKind::Spec => self::readable((float) $this->value_number, (string) $this->unit),
            FactKind::Type => $vocabulary?->label('type', (string) $this->value_text, $locale) ?? (string) $this->value_text,
            FactKind::Tag => $vocabulary?->label('tag', (string) $this->value_text, $locale) ?? (string) $this->value_text,
            FactKind::Choice => $vocabulary?->label('attribute', $this->key, $locale, (string) $this->value_text) ?? (string) $this->value_text,
            FactKind::Flag => __('enrichment::enrichment.values.yes'),
            FactKind::ContentKind => __("enrichment::enrichment.content_kinds.{$this->value_text}"),
            FactKind::ShopperValue => __("enrichment::enrichment.shopper_values.{$this->value_text}"),
            FactKind::Category => (string) $this->quote,
            // A highlight or a promise is already a sentence; a promise with no detail of its
            // own ("hand made") shows the sentence it was read from. Never throw on a new kind.
            default => (string) ($this->value_text ?? $this->quote ?? ''),
        };
    }

    /**
     * A number in the unit a shopper reads it in: a ring of 0.00345 kg is 3.45 g. Stored in the
     * dimension's own unit, so values still compare.
     */
    public static function readable(float $value, string $unit): string
    {
        [$value, $unit] = match (true) {
            $unit === 'kg' && $value > 0 && $value < 1 => [$value * 1000, 'g'],
            default => [$value, $unit],
        };

        return Measurement::compact(round($value, 3)).' '.$unit;
    }
}
