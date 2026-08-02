@props(['user'])

<flux:dropdown position="bottom" align="end">
    <button
        type="button"
        class="flex size-7 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent"
        aria-label="{{ __('Account menu') }}"
        data-test="user-menu-button"
    >{{ $user->initials() }}</button>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar :name="$user->name" :initials="$user->initials()" />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                <flux:text class="truncate">{{ $user->organization?->name ?? __('Platform') }}</flux:text>
            </div>
        </div>

        <flux:menu.separator />

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
