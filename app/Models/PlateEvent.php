<?php

namespace App\Models;

use App\Enums\PlateDirection;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\PlateDataPolicy;
use Carbon\CarbonInterface;
use Database\Factories\PlateEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single plate capture, from either the alert stream or the FTP drop sweep.
 *
 * plate_number is personal data under POPIA: never log it, and let
 * PrunePlateData remove it once the site's retention window passes.
 *
 * @property int $id
 * @property int $camera_id
 * @property string $plate_number
 * @property PlateDirection|null $direction
 * @property CarbonInterface $captured_at
 * @property float|null $confidence
 * @property array<string, mixed>|null $raw_payload
 * @property CarbonInterface|null $processed_at
 * @property string|null $original_plate_number
 */
#[Fillable([
    'camera_id', 'plate_number', 'direction', 'captured_at',
    'confidence', 'raw_payload', 'processed_at', 'original_plate_number',
])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(PlateDataPolicy::class)]
class PlateEvent extends Model implements SiteScoped
{
    /** @use HasFactory<PlateEventFactory> */
    use HasFactory, ScopedToSite;

    /**
     * POPIA: a plate is personal information, so it is kept out of anything
     * serialised. Views read the attribute directly and are unaffected.
     *
     * @var list<string>
     */
    protected $hidden = ['plate_number', 'original_plate_number', 'raw_payload'];

    protected function casts(): array
    {
        return [
            'direction' => PlateDirection::class,
            'captured_at' => 'datetime',
            'processed_at' => 'datetime',
            'confidence' => 'float',
            'raw_payload' => 'array',
        ];
    }

    /**
     * Plate events reach a site through their camera, so the tenant scope has
     * to hop one table.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  array<int, int>  $siteIds
     */
    public function applySiteScope(Builder $builder, array $siteIds): void
    {
        $builder->whereIn(
            $builder->qualifyColumn('camera_id'),
            Camera::query()
                ->withoutGlobalScope(SiteScope::class)
                ->select('id')
                ->whereIn('site_id', $siteIds),
        );
    }

    /**
     * @return BelongsTo<Camera, $this>
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    /**
     * @param  Builder<PlateEvent>  $query
     * @return Builder<PlateEvent>
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    /**
     * @param  Builder<PlateEvent>  $query
     * @return Builder<PlateEvent>
     */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->whereHas('camera', fn (Builder $camera) => $camera->where('site_id', $siteId));
    }

    /**
     * True when fuzzy matching rewrote the plate to correct an OCR misread.
     */
    public function wasFuzzyCorrected(): bool
    {
        return $this->original_plate_number !== null
            && $this->original_plate_number !== $this->plate_number;
    }
}
