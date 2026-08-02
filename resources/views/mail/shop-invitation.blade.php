<x-mail::message>
# You have been invited to {{ config('app.name') }}

{{ $siteName }} would like to give your shop access to its vehicle traffic
analytics: visitor volumes, peak hours and dwell times for the centre.

The subscription is R{{ $amount }} per month.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->format('j F Y') }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
