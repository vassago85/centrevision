<?php

namespace App\Models;

use App\Enums\WatchlistKind;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\PlateDataPolicy;
use Carbon\CarbonInterface;
use Database\Factories\WatchlistPlateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plate that a client has explicitly added to their watchlist.
 *
 * @property int $id
 * @property int $site_id
 * @property string $plate_number
 * @property WatchlistKind $kind
 * @property string|null $reason
 * @property CarbonInterface|null $expires_at
 * @property int|null $added_by_user_id
 */
#[Fillable(['site_id', 'plate_number', 'kind', 'reason', 'expires_at', 'added_by_user_id'])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(PlateDataPolicy::class)]
class WatchlistPlate extends Model implements SiteScoped
{
    /** @use HasFactory<WatchlistPlateFactory> */
    use HasFactory, ScopedToSite;

    /**
     * POPIA — a watchlist entry is by definition tied to a specific vehicle,
     * so its plate is personally identifiable and stays out of serialised
     * output. The kind and reason can be logged; the plate cannot.
     *
     * @var list<string>
     */
    protected $hidden = ['plate_number'];

    protected function casts(): array
    {
        return [
            'kind' => WatchlistKind::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<WatchlistPlate>  $query
     * @return Builder<WatchlistPlate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
