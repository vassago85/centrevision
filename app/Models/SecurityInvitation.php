<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SecurityInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An owner's invitation for a security operator (guard, guardhouse staff)
 * to join their organization.
 *
 * Distinct from ShopInvitation because operators are not site-scoped: they
 * work for the owner across every site, whereas a shop is tied to a
 * specific mall. Overloading a single "invitation" table with a nullable
 * `site_id` would have made every existing shop query more brittle for
 * questionable savings.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $email
 * @property string $token
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $user_id
 */
#[Fillable([
    'organization_id', 'name', 'email', 'token',
    'expires_at', 'accepted_at', 'user_id',
])]
class SecurityInvitation extends Model
{
    /** @use HasFactory<SecurityInvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<SecurityInvitation>  $query
     * @return Builder<SecurityInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
