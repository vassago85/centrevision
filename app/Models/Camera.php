<?php

namespace App\Models;

use App\Enums\CameraRole;
use App\Enums\IngestionMode;
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
use Illuminate\Support\Str;

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
 * @property IngestionMode $ingestion_mode
 * @property string|null $webhook_secret
 * @property CarbonInterface|null $last_event_at
 * @property CarbonInterface|null $last_probe_ok_at
 * @property string|null $last_probe_error
 * @property CarbonInterface|null $webhook_last_seen_at
 */
#[Fillable([
    'site_id', 'name', 'role', 'ip_address', 'isapi_username',
    'isapi_password', 'channel_id', 'is_active', 'ingestion_mode',
    'webhook_secret',
])]
// isapi_password AND webhook_secret are both device credentials; neither should
// ever land in an API response or serialised model.
#[Hidden(['isapi_password', 'webhook_secret'])]
#[ScopedBy(SiteScope::class)]
class Camera extends Model implements SiteScoped
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory, ScopedToSite;

    /**
     * A camera in webhook or auto mode must always have a shared secret; the
     * incoming request has nothing else to authenticate against. This hook
     * fires on both create and update so switching an existing stream-mode
     * camera to webhook mode from the UI never leaves it un-authenticatable.
     * Callers can supply their own secret first for tests and seeds.
     */
    protected static function booted(): void
    {
        static::saving(function (self $camera): void {
            $needsSecret = in_array(
                $camera->ingestion_mode ?? IngestionMode::Webhook,
                [IngestionMode::Webhook, IngestionMode::Auto],
                strict: true,
            );

            if ($needsSecret && ($camera->webhook_secret === null || $camera->webhook_secret === '')) {
                $camera->webhook_secret = self::generateWebhookSecret();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => CameraRole::class,
            'ingestion_mode' => IngestionMode::class,
            'isapi_password' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'channel_id' => 'integer',
            'is_active' => 'boolean',
            'last_event_at' => 'datetime',
            'last_probe_ok_at' => 'datetime',
            'webhook_last_seen_at' => 'datetime',
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
     * The URL the camera should POST HTTP Listening events to. Camera-side
     * config also needs Basic Auth with username = camera id, password =
     * webhook_secret.
     */
    public function webhookUrl(): string
    {
        return url("/webhooks/hik/{$this->getKey()}");
    }

    /**
     * A self-authenticating URL variant with the secret baked into the path.
     *
     * Some Hikvision firmwares ship an "Alarm Server" configuration screen
     * that only exposes a URL field — no username/password — so we cannot
     * rely on HTTP Basic on those cameras. Handing the operator a URL that
     * carries the secret means the same webhook still works with zero auth
     * fields on the camera side, and the middleware validates the token in
     * exactly the same way it validates a Basic password.
     *
     * The secret still only ever travels over TLS to the reverse proxy.
     */
    public function webhookUrlWithToken(): string
    {
        $secret = (string) $this->webhook_secret;

        return url("/webhooks/hik/{$this->getKey()}/".rawurlencode($secret));
    }

    /**
     * Regenerate the shared secret and persist. Returns the fresh plaintext
     * so it can be shown to the operator exactly once; on subsequent renders
     * it is only available via the encrypted cast.
     */
    public function regenerateWebhookSecret(): string
    {
        $secret = self::generateWebhookSecret();

        $this->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    /**
     * A 32-byte URL-safe token, generated the same way regardless of caller.
     */
    public static function generateWebhookSecret(): string
    {
        return Str::random(48);
    }

    /**
     * A camera is considered reachable if it produced an event, answered a
     * probe, or sent a webhook within the staleness window. Webhook cameras
     * never answer probes (they only speak outbound), so this is the only
     * signal we have for them.
     */
    public function isReachable(): bool
    {
        $threshold = now()->subMinutes((int) config('trafficflow.camera_stale_after_minutes'));

        return ($this->last_event_at !== null && $this->last_event_at->greaterThan($threshold))
            || ($this->last_probe_ok_at !== null && $this->last_probe_ok_at->greaterThan($threshold))
            || ($this->webhook_last_seen_at !== null && $this->webhook_last_seen_at->greaterThan($threshold));
    }
}
