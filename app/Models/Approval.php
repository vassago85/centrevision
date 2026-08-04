<?php

namespace App\Models;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

/**
 * A pending or resolved decision that requires platform sign-off.
 *
 * @property int $id
 * @property ApprovalKind $kind
 * @property ApprovalStatus $status
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property int|null $requested_by_user_id
 * @property int|null $reviewed_by_user_id
 * @property \Carbon\CarbonInterface|null $reviewed_at
 * @property string|null $review_note
 */
#[Fillable([
    'kind', 'status', 'subject_type', 'subject_id', 'payload',
    'requested_by_user_id', 'reviewed_by_user_id', 'reviewed_at', 'review_note',
])]
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * Sign the approval off. The kind-specific side effect (unlocking the
     * owner org, etc.) is applied by the caller — this method only records
     * the decision so the audit trail is consistent regardless of what the
     * caller does next.
     */
    public function approve(User $reviewer, ?string $note = null): void
    {
        $this->assertPending();

        $this->forceFill([
            'status' => ApprovalStatus::Approved,
            'reviewed_by_user_id' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
    }

    public function reject(User $reviewer, ?string $note = null): void
    {
        $this->assertPending();

        $this->forceFill([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by_user_id' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
    }

    /**
     * Prevents double-signing: once decided, an approval is frozen. A
     * platform admin who wants to change their mind can create a new
     * approval rather than overwriting the old one, which keeps the audit
     * trail honest.
     */
    protected function assertPending(): void
    {
        if (! $this->isPending()) {
            throw new InvalidArgumentException(
                "Approval #{$this->getKey()} has already been {$this->status->value}.",
            );
        }
    }
}
