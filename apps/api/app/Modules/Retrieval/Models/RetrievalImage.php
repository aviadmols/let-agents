<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The vector of one product's main picture.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $external_id
 * @property string $title
 * @property string $image_url
 * @property string $url_hash
 * @property string|null $embedding_model
 * @property int|null $dimensions
 * @property string|null $error why the picture could not be read: too_big, not_image, unreachable
 * @property Carbon|null $embedded_at
 * @property string|null $embedding
 */
class RetrievalImage extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    /** @return list<float>|null */
    public function vector(): ?array
    {
        $vector = $this->embedding === null ? null : json_decode((string) $this->embedding, true);

        return is_array($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
    }
}
