@php
    $impersonation = app(\App\Support\Platform\Impersonation::class);
    $viewingAs = $impersonation->isActive() ? auth()->user() : null;
    $realAdmin = $impersonation->impersonator();
@endphp

@if ($viewingAs !== null)
    <div
        class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-tf border border-warning/40 bg-warning-soft px-4 py-3"
        data-test="impersonation-banner"
    >
        <div class="flex items-center gap-3">
            <span class="flex size-8 items-center justify-center rounded-full bg-warning text-white">
                <flux:icon icon="eye" class="size-4" />
            </span>
            <div class="text-[13px] text-warning">
                <p class="font-semibold">
                    Viewing as {{ $viewingAs->name }}
                    <span class="font-normal text-warning/80">
                        · {{ $viewingAs->organization?->name ?? 'Tenant' }}
                    </span>
                </p>
                @if ($realAdmin)
                    <p class="text-[11.5px] text-warning/80">
                        Real account: {{ $realAdmin->name }} ({{ $realAdmin->email }})
                    </p>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('platform.impersonate.stop') }}">
            @csrf
            <flux:button size="sm" variant="primary" type="submit" data-test="stop-impersonating">
                Stop viewing as tenant
            </flux:button>
        </form>
    </div>
@endif
