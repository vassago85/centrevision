<?php

namespace App\Support\Alerts;

use App\Enums\AlertEventStatus;
use App\Enums\AlertRule;
use App\Enums\CameraRole;
use App\Enums\PlateDirection;
use App\Enums\VisitStatus;
use App\Jobs\SendAlertMail;
use App\Models\AlertEvent;
use App\Models\Camera;
use App\Models\PlateEvent;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WatchlistPlate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;

final class AlertEvaluator
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array{visit_id?: int|null, window_start?: string|null}  $fingerprintContext
     */
    public function record(
        Site $site,
        AlertRule $rule,
        string $plate,
        array $payload = [],
        ?int $visitId = null,
        ?int $watchlistPlateId = null,
        array $fingerprintContext = [],
    ): ?AlertEvent {
        $settings = AlertSettings::for($site);

        if (! $settings->ruleEnabled($rule)) {
            return null;
        }

        $detectedAt = Date::now();
        $fingerprint = AlertFingerprint::make($site, $rule, $plate, [
            ...$fingerprintContext,
            'visit_id' => $visitId,
            'detected_at' => $detectedAt,
        ]);

        $sendAfter = AlertQuietHours::sendAfter($site, $settings, $rule, $detectedAt);
        $recipients = AlertRecipientResolver::emails($site);

        $status = AlertEventStatus::Pending;
        $error = null;

        if ($recipients === []) {
            $status = AlertEventStatus::Suppressed;
            $error = 'no_recipients';
        }

        try {
            $event = AlertEvent::query()->create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->getKey(),
                'rule' => $rule,
                'plate_number' => strtoupper($plate),
                'visit_id' => $visitId,
                'watchlist_plate_id' => $watchlistPlateId,
                'fingerprint' => $fingerprint,
                'status' => $status,
                'payload' => $payload,
                'detected_at' => $detectedAt,
                'send_after' => $status === AlertEventStatus::Pending ? $sendAfter : null,
                'error' => $error,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }

        if ($event->status === AlertEventStatus::Pending && $event->send_after === null) {
            SendAlertMail::dispatch($event->getKey());
        }

        return $event;
    }

    public function evaluateWatchlistHit(Site $site, string $plate, ?int $visitId = null): void
    {
        $entry = WatchlistPlate::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('plate_number', strtoupper($plate))
            ->active()
            ->first();

        if ($entry === null) {
            return;
        }

        $this->record(
            $site,
            AlertRule::WatchlistHit,
            $entry->plate_number,
            [
                'kind' => $entry->kind->value,
                'kind_label' => $entry->kind->label(),
                'reason' => $entry->reason,
            ],
            visitId: $visitId,
            watchlistPlateId: $entry->getKey(),
        );
    }

    public function evaluateDwellForSite(Site $site): void
    {
        if (! $this->siteHasExitTracking($site)) {
            return;
        }

        $settings = AlertSettings::for($site);

        if (! $settings->ruleEnabled(AlertRule::Dwell)) {
            return;
        }

        $thresholdHours = $settings->dwellHours();
        $cutoff = Date::now()->subHours($thresholdHours);

        $visits = Visit::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->where('status', VisitStatus::Open)
            ->where('entered_at', '<=', $cutoff)
            ->get();

        foreach ($visits as $visit) {
            $this->record(
                $site,
                AlertRule::Dwell,
                $visit->plate_number,
                [
                    'threshold_hours' => $thresholdHours,
                    'entered_at' => $visit->entered_at?->toIso8601String(),
                    'hours_on_site' => round($visit->entered_at->diffInMinutes(Date::now()) / 60, 1),
                ],
                visitId: $visit->getKey(),
                fingerprintContext: ['visit_id' => $visit->getKey()],
            );
        }
    }

    public function evaluateMultiEntryForSite(Site $site): void
    {
        $settings = AlertSettings::for($site);

        if (! $settings->ruleEnabled(AlertRule::MultiEntry)) {
            return;
        }

        $threshold = $settings->multiEntryThreshold();
        $dayStart = Date::now($site->timezone ?: config('app.timezone'))->startOfDay()->timezone(config('app.timezone'));

        $counts = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereHas('camera', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->getKey()))
            ->where('direction', PlateDirection::In)
            ->where('captured_at', '>=', $dayStart)
            ->selectRaw('plate_number, count(*) as entries')
            ->groupBy('plate_number')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->toBase()
            ->get();

        foreach ($counts as $row) {
            $this->record(
                $site,
                AlertRule::MultiEntry,
                (string) $row->plate_number,
                [
                    'entries' => (int) $row->entries,
                    'threshold' => $threshold,
                ],
            );
        }
    }

    public function evaluateOddHourForSite(Site $site): void
    {
        $settings = AlertSettings::for($site);

        if (! $settings->ruleEnabled(AlertRule::OddHour)) {
            return;
        }

        $config = config('trafficflow.security');
        $windowDays = (int) $config['odd_hour_window_days'];
        $start = (int) $config['odd_hours']['start'];
        $end = (int) $config['odd_hours']['end'];
        $windowStart = Date::now()->subDays($windowDays)->toDateString();

        $driver = PlateEvent::query()->getConnection()->getDriverName();

        $query = PlateEvent::query()
            ->withoutGlobalScope(SiteScope::class)
            ->whereHas('camera', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->getKey()))
            ->where('direction', PlateDirection::In)
            ->where('captured_at', '>=', Date::now()->subDays($windowDays));

        if ($driver === 'pgsql') {
            $query->whereRaw('(extract(hour from captured_at) >= ? or extract(hour from captured_at) < ?)', [$start, $end])
                ->selectRaw('plate_number')
                ->selectRaw('count(distinct captured_at::date) as days')
                ->groupBy('plate_number')
                ->havingRaw('count(distinct captured_at::date) > 1');
        } else {
            $query->whereRaw('(HOUR(captured_at) >= ? or HOUR(captured_at) < ?)', [$start, $end])
                ->selectRaw('plate_number')
                ->selectRaw('count(distinct DATE(captured_at)) as days')
                ->groupBy('plate_number')
                ->havingRaw('count(distinct DATE(captured_at)) > 1');
        }

        $rows = $query->toBase()->get();

        foreach ($rows as $row) {
            $this->record(
                $site,
                AlertRule::OddHour,
                (string) $row->plate_number,
                [
                    'days' => (int) $row->days,
                    'window_days' => $windowDays,
                ],
                fingerprintContext: ['window_start' => $windowStart],
            );
        }
    }

    protected function siteHasExitTracking(Site $site): bool
    {
        return Camera::query()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->getKey())
            ->whereIn('role', [CameraRole::Exit->value, CameraRole::Both->value])
            ->exists();
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';

        // MySQL 23000 / SQLite UNIQUE / Postgres 23505
        return $sqlState === '23000' || $sqlState === '23505' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
