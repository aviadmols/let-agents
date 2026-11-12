<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A page or a post of the store's own site its team stopped the assistant from using: never a passage of an answer, and
 * none of the facts read from it, until the team brings it back.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $content_id
 * @property string $title
 * @property string|null $url
 * @property int|null $created_by
 */
class AssistantIgnoredSource extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    /** @return list<string> the content ids this shop stopped (call inside its tenant) */
    public static function contentIds(): array
    {
        return self::query()->pluck('content_id')->map(fn ($id): string => (string) $id)->all();
    }
}
