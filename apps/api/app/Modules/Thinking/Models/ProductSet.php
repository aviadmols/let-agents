<?php

namespace App\Modules\Thinking\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A set of products a question asked to see, chosen by meaning (FindProductSet), kept so the same
 * question with the same products to choose from asks no model again.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $subject_key what was asked to see, its words sorted
 * @property string $subject
 * @property string $fingerprint the candidates it was chosen from and how (prompt versions)
 * @property string|null $title the set's name, as the shopper reads it
 * @property list<string> $product_ids external ids, best first; empty when none fit
 * @property list<string>|null $near_ids when none fit exactly: the nearest the store has, best first
 * @property string|null $near_title
 * @property array{question: string, options: list<string>}|null $ask one question to narrow a request too broad to choose well
 * @property list<string> $candidate_ids
 * @property string|null $chosen_by
 * @property string|null $run_id
 */
class ProductSet extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'thinking_product_sets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'near_ids' => 'array',
            'ask' => 'array',
            'candidate_ids' => 'array',
        ];
    }
}
