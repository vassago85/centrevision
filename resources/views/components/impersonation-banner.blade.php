@php
    $impersonation = app(\App\Support\Platform\Impersonation::class);
    $viewingAs = $impersonation->isActive() ? auth()->user() : null;
    $realAdmin = $impersonation->impersonator();
@endphp

@if ($viewingAs !== null)
    <div
        role="status"
        class="mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border border-warning/40 bg-warning-soft py-0 pr-1 pl-3 text-[13px] text-ink"
        data-test="impersonation-banner"
    >
        <p class="flex min-w-0 items-center gap-2">
            <flux:icon icon="eye" class="size-4 shrink-0 text-ink-2" />
            <span class="truncate">
                <span class="font-semibold">Viewing tenant: {{ $viewingAs->organization?->name ?? 'Tenant' }}</span>
                <span class="text-ink-2">· as {{ $viewingAs->name }}@if ($realAdmin) · signed in as {{ $realAdmin->name }}@endif</span>
            </span>
        </p>

        <form method="POST" action="{{ route('platform.impersonate.stop') }}">
            @csrf
            <button
                type="submit"
                data-test="stop-impersonating"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-2.5 font-semibold text-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
                <flux:icon icon="x-mark" class="size-4" />
                Stop viewing
            </button>
        </form>
    </div>
@endif
