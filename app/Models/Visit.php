<?php

namespace App\Models;

use App\Enums\PlateTagType;
use App\Enums\VisitStatus;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\PlateDataPolicy;
use Carbon\CarbonInterface;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vehicle's stay at a site, built by pairing an entry event with an exit.
 *
 * @property int $id
 * @property int $site_id
 * @property string $plate_number
 * @property int|null $entry_event_id
 * @property int|null $exit_event_id
 * @property CarbonInterface $entered_at
 * @property CarbonInterface|null $exited_at
 * @property int|null $dwell_minutes
 * @property VisitStatus $status
 */
#[Fillable([
    'site_id', 'plate_number', 'entry_event_id', 'exit_event_id',
    'entered_at', 'exited_at', 'dwell_minutes', 'status',
])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(PlateDataPolicy::class)]
class Visit extends Model implements SiteScoped
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory, ScopedToSite;

    /**
     * POPIA: kept out of anything serialised. See PlateEvent.
     *
     * @var list<string>
     */
    protected $hidden = ['plate_number'];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'dwell_minutes' => 'integer',
            'status' => VisitStatus::class,
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
     * @return BelongsTo<PlateEvent, $this>
     */
    public function entryEvent(): BelongsTo
    {
        return $this->belongsTo(PlateEvent::class, 'entry_event_id');
    }

    /**
     * @return BelongsTo<PlateEvent, $this>
     */
    public function exitEvent(): BelongsTo
    {
        return $this->belongsTo(PlateEvent::class, 'exit_event_id');
    }

    /**
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', VisitStatus::Open);
    }

    /**
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', VisitStatus::Closed);
    }

    /**
     * Drop plates tagged as staff or tenant patterns. Every shopper-facing
     * metric must apply this; the Security views deliberately must not.
     *
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeExcludingRecurring(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('plate_tags')
                ->whereColumn('plate_tags.site_id', 'visits.site_id')
                ->whereColumn('plate_tags.plate_number', 'visits.plate_number')
                ->where('plate_tags.tag', PlateTagType::RecurringPattern->value);
        });
    }

    /**
     * The inverse of excludingRecurring — staff and tenant-pattern plates
     * only. Used by the Reports "Staff / regular" audience filter.
     *
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeOnlyRecurring(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('plate_tags')
                ->whereColumn('plate_tags.site_id', 'visits.site_id')
                ->whereColumn('plate_tags.plate_number', 'visits.plate_number')
                ->where('plate_tags.tag', PlateTagType::RecurringPattern->value);
        });
    }

    /**
     * @param  Builder<Visit>  $query
     * @return Builder<Visit>
     */
    public function scopeEnteredBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('entered_at', [$from, $to]);
    }

    /**
     * Minutes on site so far, for visits that have not closed yet.
     */
    public function minutesOnSite(): int
    {
        return $this->dwell_minutes ?? (int) $this->entered_at->diffInMinutes(now());
    }
}
