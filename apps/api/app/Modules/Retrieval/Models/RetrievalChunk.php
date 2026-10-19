<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A piece of what one product, page, post or purchase record says, and its vector.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $source product, content, purchases, or a key another module registered
 * @property string $source_id the catalogue row it was built from
 * @property string|null $external_id the store's own ID for it
 * @property string $title
 * @property int $position 0 for the first piece of a source
 * @property string $text
 * @property string $text_hash
 * @property string|null $embedding_model
 * @property int|null $dimensions
 * @property string|null $embedding "[0.1,0.2,...]", pgvector's text form; read it with vector()
 * @property Carbon|null $embedded_at
 * @property string|null $topics what a section of a page or post is about, " returns shipping " (Topics)
 * @property string|null $ask_embedding the vector of the questions the section answers, same model as embedding
 * @property string|null $asks_hash the model and the questions ask_embedding was made from
 */
class RetrievalChunk extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['embedding', 'ask_embedding'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    /** @return list<float>|null */
    public function vector(): ?array
    {
        $vector = $this->embedding === null ? null : json_decode((string) $this->embedding, true);

        return is_array($vector) ? array_map('floatval', $vector) : null;
    }

    /** @return list<float>|null the vector of the questions the section answers */
    public function askVector(): ?array
    {
        $vector = $this->ask_embedding === null ? null : json_decode((string) $this->ask_embedding, true);

        return is_array($vector) ? array_map('floatval', $vector) : null;
    }
}
