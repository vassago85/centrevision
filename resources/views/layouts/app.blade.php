<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-page font-sans text-ink antialiased">

        @persist('sidebar')
            <x-sidebar />
        @endpersist

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
