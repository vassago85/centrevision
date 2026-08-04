<?php

namespace App\Models;

use App\Enums\ReportSchedule;
use App\Models\Concerns\ScopedToSite;
use App\Models\Contracts\SiteScoped;
use App\Models\Scopes\SiteScope;
use App\Policies\SitePolicy;
use Carbon\CarbonInterface;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single property (mall) belonging to an owner organization.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $address
 * @property array<string, mixed>|null $settings
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'address', 'settings'])]
#[ScopedBy(SiteScope::class)]
#[UsePolicy(SitePolicy::class)]
class Site extends Model implements SiteScoped
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, ScopedToSite;

    /**
     * Per-site operational settings. Any key absent from the stored JSON falls
     * back to these, which in turn fall back to config('trafficflow').
     */
    public const DEFAULT_SETTINGS = [
        'dwell_alert_hours' => null,
        'orphan_after_hours' => null,
        'retention_days' => null,
        'recurring_window_days' => null,
        'recurring_min_weekday_ratio' => null,
        'recurring_max_arrival_stddev_minutes' => null,
        'report_schedule' => null,
        'report_recipients' => [],
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Attach a fresh subscription so metered billing has something to write
     * lines against. Called explicitly by the Sites page when an owner adds
     * a site through the UI — we don't hook it to a model event because
     * seeders and factories already create their own SiteSubscription rows
     * with specific tiers and caps and we don't want to duplicate that path.
     *
     * Idempotent: safe to call twice for the same site.
     */
    public function attachDefaultSubscription(): SiteSubscription
    {
        return SiteSubscription::firstOrCreate(
            ['site_id' => $this->getKey()],
            [
                // The stored tier is a placeholder; BillingCalculator recomputes
                // from the live camera count every run, so the site starts
                // pointing at Starter and moves up organically as cameras arrive.
                'base_tier' => \App\Enums\BaseTier::Starter,
                'base_fee' => 0,
                'variable_rate_per_camera_per_subuser' => (float) config('trafficflow.variable_rate_per_camera_per_subuser'),
                'status' => \App\Enums\SubscriptionStatus::Active,
                'current_period_ends_at' => now()->endOfMonth(),
            ],
        );
    }

    /**
     * Site is scoped on its own primary key rather than a site_id column.
     */
    public function siteScopeColumn(): string
    {
        return $this->getKeyName();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Camera, $this>
     */
    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }

    /**
     * True when at least one camera on the site is capable of reporting
     * vehicles leaving (either an exit camera or a bidirectional one). A
     * site with only entrance cameras cannot produce dwell times or an
     * accurate "on site now" count — the dashboard reshapes itself to be
     * honest about that instead of showing figures that will never move.
     */
    public function hasExitTracking(): bool
    {
        return $this->cameras()
            ->whereIn('role', [\App\Enums\CameraRole::Exit->value, \App\Enums\CameraRole::Both->value])
            ->exists();
    }

    /**
     * @return HasManyThrough<PlateEvent, Camera, $this>
     */
    public function plateEvents(): HasManyThrough
    {
        return $this->hasManyThrough(PlateEvent::class, Camera::class);
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return HasMany<PlateTag, $this>
     */
    public function plateTags(): HasMany
    {
        return $this->hasMany(PlateTag::class);
    }

    /**
     * Shop organizations trading inside this site.
     *
     * @return HasMany<Organization, $this>
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_site_id');
    }

    /**
     * @return HasOne<SiteSubscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(SiteSubscription::class);
    }

    /**
     * @return HasMany<ShopInvitation, $this>
     */
    public function shopInvitations(): HasMany
    {
        return $this->hasMany(ShopInvitation::class);
    }

    /**
     * Resolve a setting, falling back to the application default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = data_get($this->settings, $key);

        if ($value !== null) {
            return $value;
        }

        return $default ?? config("trafficflow.{$key}");
    }

    public function dwellAlertHours(): int
    {
        return (int) $this->setting('dwell_alert_hours', config('trafficflow.dwell_alert_hours'));
    }

    public function orphanAfterHours(): int
    {
        return (int) $this->setting('orphan_after_hours', config('trafficflow.orphan_after_hours'));
    }

    public function retentionDays(): int
    {
        return (int) $this->setting('retention_days', config('trafficflow.retention_days'));
    }

    public function reportSchedule(): ReportSchedule
    {
        return ReportSchedule::tryFrom((string) $this->setting('report_schedule', config('trafficflow.report_schedule')))
            ?? ReportSchedule::Off;
    }

    /**
     * @return array<int, string>
     */
    public function reportRecipients(): array
    {
        return array_values(array_filter((array) $this->setting('report_recipients', [])));
    }
}
