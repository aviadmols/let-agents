<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The vector of one thing shoppers typed into a shop's search, kept so the same words are
 * embedded once.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $embedding_model
 * @property string $text_hash
 * @property string $text
 * @property int $dimensions
 * @property int $uses
 * @property Carbon|null $last_used_at
 * @property string $embedding
 */
class RetrievalQueryVector extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'uses' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return list<float>|null */
    public function vector(): ?array
    {
        $vector = json_decode((string) $this->embedding, true);

        return is_array($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
    }
}
