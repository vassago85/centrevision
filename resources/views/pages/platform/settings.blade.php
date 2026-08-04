<?php

use App\Support\Platform\PlatformSettings;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Platform settings')] class extends Component {
    /**
     * Deep-link the currently open tab into the URL so a support engineer
     * can send "open your Paystack settings" as a single link, and a page
     * reload during editing does not throw the operator back to Mail.
     */
    #[Url(as: 'tab', keep: true)]
    public string $tab = 'mail';

    /**
     * Not shown in Commit 1 (skeleton only) — Commit 2 fills each tab with
     * its real form. The list of tabs is exposed as a computed so views can
     * render pill navigation without knowing the tab keys ahead of time.
     *
     * @return array<int, array{key: string, label: string, description: string}>
     */
    public function tabs(): array
    {
        return [
            ['key' => 'mail', 'label' => 'Mail', 'description' => 'Mailgun / SMTP settings for transactional email.'],
            ['key' => 'paystack', 'label' => 'Paystack', 'description' => 'Payment gateway keys and webhook secret.'],
            ['key' => 'billing', 'label' => 'Billing', 'description' => 'Rates, revenue shares, retention window.'],
            ['key' => 'landing', 'label' => 'Landing', 'description' => 'Contact addresses shown to tenants.'],
            ['key' => 'flags', 'label' => 'Feature flags', 'description' => 'Demo mode, fuzzy match, defaults.'],
        ];
    }

    public function setTab(string $tab): void
    {
        // Guard against a tampered URL setting a tab that does not exist.
        // If the caller passes gibberish we quietly clamp to `mail` rather
        // than throw, because a bad deep-link should still render a page.
        $keys = array_column($this->tabs(), 'key');
        $this->tab = in_array($tab, $keys, true) ? $tab : 'mail';
    }
}; ?>

<div>
    <x-page-header title="Platform settings" subtitle="Configuration a platform admin can change without redeploying" />

    <div class="mb-6 flex flex-wrap gap-2 border-b border-line">
        @foreach ($this->tabs() as $tab)
            <button
                type="button"
                wire:click="setTab('{{ $tab['key'] }}')"
                @class([
                    'relative rounded-t-md px-3 py-2 text-[13px] font-semibold transition-colors -mb-px border-b-2',
                    'border-accent text-accent' => $this->tab === $tab['key'],
                    'border-transparent text-ink-2 hover:text-ink' => $this->tab !== $tab['key'],
                ])
            >{{ $tab['label'] }}</button>
        @endforeach
    </div>

    <p class="mb-4 text-[13px] text-ink-2">
        {{ collect($this->tabs())->firstWhere('key', $this->tab)['description'] ?? '' }}
    </p>

    {{-- Placeholder body. Commit 2 replaces this with the tab-specific forms.
         Rendering an obvious "coming next" panel keeps the navigation testable
         and lets a reviewer click each tab to prove the deep-link works. --}}
    <x-panel>
        <p class="text-sm text-ink-2">
            Editing the <span class="font-semibold text-ink">{{ collect($this->tabs())->firstWhere('key', $this->tab)['label'] }}</span> tab arrives in the next commit.
            Until then the app reads these values from the live <code class="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[12px]">.env</code>
            just as it did before.
        </p>
    </x-panel>
</div>
