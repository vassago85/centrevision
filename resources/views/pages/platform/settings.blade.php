<?php

use App\Support\Platform\PlatformSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    // ── Mail ─────────────────────────────────────────────────────────────
    public string $mailMailer = '';

    public string $mailFromAddress = '';

    public string $mailFromName = '';

    public string $mailgunDomain = '';

    public string $mailgunSecret = '';

    public string $mailgunEndpoint = '';

    public string $testMailTo = '';

    // ── Paystack ─────────────────────────────────────────────────────────
    public string $paystackPublic = '';

    public string $paystackSecret = '';

    public string $paystackWebhookSecret = '';

    public string $paystackBaseUrl = '';

    // ── Billing ──────────────────────────────────────────────────────────
    public float $variableRate = 0.0;

    public float $shopRevenueShare = 0.0;

    public float $operatorSeatRate = 0.0;

    public float $partnerCommissionRate = 0.0;

    public int $retentionDays = 365;

    // ── Landing / support ────────────────────────────────────────────────
    public string $billingEmail = '';

    public string $supportEmail = '';

    // ── Feature flags ────────────────────────────────────────────────────
    public bool $demoMode = false;

    public bool $fuzzyMatchEnabled = true;

    public int $dwellAlertHours = 4;

    public function mount(): void
    {
        $s = app(PlatformSettings::class);

        // Mail
        $this->mailMailer = (string) $s->get('mail.mailer', 'mail.default', '');
        $this->mailFromAddress = (string) $s->get('mail.from.address', 'mail.from.address', '');
        $this->mailFromName = (string) $s->get('mail.from.name', 'mail.from.name', '');
        $this->mailgunDomain = (string) $s->get('services.mailgun.domain', 'services.mailgun.domain', '');
        $this->mailgunSecret = (string) $s->get('services.mailgun.secret', 'services.mailgun.secret', '');
        $this->mailgunEndpoint = (string) $s->get('services.mailgun.endpoint', 'services.mailgun.endpoint', 'api.mailgun.net');
        $this->testMailTo = auth()->user()?->email ?? '';

        // Paystack
        $this->paystackPublic = (string) $s->get('services.paystack.public', 'services.paystack.public', '');
        $this->paystackSecret = (string) $s->get('services.paystack.secret', 'services.paystack.secret', '');
        $this->paystackWebhookSecret = (string) $s->get('services.paystack.webhook_secret', 'services.paystack.webhook_secret', '');
        $this->paystackBaseUrl = (string) $s->get('services.paystack.base_url', 'services.paystack.base_url', 'https://api.paystack.co');

        // Billing
        $this->variableRate = $s->getFloat('trafficflow.variable_rate_per_camera_per_subuser', 'trafficflow.variable_rate_per_camera_per_subuser', 20.00);
        $this->shopRevenueShare = $s->getFloat('trafficflow.platform_shop_revenue_share', 'trafficflow.platform_shop_revenue_share', 0.30);
        $this->operatorSeatRate = $s->getFloat('trafficflow.security_operator_monthly_amount', 'trafficflow.security_operator_monthly_amount', 20.00);
        $this->partnerCommissionRate = $s->getFloat('trafficflow.partner_commission_rate', 'trafficflow.partner_commission_rate', 0.20);
        $this->retentionDays = $s->getInt('trafficflow.retention_days', 'trafficflow.retention_days', 365);

        // Landing
        $this->billingEmail = (string) $s->get('trafficflow.billing_email', 'trafficflow.billing_email', '');
        $this->supportEmail = (string) $s->get('trafficflow.support_email', 'trafficflow.support_email', '');

        // Feature flags
        $this->demoMode = $s->getBool('trafficflow.demo_mode', 'trafficflow.demo_mode', false);
        $this->fuzzyMatchEnabled = $s->getBool('trafficflow.fuzzy_match_enabled', 'trafficflow.fuzzy_match_enabled', true);
        $this->dwellAlertHours = $s->getInt('trafficflow.dwell_alert_hours', 'trafficflow.dwell_alert_hours', 4);
    }

    /**
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
        // Guard against a tampered URL setting a tab that does not exist —
        // a bad deep-link should still render a page.
        $keys = array_column($this->tabs(), 'key');
        $this->tab = in_array($tab, $keys, true) ? $tab : 'mail';
    }

    /**
     * ── Save handlers ────────────────────────────────────────────────────
     *
     * One per tab. Splitting per-tab keeps a botched Paystack change from
     * blocking a save on the Mail tab, and lets each form declare only the
     * validation rules that apply to its own fields.
     */
    public function saveMail(): void
    {
        $data = $this->validate([
            'mailMailer' => ['required', 'string', 'in:mailgun,smtp,log'],
            'mailFromAddress' => ['nullable', 'email', 'max:255'],
            'mailFromName' => ['nullable', 'string', 'max:120'],
            'mailgunDomain' => ['nullable', 'string', 'max:255'],
            'mailgunSecret' => ['nullable', 'string', 'max:255'],
            'mailgunEndpoint' => ['nullable', 'string', 'max:255'],
        ]);

        app(PlatformSettings::class)->setMany([
            'mail.mailer' => $data['mailMailer'],
            'mail.from.address' => $data['mailFromAddress'],
            'mail.from.name' => $data['mailFromName'],
            'services.mailgun.domain' => $data['mailgunDomain'],
            'services.mailgun.secret' => $data['mailgunSecret'],
            'services.mailgun.endpoint' => $data['mailgunEndpoint'],
        ], auth()->user());

        // Apply the change to the running process too, so the "send test"
        // button below uses what the operator just typed without a reload.
        $this->reapplyMailConfig();

        Flux::toast(variant: 'success', text: 'Mail settings saved.');
    }

    public function savePaystack(): void
    {
        $data = $this->validate([
            'paystackPublic' => ['nullable', 'string', 'max:255'],
            'paystackSecret' => ['nullable', 'string', 'max:255'],
            'paystackWebhookSecret' => ['nullable', 'string', 'max:255'],
            'paystackBaseUrl' => ['required', 'url', 'max:255'],
        ]);

        app(PlatformSettings::class)->setMany([
            'services.paystack.public' => $data['paystackPublic'],
            'services.paystack.secret' => $data['paystackSecret'],
            'services.paystack.webhook_secret' => $data['paystackWebhookSecret'],
            'services.paystack.base_url' => $data['paystackBaseUrl'],
        ], auth()->user());

        // Push through to the running config so the verify button below
        // uses the just-saved credentials rather than the pre-save ones.
        config()->set('services.paystack.public', $data['paystackPublic']);
        config()->set('services.paystack.secret', $data['paystackSecret']);
        config()->set('services.paystack.webhook_secret', $data['paystackWebhookSecret']);
        config()->set('services.paystack.base_url', $data['paystackBaseUrl']);

        Flux::toast(variant: 'success', text: 'Paystack settings saved.');
    }

    public function saveBilling(): void
    {
        $data = $this->validate([
            'variableRate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'shopRevenueShare' => ['required', 'numeric', 'min:0', 'max:1'],
            'operatorSeatRate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'partnerCommissionRate' => ['required', 'numeric', 'min:0', 'max:1'],
            'retentionDays' => ['required', 'integer', 'min:30', 'max:1095'],
        ]);

        app(PlatformSettings::class)->setMany([
            'trafficflow.variable_rate_per_camera_per_subuser' => $data['variableRate'],
            'trafficflow.platform_shop_revenue_share' => $data['shopRevenueShare'],
            'trafficflow.security_operator_monthly_amount' => $data['operatorSeatRate'],
            'trafficflow.partner_commission_rate' => $data['partnerCommissionRate'],
            'trafficflow.retention_days' => $data['retentionDays'],
        ], auth()->user());

        Flux::toast(variant: 'success', text: 'Billing settings saved. Applies from the next invoice run.');
    }

    public function saveLanding(): void
    {
        $data = $this->validate([
            'billingEmail' => ['required', 'email', 'max:255'],
            'supportEmail' => ['required', 'email', 'max:255'],
        ]);

        app(PlatformSettings::class)->setMany([
            'trafficflow.billing_email' => $data['billingEmail'],
            'trafficflow.support_email' => $data['supportEmail'],
        ], auth()->user());

        Flux::toast(variant: 'success', text: 'Contact addresses saved.');
    }

    public function saveFlags(): void
    {
        $data = $this->validate([
            'demoMode' => ['boolean'],
            'fuzzyMatchEnabled' => ['boolean'],
            'dwellAlertHours' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        app(PlatformSettings::class)->setMany([
            'trafficflow.demo_mode' => $data['demoMode'],
            'trafficflow.fuzzy_match_enabled' => $data['fuzzyMatchEnabled'],
            'trafficflow.dwell_alert_hours' => $data['dwellAlertHours'],
        ], auth()->user());

        Flux::toast(variant: 'success', text: 'Feature flags saved.');
    }

    /**
     * Send a one-liner to the operator to prove the mailer works. Uses the
     * *saved* settings, not the ones sitting in the form fields — an admin
     * clicking Send without saving first would be misleading.
     */
    public function sendTestMail(): void
    {
        $this->validate([
            'testMailTo' => ['required', 'email'],
        ]);

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').". If you can read this, mail is configured correctly.\n\nSent at ".now()->toDateTimeString(),
                function ($message): void {
                    $message->to($this->testMailTo)
                        ->subject(config('app.name').' — mail test');
                },
            );

            Flux::toast(variant: 'success', text: 'Test email queued to '.$this->testMailTo.'.');
        } catch (\Throwable $e) {
            // Surface the underlying error rather than a generic "failed" —
            // a mailer failure with no context makes debugging Mailgun keys
            // an exercise in log-diving.
            Flux::toast(
                variant: 'danger',
                text: 'Send failed: '.\Illuminate\Support\Str::limit($e->getMessage(), 200),
            );
        }
    }

    /**
     * Hit Paystack's cheapest authenticated endpoint (bank list) with the
     * saved secret so the operator gets a green tick before ever expecting
     * a real payment to work.
     */
    public function verifyPaystack(): void
    {
        $secret = config('services.paystack.secret');
        $baseUrl = config('services.paystack.base_url');

        if (blank($secret)) {
            Flux::toast(variant: 'danger', text: 'Save a secret key first, then verify.');

            return;
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(10)
                ->get(rtrim($baseUrl, '/').'/bank?currency=ZAR&perPage=1');

            if ($response->status() === 401) {
                Flux::toast(variant: 'danger', text: 'Paystack refused the secret key. Double-check it.');

                return;
            }

            if ($response->failed()) {
                Flux::toast(variant: 'danger', text: 'Paystack returned HTTP '.$response->status().'.');

                return;
            }

            Flux::toast(variant: 'success', text: 'Paystack accepted the key.');
        } catch (ConnectionException $e) {
            Flux::toast(variant: 'danger', text: 'Could not reach Paystack: '.\Illuminate\Support\Str::limit($e->getMessage(), 200));
        }
    }

    /**
     * Livewire holds a snapshot of the mail config at request boot. When
     * saveMail() writes new credentials, the running process still points
     * at the old ones until we push through the changes, which would make
     * "Save then Send Test" fail on a working setup.
     */
    protected function reapplyMailConfig(): void
    {
        config()->set('mail.default', $this->mailMailer);
        config()->set('mail.from.address', $this->mailFromAddress);
        config()->set('mail.from.name', $this->mailFromName);
        config()->set('services.mailgun.domain', $this->mailgunDomain);
        config()->set('services.mailgun.secret', $this->mailgunSecret);
        config()->set('services.mailgun.endpoint', $this->mailgunEndpoint);

        // Laravel caches the mailer instance keyed on the mailer name. Dropping
        // the singleton forces the next Mail::raw call to build a fresh one
        // using the config we just replaced.
        app()->forgetInstance('mailer');
        app()->forgetInstance('mail.manager');
    }
}; ?>

<div>
    <x-page-header title="Platform settings" subtitle="Configuration a platform admin can change without redeploying" />

    <div class="mb-6 flex flex-wrap gap-2 border-b border-line">
        @foreach ($this->tabs() as $t)
            <button
                type="button"
                wire:click="setTab('{{ $t['key'] }}')"
                @class([
                    'relative rounded-t-md px-3 py-2 text-[13px] font-semibold transition-colors -mb-px border-b-2',
                    'border-accent text-accent' => $this->tab === $t['key'],
                    'border-transparent text-ink-2 hover:text-ink' => $this->tab !== $t['key'],
                ])
            >{{ $t['label'] }}</button>
        @endforeach
    </div>

    <p class="mb-4 text-[13px] text-ink-2">
        {{ collect($this->tabs())->firstWhere('key', $this->tab)['description'] ?? '' }}
    </p>

    {{-- ── Mail ─────────────────────────────────────────────────────── --}}
    @if ($this->tab === 'mail')
        <x-panel heading="Transactional email">
            <form wire:submit="saveMail" class="grid gap-4 md:grid-cols-2">
                <flux:select wire:model="mailMailer" label="Mailer" description="Log stores to storage/logs; use Mailgun for outbound.">
                    <flux:select.option value="log">Log (development)</flux:select.option>
                    <flux:select.option value="mailgun">Mailgun</flux:select.option>
                    <flux:select.option value="smtp">SMTP</flux:select.option>
                </flux:select>

                <flux:input wire:model="mailFromAddress" type="email" label="From address" placeholder="no-reply@centrevision.co.za" />
                <flux:input wire:model="mailFromName" label="From name" placeholder="CentreVision" />

                <div class="md:col-span-2 mt-2 border-t border-line pt-4">
                    <p class="mb-3 text-[13px] font-semibold text-ink">Mailgun credentials</p>
                </div>

                <flux:input wire:model="mailgunDomain" label="Domain" placeholder="mg.centrevision.co.za" />
                <flux:input wire:model="mailgunSecret" type="password" label="API secret" placeholder="key-xxxxxxxx" viewable />
                <flux:input wire:model="mailgunEndpoint" label="API endpoint" description="Use api.eu.mailgun.net for EU domains." />

                <div class="md:col-span-2 flex justify-end">
                    <flux:button variant="primary" type="submit">Save mail settings</flux:button>
                </div>
            </form>
        </x-panel>

        <x-panel heading="Send a test email">
            <div class="flex flex-wrap items-end gap-3">
                <flux:input wire:model="testMailTo" type="email" label="Send to" class="min-w-[240px]" />
                <flux:button variant="ghost" wire:click="sendTestMail">Send test</flux:button>
            </div>
            <p class="mt-3 text-[12.5px] text-ink-muted">
                Uses the last-saved credentials. Save first, then send — an unsaved change won't be picked up.
            </p>
        </x-panel>
    @endif

    {{-- ── Paystack ─────────────────────────────────────────────────── --}}
    @if ($this->tab === 'paystack')
        <x-panel heading="Paystack">
            <form wire:submit="savePaystack" class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="paystackPublic" label="Public key" placeholder="pk_live_..." />
                <flux:input wire:model="paystackSecret" type="password" label="Secret key" placeholder="sk_live_..." viewable />
                <flux:input wire:model="paystackWebhookSecret" type="password" label="Webhook secret" description="Set on the Webhook page in your Paystack dashboard." viewable />
                <flux:input wire:model="paystackBaseUrl" label="API base URL" description="Change only if Paystack tells you to." />

                <div class="md:col-span-2 flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="verifyPaystack" type="button">Verify credentials</flux:button>
                    <flux:button variant="primary" type="submit">Save Paystack settings</flux:button>
                </div>
            </form>
        </x-panel>
    @endif

    {{-- ── Billing ──────────────────────────────────────────────────── --}}
    @if ($this->tab === 'billing')
        <x-panel heading="Global billing knobs">
            <p class="mb-5 text-[13px] text-ink-2">
                Applies from the next invoice run. Nothing already invoiced changes.
            </p>

            <form wire:submit="saveBilling" class="grid gap-4 md:grid-cols-2">
                <flux:input
                    wire:model="variableRate"
                    type="number"
                    step="0.01"
                    label="Variable rate (per camera × paying shop)"
                    description="Currently R{{ number_format($variableRate, 2) }}/month per unit."
                />

                <flux:input
                    wire:model="shopRevenueShare"
                    type="number"
                    step="0.01"
                    label="Platform share of shop revenue"
                    description="{{ number_format($shopRevenueShare * 100, 1) }}% of what owners charge tenants."
                />

                <flux:input
                    wire:model="operatorSeatRate"
                    type="number"
                    step="0.01"
                    label="Security operator seat (R/month)"
                    description="Charged flat per active operator user."
                />

                <flux:input
                    wire:model="partnerCommissionRate"
                    type="number"
                    step="0.01"
                    label="Partner commission rate"
                    description="{{ number_format($partnerCommissionRate * 100, 1) }}% of tenant revenue owed to partners."
                />

                <flux:input
                    wire:model="retentionDays"
                    type="number"
                    label="POPIA retention (days)"
                    description="Between 30 and 1095. Plate data older than this is pruned nightly."
                />

                <div class="md:col-span-2 flex justify-end">
                    <flux:button variant="primary" type="submit">Save billing knobs</flux:button>
                </div>
            </form>
        </x-panel>
    @endif

    {{-- ── Landing / support ─────────────────────────────────────────── --}}
    @if ($this->tab === 'landing')
        <x-panel heading="Contact addresses">
            <p class="mb-5 text-[13px] text-ink-2">
                Shown to tenants who need to reach a human — the paywall page, the footer of transactional email, and the marketing landing.
            </p>

            <form wire:submit="saveLanding" class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="billingEmail" type="email" label="Billing email" description="Displayed to tenants past-due." />
                <flux:input wire:model="supportEmail" type="email" label="Support email" description="General enquiries." />

                <div class="md:col-span-2 flex justify-end">
                    <flux:button variant="primary" type="submit">Save contact addresses</flux:button>
                </div>
            </form>
        </x-panel>
    @endif

    {{-- ── Feature flags ────────────────────────────────────────────── --}}
    @if ($this->tab === 'flags')
        <x-panel heading="Feature flags">
            <form wire:submit="saveFlags" class="grid gap-6">
                <flux:switch
                    wire:model="demoMode"
                    label="Demo mode"
                    description="When on, the login screen exposes seeded demo accounts with one-click prefill. Never enable on a paying deployment."
                />

                <flux:switch
                    wire:model="fuzzyMatchEnabled"
                    label="Fuzzy plate matching"
                    description="Corrects single-character OCR misreads against plates already on site. Turn off if your OCR is already very accurate."
                />

                <flux:input
                    wire:model="dwellAlertHours"
                    type="number"
                    label="Default dwell alert (hours)"
                    description="How long a vehicle sits before Security shows an alert. Tenants can override per-site."
                />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit">Save feature flags</flux:button>
                </div>
            </form>
        </x-panel>
    @endif
</div>
