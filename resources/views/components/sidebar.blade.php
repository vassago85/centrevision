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
@endphp

<aside class="fixed inset-y-0 left-0 z-40 flex w-[264px] flex-col gap-3 border-r border-line bg-canvas p-4 max-lg:hidden">

    {{-- Brand card — the wordmark lockup gets its own white tile at the top
         of the sidebar so the logo has breathing room and reads clearly. --}}
    <a
        href="{{ route(Navigation::homeRouteFor($user)) }}"
        wire:navigate
        class="flex items-center justify-center rounded-xl border border-line bg-surface px-3 py-4 shadow-tf-sm transition-colors hover:bg-surface-2"
    >
        <x-brand variant="wordmark" />
    </a>

    {{-- Optional site scope pill: only rendered for tenants who have more than
         one site, so the switcher does not clutter a single-site sidebar. --}}
    @if ($tenancy->hasMultipleSites())
        <div class="rounded-xl border border-line bg-surface p-3 shadow-tf-sm">
            <p class="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Site scope</p>
            <livewire:site-switcher />
        </div>
    @endif

    <nav class="mt-1 flex flex-col gap-1 overflow-y-auto text-[14px]">
        @foreach ($items as $item)
            @php
                $isCurrent = request()->routeIs($item['route']);
                $isDanger = ($item['tone'] ?? null) === 'danger';
            @endphp

            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @class([
                    'group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors',
                    'bg-accent dark:bg-accent-2 text-white shadow-tf-sm' => $isCurrent,
                    'text-ink-2 hover:bg-surface-2 hover:text-ink' => ! $isCurrent,
                ])
            >
                <flux:icon :icon="$item['icon']" @class([
                    'size-5 shrink-0',
                    'text-white' => $isCurrent,
                    'text-danger' => ! $isCurrent && $isDanger,
                    'text-ink-muted group-hover:text-ink-2' => ! $isCurrent && ! $isDanger,
                ]) />
                <span class="{{ $isCurrent ? 'font-semibold' : 'font-medium' }}">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="mt-auto">
        {{-- User card — anchored at the bottom of the sidebar. Clicking it
             opens the same dropdown menu the topbar avatar used to. --}}
        <flux:dropdown position="top" align="end">
            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-xl border border-line bg-surface px-3 py-2.5 text-left shadow-tf-sm transition-colors hover:bg-surface-2"
                aria-label="{{ __('Account menu') }}"
                data-test="user-menu-button"
            >
                <span class="flex size-9 items-center justify-center rounded-full bg-accent dark:bg-accent-2 text-[13px] font-semibold text-white">{{ $user->initials() }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13.5px] font-semibold text-ink">{{ $user->name }}</span>
                    <span class="block truncate text-[11.5px] text-ink-muted">{{ $roleLabel }} · {{ $user->organization?->name ?? 'CentreVision' }}</span>
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

{{-- Mobile fallback: on <lg, the sidebar collapses to a strip along the top so
     users on small screens still get the same nav items without a hamburger. --}}
<div class="mb-4 flex items-center gap-3 border-b border-line bg-canvas px-4 py-3 lg:hidden">
    <x-brand variant="wordmark" class="flex-1" />
    <flux:dropdown position="bottom" align="end">
        <flux:button size="sm" variant="ghost" icon="bars-3" square />
        <flux:menu>
            @foreach ($items as $item)
                <flux:menu.item :href="route($item['route'])" wire:navigate :icon="$item['icon']">{{ $item['label'] }}</flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
