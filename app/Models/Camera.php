<?php

namespace App\Models;

use App\Enums\CameraRole;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use Carbon\CarbonInterface;
use Database\Factories\CameraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Hikvision LPR camera feeding plate events for a site.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property CameraRole $role
 * @property string $ip_address
 * @property string|null $isapi_username
 * @property string|null $isapi_password
 * @property int $channel_id
 * @property bool $is_active
 * @property CarbonInterface|null $last_event_at
 * @property CarbonInterface|null $last_probe_ok_at
 * @property string|null $last_probe_error
 */
#[Fillable([
    'site_id', 'name', 'role', 'ip_address', 'isapi_username',
    'isapi_password', 'channel_id', 'is_active',
])]
#[Hidden(['isapi_password'])]
#[ScopedBy(SiteScope::class)]
class Camera extends Model implements SiteScoped
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory, ScopedToSite;

    protected function casts(): array
    {
        return [
            'role' => CameraRole::class,
            'isapi_password' => 'encrypted',
            'channel_id' => 'integer',
            'is_active' => 'boolean',
            'last_event_at' => 'datetime',
            'last_probe_ok_at' => 'datetime',
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
     * @return HasMany<PlateEvent, $this>
     */
    public function plateEvents(): HasMany
    {
        return $this->hasMany(PlateEvent::class);
    }

    /**
     * The ISAPI alert stream endpoint this camera is listened to on.
     */
    public function alertStreamUrl(): string
    {
        return "http://{$this->ip_address}/ISAPI/Event/notification/alertStream";
    }

    /**
     * A cheap endpoint used to check the camera answers before opening the
     * long-lived alert stream.
     */
    public function probeUrl(): string
    {
        return "http://{$this->ip_address}/ISAPI/System/deviceInfo";
    }

    /**
     * A camera is considered reachable if it produced an event or answered a
     * probe within the staleness window.
     */
    public function isReachable(): bool
    {
        $threshold = now()->subMinutes((int) config('trafficflow.camera_stale_after_minutes'));

        return ($this->last_event_at !== null && $this->last_event_at->greaterThan($threshold))
            || ($this->last_probe_ok_at !== null && $this->last_probe_ok_at->greaterThan($threshold));
    }
}
