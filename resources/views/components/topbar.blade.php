@php
    use App\Support\Navigation;
    use App\Support\Tenancy;

    $user = auth()->user();
    $items = Navigation::for($user);
    $tenancy = app(Tenancy::class);
@endphp

<header class="mb-6 flex items-end justify-between gap-4 border-b border-line">
    <a href="{{ route(Navigation::homeRouteFor($user)) }}" wire:navigate class="pb-4">
        <x-brand />
    </a>

    <nav class="flex flex-1 items-end gap-6 overflow-x-auto text-[13.5px] max-sm:gap-3.5">
        @foreach ($items as $item)
            @php
                $isCurrent = request()->routeIs($item['route']);
                $accentBorder = ($item['tone'] ?? null) === 'danger' ? 'border-danger' : 'border-accent';
            @endphp

            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @class([
                    'whitespace-nowrap border-b-2 pb-4 transition-colors',
                    "font-semibold text-ink {$accentBorder}" => $isCurrent,
                    'border-transparent text-ink-2 hover:text-ink' => ! $isCurrent,
                ])
            >{{ $item['label'] }}</a>
        @endforeach
    </nav>

    <div class="flex items-center gap-3 pb-3">
        @if ($tenancy->hasMultipleSites())
            <livewire:site-switcher />
        @endif

        <x-user-menu :user="$user" />
    </div>
</header>
