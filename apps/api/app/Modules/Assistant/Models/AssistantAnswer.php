<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shopper's question about a product and the answer given, kept so the next shopper who asks the
 * same gets it without a model. See Assistant\Actions\AnswerQuestion.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $question_key sha256 of the normalized question
 * @property string $question
 * @property string|null $answer
 * @property string $outcome answered, no_info or out_of_scope
 * @property string|null $source store (the product information), general (knowledge about products like it) or team
 * @property list<array{title: string, url: string|null, type: string}>|null $sources the pages a site-wide answer was written from
 * @property string $status shown or hidden
 * @property int $prompt_version
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $cost_usd
 * @property string|null $run_id
 * @property int $asked_count
 * @property Carbon $last_asked_at
 */
class AssistantAnswer extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const ANSWERED = 'answered';

    public const NO_INFO = 'no_info';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const SHOWN = 'shown';

    public const HIDDEN = 'hidden';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'asked_count' => 'integer',
            'sources' => 'array',
            'prompt_version' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'last_asked_at' => 'datetime',
        ];
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

    /** The page the question was asked on: a product, or a guide the store published. */
    public function page(): CatalogProduct|CatalogContent|null
    {
        return $this->product ?? $this->content;
    }
}
