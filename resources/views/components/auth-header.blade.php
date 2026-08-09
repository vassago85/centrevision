@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    {{-- Force level-1 so the auth page has a single h1 landmark. --}}
    <flux:heading level="1" size="xl">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
