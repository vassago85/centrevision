<?php

namespace App\Support\Alerts;

use App\Enums\AlertRule;
use App\Models\Site;

/**
 * Normalised view of sites.settings.alerts.
 */
final class AlertSettings
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        protected Site $site,
        protected array $raw,
    ) {}

    public static function for(Site $site): self
    {
        $raw = $site->setting('alerts', []);

        return new self($site, is_array($raw) ? $raw : []);
    }

    public function enabled(): bool
    {
        return (bool) ($this->raw['enabled'] ?? false);
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $list = $this->raw['recipients'] ?? [];

        if (is_string($list)) {
            $list = preg_split('/[\s,;]+/', $list) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($email) => strtolower(trim((string) $email)),
            (array) $list,
        ), fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    public function quietStart(): ?string
    {
        $value = $this->raw['quiet_start'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function quietEnd(): ?string
    {
        $value = $this->raw['quiet_end'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasQuietHours(): bool
    {
        return $this->quietStart() !== null && $this->quietEnd() !== null;
    }

    public function dedupeMinutes(): int
    {
        return max(1, (int) ($this->raw['dedupe_minutes'] ?? 60));
    }

    public function dwellHours(): int
    {
        $override = $this->raw['dwell_hours'] ?? null;

        if ($override !== null && $override !== '') {
            return max(1, (int) $override);
        }

        return $this->site->dwellAlertHours();
    }

    public function multiEntryThreshold(): int
    {
        $override = $this->raw['multi_entry_threshold'] ?? null;

        if ($override !== null && $override !== '') {
            return max(2, (int) $override);
        }

        return max(2, (int) config('trafficflow.security.multi_entry_threshold', 3));
    }

    public function ruleEnabled(AlertRule $rule): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $rules = $this->raw['rules'] ?? null;

        if (! is_array($rules) || $rules === []) {
            return true;
        }

        $entry = $rules[$rule->value] ?? null;

        if (! is_array($entry)) {
            return true;
        }

        return (bool) ($entry['enabled'] ?? true);
    }

    public function respectQuiet(AlertRule $rule): bool
    {
        $defaults = [
            AlertRule::WatchlistHit->value => false,
            AlertRule::Dwell->value => true,
            AlertRule::OddHour->value => true,
            AlertRule::MultiEntry->value => true,
        ];

        $rules = $this->raw['rules'] ?? [];
        $entry = is_array($rules) ? ($rules[$rule->value] ?? null) : null;

        if (is_array($entry) && array_key_exists('respect_quiet', $entry)) {
            return (bool) $entry['respect_quiet'];
        }

        return $defaults[$rule->value] ?? true;
    }

    public function site(): Site
    {
        return $this->site;
    }
}
