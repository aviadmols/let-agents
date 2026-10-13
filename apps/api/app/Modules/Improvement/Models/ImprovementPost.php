<?php

namespace App\Modules\Improvement\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogContent;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A post a model drafted for a missing topic, as the team left it: a draft to edit, or
 * published as a guide of the store's own.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $proposal_id
 * @property string $title
 * @property string $body markdown
 * @property list<string> $products the store's products it is about, by external id
 * @property string $status draft, published or unpublished
 * @property bool $checked whether the second model found nothing wrong
 * @property list<string> $problems what it found
 * @property string $written_by
 * @property string|null $checked_by
 * @property string|null $run_id
 * @property Carbon|null $published_at
 * @property array{seo_title?: string, meta_description?: string, focus_keyword?: string, excerpt?: string, tags?: list<string>, faq?: list<array{q: string, a: string}>}|null $seo
 * @property array{platform?: string, id?: string, url?: string, admin_url?: string, status?: string, error?: string, at?: string}|null $remote where it went on the store's own site
 */
class ImprovementPost extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['products' => 'array', 'checked' => 'boolean', 'problems' => 'array', 'published_at' => 'datetime', 'seo' => 'array', 'remote' => 'array'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ImprovementProposal::class, 'proposal_id');
    }

    /** The id the post carries among the store's content, in the catalog and the search. */
    public function contentId(): string
    {
        return CatalogContent::OWN_PREFIX.$this->id;
    }
}
