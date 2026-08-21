<?php

use App\Enums\AlertEventStatus;
use App\Enums\AlertRule;
use App\Enums\WatchlistKind;
use App\Jobs\MatchVisits;
use App\Jobs\SendAlertMail;
use App\Mail\SecurityAlertMail;
use App\Models\AlertEvent;
use App\Models\Camera;
use App\Models\Organization;
use App\Models\PlateEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\WatchlistPlate;
use App\Support\Alerts\AlertEvaluator;
use App\Support\Alerts\AlertFingerprint;
use App\Support\Alerts\AlertQuietHours;
use App\Support\Alerts\AlertRecipientResolver;
use App\Support\Alerts\AlertSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = Organization::factory()->owner()->create();
    $this->site = Site::factory()->for_($this->owner)->create([
        'timezone' => 'Africa/Johannesburg',
        'settings' => [
            'alerts' => [
                'enabled' => true,
                'recipients' => ['desk@example.com'],
                'quiet_start' => null,
                'quiet_end' => null,
                'rules' => [
                    'watchlist_hit' => ['enabled' => true, 'respect_quiet' => false],
                    'dwell' => ['enabled' => true, 'respect_quiet' => true],
                    'odd_hour' => ['enabled' => true, 'respect_quiet' => true],
                    'multi_entry' => ['enabled' => true, 'respect_quiet' => true],
                ],
            ],
        ],
    ]);
    $this->camera = Camera::factory()->for($this->site)->entrance()->create();
});

it('persists an alert event with rule and status enums', function () {
    $event = AlertEvent::query()->create([
        'organization_id' => $this->owner->id,
        'site_id' => $this->site->id,
        'rule' => AlertRule::WatchlistHit,
        'plate_number' => 'BX91GP',
        'fingerprint' => $this->site->id.'|watchlist|BX91GP|1',
        'status' => AlertEventStatus::Pending,
        'payload' => ['kind' => 'block'],
        'detected_at' => now(),
    ]);

    expect($event->fresh()->rule)->toBe(AlertRule::WatchlistHit)
        ->and($event->status)->toBe(AlertEventStatus::Pending);
});

it('resolves site recipients and opted-in users', function () {
    User::factory()->ownerAdmin($this->owner)->create([
        'email' => 'owner-opted@example.com',
        'alert_email_opt_in' => true,
    ]);
    User::factory()->ownerAdmin($this->owner)->create([
        'email' => 'owner-silent@example.com',
        'alert_email_opt_in' => false,
    ]);
    User::factory()->securityOperator($this->owner)->create([
        'email' => 'guard@example.com',
    ]);

    $emails = AlertRecipientResolver::emails($this->site);

    expect($emails)->toContain('desk@example.com')
        ->toContain('owner-opted@example.com')
        ->toContain('guard@example.com')
        ->not->toContain('owner-silent@example.com');
});

it('defers send_after during quiet hours when the rule respects quiet', function () {
    $this->site->update([
        'settings' => [
            ...($this->site->settings ?? []),
            'alerts' => [
                ...($this->site->settings['alerts'] ?? []),
                'quiet_start' => '22:00',
                'quiet_end' => '06:00',
            ],
        ],
    ]);

    $settings = AlertSettings::for($this->site->fresh());
    $detected = now()->timezone('Africa/Johannesburg')->setTime(23, 30);

    $sendAfter = AlertQuietHours::sendAfter($this->site, $settings, AlertRule::Dwell, $detected);

    expect($sendAfter)->not->toBeNull()
        ->and($sendAfter->timezone('Africa/Johannesburg')->format('H:i'))->toBe('06:00');
});

it('emails on watchlist hit when a visit is matched', function () {
    Mail::fake();
    Queue::fake();

    WatchlistPlate::factory()->block()->for($this->site)->create([
        'plate_number' => 'BX91GP',
    ]);

    PlateEvent::factory()->for($this->camera)->entering(now())->plateNumber('BX91GP')->create([
        'processed_at' => null,
    ]);

    (new MatchVisits($this->site->id))->handle();

    $event = AlertEvent::query()->sole();

    expect($event->rule)->toBe(AlertRule::WatchlistHit)
        ->and($event->status)->toBe(AlertEventStatus::Pending);

    Queue::assertPushed(SendAlertMail::class);

    (new SendAlertMail($event->id))->handle();

    Mail::assertSent(SecurityAlertMail::class, function (SecurityAlertMail $mail) {
        return $mail->hasTo('desk@example.com');
    });

    expect($event->fresh()->status)->toBe(AlertEventStatus::Sent);
});

it('suppresses when there are no recipients', function () {
    $this->site->update([
        'settings' => [
            ...($this->site->settings ?? []),
            'alerts' => [
                ...($this->site->settings['alerts'] ?? []),
                'recipients' => [],
            ],
        ],
    ]);

    $event = app(AlertEvaluator::class)->record(
        $this->site->fresh(),
        AlertRule::WatchlistHit,
        'ZZ99ZZ',
        ['kind' => 'watch'],
    );

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe(AlertEventStatus::Suppressed)
        ->and($event->error)->toBe('no_recipients');
});

it('does not create alerts when master switch is off', function () {
    $this->site->update([
        'settings' => [
            ...($this->site->settings ?? []),
            'alerts' => [
                ...($this->site->settings['alerts'] ?? []),
                'enabled' => false,
            ],
        ],
    ]);

    $event = app(AlertEvaluator::class)->record(
        $this->site->fresh(),
        AlertRule::WatchlistHit,
        'ZZ99ZZ',
        ['kind' => 'watch'],
    );

    expect($event)->toBeNull()
        ->and(AlertEvent::count())->toBe(0);
});

it('dedupes dwell alerts per visit fingerprint', function () {
    $fingerprint = AlertFingerprint::make($this->site, AlertRule::Dwell, 'AA11AA', ['visit_id' => 42]);

    expect($fingerprint)->toBe($this->site->id.'|dwell|42');

    app(AlertEvaluator::class)->record(
        $this->site,
        AlertRule::Dwell,
        'AA11AA',
        ['threshold_hours' => 4],
        fingerprintContext: ['visit_id' => 42],
    );

    $second = app(AlertEvaluator::class)->record(
        $this->site,
        AlertRule::Dwell,
        'AA11AA',
        ['threshold_hours' => 4],
        fingerprintContext: ['visit_id' => 42],
    );

    expect($second)->toBeNull()
        ->and(AlertEvent::count())->toBe(1);
});
