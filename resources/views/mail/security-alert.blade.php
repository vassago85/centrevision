<x-mail::message>
# {{ $ruleLabel }}

**Site:** {{ $siteName }}  
**Plate:** {{ $plate }}  
**Detected:** {{ $detectedAt->timezone(config('app.timezone'))->format('Y-m-d H:i') }}

@if (! empty($payload['kind_label']))
**Watchlist:** {{ $payload['kind_label'] }}@if (! empty($payload['reason'])) — {{ $payload['reason'] }}@endif
@endif

@if (! empty($payload['threshold_hours']))
**Dwell threshold:** {{ $payload['threshold_hours'] }}h  
**Hours on site:** {{ $payload['hours_on_site'] ?? '—' }}
@endif

@if (! empty($payload['entries']))
**Entries today:** {{ $payload['entries'] }} (threshold {{ $payload['threshold'] ?? '—' }})
@endif

@if (! empty($payload['days']))
**Odd-hour days in window:** {{ $payload['days'] }} / {{ $payload['window_days'] ?? '—' }}
@endif

<x-mail::button :url="$securityUrl">
Open Security
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
