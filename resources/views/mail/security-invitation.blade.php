<x-mail::message>
# You have been invited to {{ config('app.name') }}

Hello {{ $name }},

{{ $organizationName }} would like you to help watch their properties on
{{ config('app.name') }}. You will be able to see live vehicle plates,
review the watchlist and follow up on security alerts.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->format('j F Y') }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
