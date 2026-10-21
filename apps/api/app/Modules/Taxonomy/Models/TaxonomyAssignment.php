<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tag on a product or a post, with where it was read from and the evidence for it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $subject_type product | content
 * @property string $subject_id
 * @property string $tag_id
 * @property string $source catalog (the store's own text and fields) | picture (what its picture shows) | knowledge (what is widely known of a well-known product or work)
 * @property string|null $evidence the words of the text, or what the picture shows, or what is known
 * @property float $confidence
 * @property float $weight how central it is to the product: 1 what it is mainly about, about .3 in the background
 * @property bool $explicit its text says the tag itself, rather than only what justifies it
 * @property string $status kept | review | rejected
 * @property string|null $checked held | taken_out: what the model of the other family said
 * @property string|null $check_note
 * @property string $input_hash what the product was when it was tagged
 * @property int $bank_version
 * @property string|null $run_id
 */
class TaxonomyAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const PRODUCT = 'product';

    public const CONTENT = 'content';

    public const KEPT = 'kept';

    public const REVIEW = 'review';

    public const REJECTED = 'rejected';

    public const SOURCES = ['catalog', 'picture', 'knowledge'];

    /** checked: what the team said of a tag (kept or taken out), which tagging again and the rules never undo */
    public const TEAM_KEPT = 'team';

    public const TEAM_OUT = 'team_out';

    public const TEAM = [self::TEAM_KEPT, self::TEAM_OUT];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'weight' => 'float', 'explicit' => 'boolean', 'bank_version' => 'integer'];
    }

    /** @return BelongsTo<TaxonomyTag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTag::class, 'tag_id');
    }
}
