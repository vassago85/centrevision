<?php

namespace App\Models;

use App\Enums\PlateTagType;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\PlateDataPolicy;
use Carbon\CarbonInterface;
use Database\Factories\PlateTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plate-level classification. Deliberately holds no name or personal profile
 * beyond the plate string itself.
 *
 * @property int $id
 * @property int $site_id
 * @property string $plate_number
 * @property PlateTagType $tag
 * @property CarbonInterface $tagged_at
 * @property array<string, mixed>|null $evidence
 */
#[Fillable(['site_id', 'plate_number', 'tag', 'tagged_at', 'evidence'])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(PlateDataPolicy::class)]
class PlateTag extends Model implements SiteScoped
{
    /** @use HasFactory<PlateTagFactory> */
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
            'tag' => PlateTagType::class,
            'tagged_at' => 'datetime',
            'evidence' => 'array',
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
     * @param  Builder<PlateTag>  $query
     * @return Builder<PlateTag>
     */
    public function scopeOfType(Builder $query, PlateTagType $tag): Builder
    {
        return $query->where('tag', $tag);
    }
}
