<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A tag the bank lacks, proposed while products were tagged, kept once per group and wording.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $group_id
 * @property string $label_he
 * @property string $label_en
 * @property string $norm
 * @property list<array{0: string, 1: string}> $subjects [type, id] of what it was proposed for
 * @property string|null $why
 * @property string $status open | added | merged | rejected
 * @property string|null $tag_id the tag it became or was merged into
 * @property string|null $decision_note
 */
class TaxonomyProposal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const OPEN = 'open';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['subjects' => 'array'];
    }
}
