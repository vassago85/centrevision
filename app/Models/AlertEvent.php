<?php

namespace App\Models;

use App\Enums\AlertEventStatus;
use App\Enums\AlertRule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single security alert firing (email sent, deferred, or suppressed).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $site_id
 * @property AlertRule $rule
 * @property string $plate_number
 * @property int|null $visit_id
 * @property int|null $watchlist_plate_id
 * @property string $fingerprint
 * @property AlertEventStatus $status
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface $detected_at
 * @property CarbonInterface|null $send_after
 * @property CarbonInterface|null $sent_at
 * @property string|null $error
 */
#[Fillable([
    'organization_id', 'site_id', 'rule', 'plate_number', 'visit_id',
    'watchlist_plate_id', 'fingerprint', 'status', 'payload', 'detected_at',
    'send_after', 'sent_at', 'error',
])]
class AlertEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = ['plate_number'];

    protected function casts(): array
    {
        return [
            'rule' => AlertRule::class,
            'status' => AlertEventStatus::class,
            'payload' => 'array',
            'detected_at' => 'datetime',
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return BelongsTo<WatchlistPlate, $this>
     */
    public function watchlistPlate(): BelongsTo
    {
        return $this->belongsTo(WatchlistPlate::class);
    }
}
