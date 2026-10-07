<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The tags one page offers, as written and checked at night.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $page_type product or content
 * @property string $external_id
 * @property string $title the page's title when the tags were written
 * @property list<array{label: string, query: string, reason?: string}> $tags accepted, best first
 * @property list<string> $hidden_labels labels the store team took off this page
 * @property string $fingerprint the page's words and the prompt version the tags were written for
 * @property string|null $written_by
 * @property string|null $checked_by
 */
class SearchPageTags extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'search_page_tags';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'hidden_labels' => 'array',
        ];
    }

    /** @return list<array{label: string, query: string}> what the page shows */
    public function shown(): array
    {
        $hidden = (array) $this->hidden_labels;

        return array_values(array_filter((array) $this->tags, fn (array $tag): bool => ! in_array($tag['label'], $hidden, true)));
    }
}
