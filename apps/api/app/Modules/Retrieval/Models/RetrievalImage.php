<?php

namespace App\Modules\Retrieval\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Support\PictureTrim;
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
 * @property list<string>|null $caption_words
 * @property list<string>|null \ what is drawn, printed or written on the product, apart from its words (caption v8)
 * @property array{name?: string, covers: string}|null $caption_pattern the print on it and how much it covers: all, most, part, detail, none (caption v8)
 */
class RetrievalImage extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The picture preparation a vector is made from now (the plain background trimmed). */
    public const PREP_CURRENT = PictureTrim::VERSION;

    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'embedded_at' => 'datetime',
            'captioned_at' => 'datetime',
            'caption_words' => 'array',
            'caption_depicted' => 'array',
            'caption_pattern' => 'array',
        ];
    }

    /**
     * Pictures of the current shop still waiting: not yet embedded from a picture prepared the
     * current way, or (when descriptions are on) not described under the current description.
     * Unreadable ones are not waiting. Gallery pictures count as well. Inside the shop's tenant.
     */
    public static function leftToDo(bool $captions, string $captionModel): int
    {
        $left = 0;

        foreach (self::query()->whereNull('error')->get(['id', 'url_hash', 'embedded_at', 'prep', 'caption_key']) as $image) {
            $embedded = $image->embedded_at !== null && (int) $image->prep === PictureTrim::VERSION;
            $described = ! $captions || $image->caption_key === CaptionImages::key((string) $image->url_hash, $captionModel);
            $left += $embedded && $described ? 0 : 1;
        }

        return $left + RetrievalGalleryImage::query()->whereNull('error')
            ->where(fn ($q) => $q->whereNull('embedded_at')->orWhere('prep', '!=', PictureTrim::VERSION)->orWhereNull('prep'))
            ->count();
    }

    /** @return list<float>|null */
    public function vector(): ?array
    {
        $vector = $this->embedding === null ? null : json_decode((string) $this->embedding, true);

        return is_array($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
    }
}
