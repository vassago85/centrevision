<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use Carbon\CarbonInterface;
use Database\Factories\SiteDayStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-site, per-day rollup of shopper traffic + weather + holiday context.
 *
 * Built nightly by EnrichSiteDayStats. Deliberately plate-free so POPIA
 * pruning leaves it alone — historical trend context has to outlive the
 * personal-data window.
 *
 * @property int $id
 * @property int $site_id
 * @property CarbonInterface $local_date
 * @property int $visits_count
 * @property int $unique_vehicles
 * @property float|null $temp_avg_c
 * @property float|null $precip_mm
 * @property int|null $weather_code
 * @property string|null $weather_label
 * @property bool $is_public_holiday
 * @property bool $is_school_holiday
 * @property string|null $holiday_name
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'site_id', 'local_date',
    'visits_count', 'unique_vehicles',
    'temp_avg_c', 'precip_mm', 'weather_code', 'weather_label',
    'is_public_holiday', 'is_school_holiday', 'holiday_name',
])]
#[ScopedBy(SiteScope::class)]
class SiteDayStat extends Model implements SiteScoped
{
    /** @use HasFactory<SiteDayStatFactory> */
    use HasFactory, ScopedToSite;

    protected $table = 'site_day_stats';

    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'visits_count' => 'integer',
            'unique_vehicles' => 'integer',
            'temp_avg_c' => 'float',
            'precip_mm' => 'float',
            'weather_code' => 'integer',
            'is_public_holiday' => 'boolean',
            'is_school_holiday' => 'boolean',
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
     * @param  Builder<SiteDayStat>  $query
     * @return Builder<SiteDayStat>
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('local_date', [$from->toDateString(), $to->toDateString()]);
    }
}
