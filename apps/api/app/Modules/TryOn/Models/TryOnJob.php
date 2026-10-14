<?php

namespace App\Modules\TryOn\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One product tried on one photo: its stages, the picture it made, and what it cost.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $photo_id
 * @property string|null $product_id null only in the team's test of a product the catalog has not read
 * @property array{external_id: string, title: string, image: string}|null $page_product
 * @property string $visitor_hash
 * @property string $tryon_class
 * @property string $group
 * @property array<string, string>|null $answers
 * @property string $fingerprint
 * @property string $status
 * @property string|null $stage
 * @property string|null $failure
 * @property int $attempts
 * @property string|null $result_path
 * @property string $cost_usd
 * @property string|null $run_id
 * @property Carbon|null $finished_at
 */
class TryOnJob extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const QUEUED = 'queued';

    public const WORKING = 'working';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Never more than two pictures for one try: the first and one more after the checker's correction. */
    public const MAX_ATTEMPTS = 2;

    /** A try not done after this many minutes ends as failed (timed_out), so nothing waits on it forever. */
    public const MAX_MINUTES = 6;

    /** Still waited on (queued or working) but past its time: ended now as failed. True when it was. */
    public function endIfStuck(): bool
    {
        if (! in_array($this->status, [self::QUEUED, self::WORKING], true) || $this->created_at === null || $this->created_at->gt(now()->subMinutes(self::MAX_MINUTES))) {
            return false;
        }

        $this->update(['status' => self::FAILED, 'failure' => 'timed_out', 'stage' => null, 'finished_at' => now()]);

        return true;
    }

    protected $table = 'try_on_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'page_product' => 'array', 'attempts' => 'integer', 'finished_at' => 'datetime'];
    }

    /** The product's name: the catalog's, or the page's in the team's test of a product not read yet. */
    public function productTitle(): string
    {
        return (string) ($this->product?->title ?? $this->page_product['title'] ?? '');
    }

    /** The product's picture, the same way. */
    public function productImage(): ?string
    {
        $url = $this->product?->image_url ?? $this->page_product['image'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** @return BelongsTo<TryOnPhoto, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(TryOnPhoto::class, 'photo_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** @return HasMany<TryOnStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(TryOnStep::class, 'job_id');
    }
}
