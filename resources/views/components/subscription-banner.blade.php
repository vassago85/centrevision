@php
    use App\Support\Billing\SubscriptionStatusResolver;

    $user = auth()->user();
    $lapsed = $user === null ? collect() : app(SubscriptionStatusResolver::class)->lapsedSubscriptions($user);
@endphp

@if ($lapsed->isNotEmpty())
    {{-- Owners keep working while one site lapses, but should not be able to miss it. --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-tf bg-warning-soft px-4 py-3">
        <p class="text-[13px] text-warning">
            {{ trans_choice(
                '{1}Payment is outstanding for :sites.|[2,*]Payment is outstanding for :count sites: :sites.',
                $lapsed->count(),
                ['count' => $lapsed->count(), 'sites' => $lapsed->pluck('site.name')->join(', ', ' and ')],
            ) }}
        </p>

        @can('manage billing')
            <flux:button size="sm" variant="ghost" :href="route('billing')" wire:navigate>Review billing</flux:button>
        @endcan
    </div>
@endif
