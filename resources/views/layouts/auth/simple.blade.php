<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas font-sans text-ink antialiased">
        {{-- A single <main> landmark holds every auth screen so screen readers can
             jump straight to the form. Was missing entirely, which axe flagged
             with "Document should have one main landmark" and "All page
             content should be contained by landmarks" on every login node. --}}
        <main class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex justify-center" wire:navigate>
                    <x-brand variant="full" />
                </a>

                <div class="flex flex-col gap-6 rounded-tf border border-line bg-surface p-6">
                    {{ $slot }}
                </div>
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
