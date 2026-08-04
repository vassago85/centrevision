@php
    /**
     * Only shown when the app is explicitly a demo build. Users copy a row into
     * the login form to browse the app from each role.
     *
     * Keyed on TRAFFICFLOW_DEMO_MODE so a paying tenant deployment never leaks
     * a credentials panel, even if the seeder is accidentally run.
     */
    $demoMode = (bool) config('trafficflow.demo_mode');

    $accounts = [
        ['Owner admin',       'owner@centrevision.co.za',    'Full centre owner: all sites, cameras, security, billing.'],
        ['Security operator', 'security@centrevision.co.za', 'A guard hired by the owner: plate-level data, watchlist and read-only cameras — no billing.'],
        ['Shop admin',        'shop@centrevision.co.za',     'Sub-account: aggregate view of the site their shop trades in.'],
        ['Shop viewer',       'viewer@centrevision.co.za',   'Read-only shop user.'],
        ['Platform',          'platform@centrevision.co.za', 'CentreVision staff: cross-tenant view, partners, payouts.'],
    ];

    $password = \Database\Seeders\DemoDataSeeder::PASSWORD;
@endphp

@if ($demoMode)
    <div
        x-data="{
            fill(email) {
                let form = this.$root.closest('body').querySelector('form[action$=\'/login\']');
                if (! form) return;
                form.querySelector('input[name=email]').value = email;
                form.querySelector('input[name=password]').value = @js($password);
            }
        }"
        class="flex flex-col gap-3 rounded-tf border border-line bg-canvas p-4 text-[13px]"
    >
        <div class="flex items-center justify-between gap-2">
            <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-accent">Demo accounts</span>
            <span class="text-[11px] text-ink-muted">password: <code class="rounded bg-surface px-1.5 py-0.5 text-ink">{{ $password }}</code></span>
        </div>

        <ul class="flex flex-col divide-y divide-line">
            @foreach ($accounts as [$label, $email, $description])
                <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <div class="min-w-0">
                        <div class="font-semibold text-ink">{{ $label }}</div>
                        <div class="truncate text-ink-muted">{{ $email }}</div>
                    </div>
                    <button
                        type="button"
                        x-on:click="fill(@js($email))"
                        class="shrink-0 rounded-md border border-line bg-surface px-2.5 py-1 text-[12px] font-medium text-ink transition-colors hover:border-accent hover:text-accent"
                    >
                        Fill
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
