@php
    use App\Support\Navigation;
    use App\Support\Tenancy;

    $user = auth()->user();
    $items = Navigation::for($user);
    $tenancy = app(Tenancy::class);

    $roleLabel = match (true) {
        $user->isPlatformAdmin() => 'Platform',
        $user->isOwnerAdmin() => 'Owner',
        $user->isShopUser() => 'Shop',
        default => 'User',
    };

    // Security and Watchlist are day-to-day operations from the sidebar's
    // point of view, so they share the Operations heading.
    $groupLabels = [
        'operations' => 'Operations',
        'security' => 'Operations',
        'admin' => 'Administration',
    ];
@endphp

<aside class="fixed inset-y-0 left-0 z-40 flex w-[220px] flex-col gap-3 border-r border-line bg-surface px-3 py-4 max-lg:hidden">

    <a
        href="{{ route(Navigation::homeRouteFor($user)) }}"
        wire:navigate
        class="flex items-center rounded-lg px-2 py-2 transition-colors hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
    >
        <x-brand variant="wordmark" />
    </a>

    {{-- Dashboard and Reports carry the site picker in their own toolbar. --}}
    @if ($tenancy->hasMultipleSites() && ! request()->routeIs('overview', 'reports'))
        <div class="px-1">
            <p class="mb-1 px-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-muted">Site scope</p>
            <livewire:site-switcher />
        </div>
    @endif

    <nav class="flex flex-col gap-0.5 overflow-y-auto text-[14px]" aria-label="{{ __('Main') }}">
        @php $previousHeading = null; @endphp
        @foreach ($items as $item)
            @php
                $isCurrent = request()->routeIs($item['route']);
                $heading = $groupLabels[$item['group'] ?? ''] ?? null;
                $showHeading = $heading !== null && $heading !== $previousHeading;
                $previousHeading = $heading ?? $previousHeading;
            @endphp

            @if ($showHeading)
                <p class="mt-4 mb-1 px-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-muted">{{ $heading }}</p>
            @endif

            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @if ($isCurrent) aria-current="page" @endif
                @class([
                    'group flex min-h-11 items-center gap-3 rounded-lg px-3 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                    'bg-accent dark:bg-accent-2 text-white' => $isCurrent,
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! $isCurrent,
                ])
            >
                <flux:icon :icon="$item['icon']" @class([
                    'size-[18px] shrink-0',
                    'text-white' => $isCurrent,
                    'text-ink-muted group-hover:text-ink-2' => ! $isCurrent,
                ]) />
                <span class="{{ $isCurrent ? 'font-semibold' : 'font-medium' }}">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-auto">
        <flux:dropdown position="top" align="end">
            <button
                type="button"
                class="flex min-h-11 w-full items-center gap-2.5 rounded-lg border border-line bg-surface px-2.5 py-2 text-left transition-colors hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                aria-label="{{ __('Account menu') }}"
                data-test="user-menu-button"
            >
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent dark:bg-accent-2 text-[12px] font-semibold text-white">{{ $user->initials() }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-semibold text-ink">{{ $user->name }}</span>
                    <span class="block truncate text-[12px] text-ink-muted">{{ $roleLabel }} · {{ $user->organization?->name ?? 'CentreVision' }}</span>
                </span>
                <flux:icon icon="chevron-up-down" class="size-4 shrink-0 text-ink-muted" />
            </button>

            <flux:menu>
                <flux:menu.item :href="route('account.profile')" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                <flux:menu.item :href="route('account.appearance')" icon="swatch" wire:navigate>{{ __('Appearance') }}</flux:menu.item>
                <flux:menu.item :href="route('account.security')" icon="shield-check" wire:navigate>{{ __('Security') }}</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                        data-test="logout-button"
                    >{{ __('Log out') }}</flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </div>
</aside>

{{-- Below lg the sidebar collapses into this bar. Account links and Log out
     live in its menu too — the desktop user card is hidden at this size, so
     without them here there is no way to sign out. --}}
<div class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-surface px-4 py-2 lg:hidden" data-test="mobile-nav">
    <x-brand variant="wordmark" class="flex-1" />
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" icon="bars-3" square aria-label="{{ __('Menu') }}" class="size-11!" />
        <flux:menu>
            @foreach ($items as $item)
                <flux:menu.item :href="route($item['route'])" wire:navigate :icon="$item['icon']" class="min-h-11">{{ $item['label'] }}</flux:menu.item>
            @endforeach
            <flux:menu.separator />
            <flux:menu.item :href="route('account.profile')" icon="user" wire:navigate class="min-h-11">{{ __('Profile') }}</flux:menu.item>
            <flux:menu.item :href="route('account.appearance')" icon="swatch" wire:navigate class="min-h-11">{{ __('Appearance') }}</flux:menu.item>
            <flux:menu.item :href="route('account.security')" icon="shield-check" wire:navigate class="min-h-11">{{ __('Account security') }}</flux:menu.item>
            <flux:menu.separator />
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="min-h-11 w-full cursor-pointer"
                    data-test="mobile-logout-button"
                >{{ __('Log out') }}</flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
