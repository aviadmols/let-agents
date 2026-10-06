<?php

namespace App\Modules\Improvement\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something the daily review proposes for the site.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $review_id
 * @property string $kind synonym, faq or content_gap
 * @property string $title
 * @property array<string, mixed> $detail synonym: term, means. faq: question, answer. content_gap: topic, why.
 * @property array<string, mixed> $evidence what shoppers did that led to it
 * @property string $fingerprint the same proposal on another day has the same one
 * @property string $status pending, applied, approved, rejected, or refused by the second model
 * @property string $proposed_by provider and model
 * @property string|null $audited_by
 * @property string|null $audit_reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
class ImprovementProposal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const SYNONYM = 'synonym';

    public const FAQ = 'faq';

    public const CONTENT_GAP = 'content_gap';

    public const KINDS = [self::SYNONYM, self::FAQ, self::CONTENT_GAP];

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REFUSED = 'refused';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'evidence' => 'array', 'decided_at' => 'datetime'];
    }
}
