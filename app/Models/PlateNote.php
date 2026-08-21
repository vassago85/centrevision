<?php

namespace App\Models;

use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\PlateDataPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freeform security note on a plate at a site (CRM-lite).
 *
 * @property int $id
 * @property int $site_id
 * @property string $plate_number
 * @property string $body
 * @property int|null $user_id
 */
#[Fillable(['site_id', 'plate_number', 'body', 'user_id'])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(PlateDataPolicy::class)]
class PlateNote extends Model implements SiteScoped
{
    use HasFactory, ScopedToSite;

    /** @var list<string> */
    protected $hidden = ['plate_number'];

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
