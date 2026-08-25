<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-page font-sans text-ink antialiased">

        {{-- Not @persist'ed: the sidebar computes its "current tab" highlight
             in Blade using request()->routeIs(), so it must re-render on
             every wire:navigate. Persisting it froze the highlight on
             whichever tab was current at initial page load. --}}
        <x-sidebar />

        <main class="lg:pl-[264px]">
            <div class="mx-auto max-w-[1200px] px-5 pt-6 pb-16 sm:px-8">
                <x-subscription-banner />

                {{ $slot }}
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
