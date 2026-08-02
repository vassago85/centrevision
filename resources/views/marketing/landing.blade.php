@php
    use App\Enums\BaseTier;

    // Ordered by tier ceiling, with the middle option flagged as recommended.
    $tiers = [
        [BaseTier::Starter,    '1 – 4 cameras',  'One entrance. First-visit dashboard, live security alerts, unlimited history.', false],
        [BaseTier::Standard,   '5 – 8 cameras',  'Multi-entrance sites and mid-size centres.',                                     true],
        [BaseTier::Large,      '9 – 16 cameras', 'Corridor-scale centres and mixed-use campuses.',                                 false],
        [BaseTier::Enterprise, '17+ cameras',    'Custom base plus per-camera pricing above 16.',                                  false],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-page font-sans text-ink antialiased">

    {{-- ── Nav ──────────────────────────────────────────────────────────── --}}
    <header class="border-b border-line bg-surface/80 backdrop-blur">
        <div class="mx-auto flex max-w-[1200px] items-center justify-between gap-4 px-6 py-4 lg:px-10">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <x-brand-mark class="!size-8" />
                <span class="text-[15px] font-semibold tracking-tight">
                    <span class="text-ink">centre</span><span class="text-accent">vision</span>
                </span>
            </a>

            <nav class="flex items-center gap-2">
                <flux:button :href="route('login')" variant="ghost" size="sm">Log in</flux:button>
                <flux:button :href="route('register')" variant="primary" size="sm" icon-trailing="arrow-right">Get started</flux:button>
            </nav>
        </div>
    </header>

    <main>
        {{-- ── Hero ─────────────────────────────────────────────────────── --}}
        <section class="relative overflow-hidden bg-gradient-to-br from-brand-navy via-brand-navy to-[#12325d] text-white">
            {{-- Soft glow so the hero doesn't read as a flat colour block. --}}
            <div class="pointer-events-none absolute -right-40 -top-40 size-[520px] rounded-full bg-accent/25 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-52 -left-40 size-[420px] rounded-full bg-accent/15 blur-3xl"></div>

            <div class="relative mx-auto grid max-w-[1200px] gap-16 px-6 py-24 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-center lg:px-10 lg:py-28">
                <div class="flex flex-col gap-7">
                    <span class="inline-flex w-fit items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1.5 text-[11.5px] font-semibold uppercase tracking-[0.16em] text-white/85 backdrop-blur">
                        <span class="size-1.5 rounded-full bg-positive"></span>
                        Month-to-month · cancel any month
                    </span>

                    <h1 class="text-[52px] font-bold leading-[1.05] tracking-tight sm:text-[64px]">
                        See every visit.<br>
                        <span class="bg-gradient-to-r from-white via-white to-[#7bb3ff] bg-clip-text text-transparent">Know every customer.</span>
                    </h1>

                    <p class="max-w-xl text-lg leading-relaxed text-white/80 sm:text-xl">
                        CentreVision turns the number-plate cameras already at your entrances into a live traffic and
                        security dashboard for your centre — and a shared analytics feed for your tenants. No contracts,
                        no lock-in, no per-user upcharges.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button :href="route('register')" variant="primary" size="base" icon-trailing="arrow-right" class="!bg-white !text-brand-navy hover:!bg-white/90">
                            Start a free 14-day trial
                        </flux:button>
                        <flux:button :href="route('login')" variant="ghost" size="base" class="!text-white/90 hover:!bg-white/10 hover:!text-white">
                            Sign in to demo
                        </flux:button>
                    </div>

                    <p class="text-sm text-white/60">
                        Works with your existing Hikvision ANPR cameras. Setup is a config file, not a rig-out.
                    </p>
                </div>

                {{-- Live-sample card. Smaller wordmark + stat cards, so the
                     numbers get the visual weight, not the logo. --}}
                <div class="relative rounded-2xl border border-white/10 bg-white/[0.04] p-7 shadow-2xl backdrop-blur">
                    <div class="mb-6 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-white/10 text-white">
                                <flux:icon icon="chart-bar" class="size-5" />
                            </span>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/60">Live sample</p>
                                <p class="text-sm font-semibold text-white">Two-site centre · last 7 days</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-positive/20 px-2 py-0.5 text-[11px] font-semibold text-positive">Live</span>
                    </div>

                    <dl class="grid grid-cols-3 gap-3">
                        @foreach ([
                            ['12,070', 'Visits', 'truck'],
                            ['45 min', 'Avg dwell', 'clock'],
                            ['16.6%',  'Return rate', 'arrow-path'],
                        ] as [$value, $label, $icon])
                            <div class="flex flex-col gap-2 rounded-xl bg-white/[0.06] p-4">
                                <span class="flex size-8 items-center justify-center rounded-full bg-white/10 text-white/90">
                                    <flux:icon :icon="$icon" class="size-4" />
                                </span>
                                <dt class="text-[10.5px] uppercase tracking-[0.14em] text-white/60">{{ $label }}</dt>
                                <dd class="text-[22px] font-semibold leading-none text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-6 grid grid-cols-2 gap-3 text-[12px] text-white/70">
                        @foreach (['Real-time plate capture', 'Dwell alerts &amp; watchlist', 'Sub-account analytics', 'POPIA-compliant retention'] as $feature)
                            <div class="flex items-center gap-2">
                                <flux:icon icon="check-circle" class="size-4 text-positive" />
                                <span>{!! $feature !!}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- ── What you get ─────────────────────────────────────────────── --}}
        <section class="bg-page py-24">
            <div class="mx-auto max-w-[1200px] px-6 lg:px-10">
                <div class="mx-auto mb-14 max-w-2xl text-center">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-accent">What CentreVision does</p>
                    <h2 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Three views, one feed.</h2>
                    <p class="mt-4 text-lg leading-relaxed text-ink-2">
                        Every registration plate that crosses your entrance becomes a visit. From that one feed we
                        answer three separate questions your centre keeps asking.
                    </p>
                </div>

                <div class="grid gap-6 md:grid-cols-3">
                    @foreach ([
                        ['Traffic',      'How busy is my centre?',       'chart-bar',            'Total visits and unique vehicles, dwell distribution, peak hours and busiest days, entrance-by-entrance share. Staff and delivery plates auto-detected and filtered out of the shopper view.'],
                        ['Security',     'Who is on-site right now?',    'shield-exclamation',   'Live dwell alerts for vehicles above your threshold, odd-hour recurring plates, multi-entry patterns, and watchlist matches. Nothing waits for a nightly rollup.'],
                        ['Sub-accounts', 'What can I offer my tenants?', 'user-group',           'Every shop in your centre gets its own aggregate dashboard — real footfall data for the mall they trade in, to roster and stock and market against. An extra reason to renew. Plate numbers never leave the security view.'],
                    ] as [$tag, $question, $icon, $body])
                        <article class="flex flex-col gap-5 rounded-2xl border border-line bg-surface p-7 shadow-tf-sm transition-shadow hover:shadow-tf-md">
                            <span class="flex size-12 items-center justify-center rounded-xl bg-accent-soft text-accent">
                                <flux:icon :icon="$icon" class="size-6" />
                            </span>
                            <div>
                                <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-accent">{{ $tag }}</span>
                                <h3 class="mt-2 text-xl font-semibold tracking-tight text-ink">{{ $question }}</h3>
                            </div>
                            <p class="text-[14.5px] leading-relaxed text-ink-2">{{ $body }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── For shops / tenants ──────────────────────────────────────────
             This is the third leg of "Three views, one feed" spelt out for the
             tenant audience. Centre managers sell CentreVision to shops on this
             pitch; shops give it real weight because it's operational data,
             not marketing copy. --}}
        <section class="bg-surface py-24">
            <div class="mx-auto max-w-[1200px] px-6 lg:px-10">
                <div class="grid gap-12 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:items-start">
                    <div class="flex flex-col gap-5 lg:sticky lg:top-16">
                        <span class="inline-flex w-fit items-center gap-2 rounded-full bg-accent-soft px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-accent">
                            <flux:icon icon="user-group" class="size-3.5" />
                            For tenants
                        </span>
                        <h2 class="text-4xl font-bold tracking-tight sm:text-5xl">
                            Every shop gets its own dashboard.
                        </h2>
                        <p class="text-lg leading-relaxed text-ink-2">
                            The centre sells you space; CentreVision hands your tenants a real-time footfall feed for
                            the mall they trade in. It's aggregate-only — no plate numbers leave the security desk —
                            so it's POPIA-safe to share.
                        </p>
                        <p class="text-[13px] text-ink-muted">
                            An extra reason for tenants to renew, and a small variable fee that pays for the platform.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            ['Roster smarter',              'user-group',           'Line rosters up with actual peaks and troughs, not last month\'s guess. Cut idle labour on quiet mornings and staff up before the rush.'],
                            ['Restock in the lulls',        'archive-box',          'Time deliveries and shelving to traffic troughs so aisles stay clear when shoppers actually arrive.'],
                            ['Time promos and activations', 'megaphone',            'Run pushes when the centre is busiest — or use them deliberately to punch up slow days. See the lift the next morning.'],
                            ['Prove your renewal case',     'document-chart-bar',   'Walk into lease negotiations with the same footfall data the landlord has. Fewer vibes, more numbers.'],
                            ['Measure campaign lift',       'arrow-trending-up',    'Ran a Mother\'s Day push? See whether centre traffic — or your slice of it — actually moved. Isolate weather and school terms.'],
                            ['Trend early-warnings',        'presentation-chart-line', 'Spot a Tuesday quietly dying before it becomes a quarter of dead Tuesdays. Compare periods, days of the week, month over month.'],
                            ['Return-visitor context',      'arrow-path',           'Know what share of centre visits are repeat vs new — frames how much you should be spending on loyalty vs acquisition.'],
                            ['Fair marketing-levy talks',   'scale',                'The mall\'s marketing contribution is easier to negotiate when both sides can see what it actually delivered.'],
                        ] as [$title, $icon, $body])
                            <article class="flex flex-col gap-3 rounded-xl border border-line bg-page p-5 transition-shadow hover:shadow-tf-sm">
                                <span class="flex size-9 items-center justify-center rounded-lg bg-accent-soft text-accent">
                                    <flux:icon :icon="$icon" class="size-5" />
                                </span>
                                <h3 class="text-[15px] font-semibold text-ink">{{ $title }}</h3>
                                <p class="text-[13.5px] leading-relaxed text-ink-2">{{ $body }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Pricing / no-lock-in ─────────────────────────────────────── --}}
        <section class="bg-page py-24">
            <div class="mx-auto max-w-[1200px] px-6 lg:px-10">
                {{-- No-contracts banner. --}}
                <div class="mb-14 flex flex-col gap-6 rounded-2xl bg-gradient-to-br from-brand-navy to-[#153a70] p-10 text-white shadow-tf-md lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-xl">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/70">No contracts</p>
                        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">
                            Month-to-month. Cancel any month.
                        </h2>
                        <p class="mt-3 text-white/80">
                            One tier fee based on how many cameras you connect. A small variable fee if you're reselling
                            sub-account access to tenants. That's the whole invoice.
                        </p>
                    </div>

                    <ul class="grid gap-2 text-sm">
                        @foreach ([
                            'Cancel any month — no window',
                            'No user-seat limits',
                            'CSV / PDF export any time',
                            'POPIA-compliant retention',
                        ] as $item)
                            <li class="flex items-center gap-2.5">
                                <flux:icon icon="check-circle" class="size-5 shrink-0 text-positive" />
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="mb-10 max-w-2xl">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-accent">Pricing</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">One plan per size of centre.</h2>
                </div>

                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($tiers as [$tier, $range, $body, $recommended])
                        <article @class([
                            'relative flex flex-col gap-5 rounded-2xl border p-7 shadow-tf-sm transition-shadow hover:shadow-tf-md',
                            'border-accent bg-surface shadow-tf-md ring-1 ring-accent' => $recommended,
                            'border-line bg-surface' => ! $recommended,
                        ])>
                            @if ($recommended)
                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-accent px-3 py-1 text-[10.5px] font-semibold uppercase tracking-[0.14em] text-white shadow-tf-sm">
                                    Recommended
                                </span>
                            @endif

                            <div>
                                <h3 class="text-lg font-semibold text-ink">{{ $tier->label() }}</h3>
                                <p class="mt-1 text-[12px] uppercase tracking-[0.14em] text-ink-muted">{{ $range }}</p>
                            </div>

                            <div>
                                @if ($tier === BaseTier::Enterprise)
                                    <p class="text-4xl font-bold text-ink">Custom</p>
                                    <p class="mt-1 text-sm text-ink-muted">Per camera above 16</p>
                                @else
                                    <p class="flex items-baseline gap-1 text-ink">
                                        <span class="text-4xl font-bold">R{{ number_format($tier->baseFee(), 0) }}</span>
                                        <span class="text-sm font-medium text-ink-muted">/ month</span>
                                    </p>
                                    <p class="mt-1 text-sm text-ink-muted">Excludes shop variable fee</p>
                                @endif
                            </div>

                            <p class="text-[13.5px] leading-relaxed text-ink-2">{{ $body }}</p>

                            <flux:button
                                :href="route('register')"
                                :variant="$recommended ? 'primary' : 'ghost'"
                                size="sm"
                                class="mt-auto w-full justify-center"
                                icon-trailing="arrow-right"
                            >
                                {{ $tier === BaseTier::Enterprise ? 'Contact sales' : 'Start trial' }}
                            </flux:button>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── How it works ─────────────────────────────────────────────── --}}
        <section class="bg-surface py-24">
            <div class="mx-auto max-w-[1200px] px-6 lg:px-10">
                <div class="mb-14 max-w-2xl">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-accent">Setup</p>
                    <h2 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Turn on your cameras, not your budget.</h2>
                </div>

                <ol class="grid gap-5 md:grid-cols-3">
                    @foreach ([
                        ['Point a camera',        'video-camera',        'Add each ANPR-enabled camera by IP and role (entrance or exit). We handle Hikvision alert streams and FTP drops out of the box.'],
                        ['Watch it fill up',      'chart-bar-square',    'Plate events start flowing within seconds. Visits pair up automatically. Staff and tenant plates get flagged after a week.'],
                        ['Invite your tenants',   'user-plus',           'Each shop gets their own login to the centre-wide aggregate view. You bill them directly through the platform.'],
                    ] as $i => [$title, $icon, $body])
                        <li class="flex flex-col gap-4 rounded-2xl border border-line bg-page p-7 shadow-tf-sm">
                            <div class="flex items-center gap-3">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent text-white shadow-tf-sm">
                                    <flux:icon :icon="$icon" class="size-5" />
                                </span>
                                <span class="rounded-full bg-accent-soft px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-accent">
                                    Step {{ $i + 1 }}
                                </span>
                            </div>
                            <h3 class="text-lg font-semibold text-ink">{{ $title }}</h3>
                            <p class="text-[14px] leading-relaxed text-ink-2">{{ $body }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ── Final CTA ────────────────────────────────────────────────── --}}
        <section class="relative overflow-hidden bg-gradient-to-br from-brand-navy to-[#153a70] py-24 text-white">
            <div class="pointer-events-none absolute -right-40 -top-40 size-[420px] rounded-full bg-accent/25 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-40 -left-40 size-[380px] rounded-full bg-accent/15 blur-3xl"></div>

            <div class="relative mx-auto flex max-w-[900px] flex-col items-center gap-8 px-6 text-center lg:px-10">
                <h2 class="text-4xl font-bold tracking-tight sm:text-5xl">See every visit. Know every customer.</h2>
                <p class="max-w-2xl text-lg text-white/80">
                    Two weeks free, cancel any month after. If your cameras are already installed, you'll have your
                    first dashboard by lunchtime.
                </p>
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <flux:button :href="route('register')" size="base" icon-trailing="arrow-right" class="!bg-white !text-brand-navy hover:!bg-white/90">
                        Start a free trial
                    </flux:button>
                    <flux:button :href="'mailto:'.config('trafficflow.billing_email')" variant="ghost" size="base" icon="envelope" class="!text-white/90 hover:!bg-white/10 hover:!text-white">
                        Talk to us
                    </flux:button>
                </div>
            </div>
        </section>
    </main>

    {{-- ── Footer ───────────────────────────────────────────────────────── --}}
    <footer class="border-t border-line bg-surface py-10">
        <div class="mx-auto max-w-[1200px] px-6 lg:px-10">
            <div class="grid gap-8 md:grid-cols-4">
                <div class="md:col-span-2">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                        <x-brand-mark class="!size-8" />
                        <span class="text-[15px] font-semibold tracking-tight">
                            <span class="text-ink">centre</span><span class="text-accent">vision</span>
                        </span>
                    </a>
                    <p class="mt-4 max-w-md text-sm leading-relaxed text-ink-2">
                        Number-plate analytics for shopping centres and mixed-use campuses. See every visit,
                        know every customer, keep your existing cameras.
                    </p>
                </div>

                <div class="flex flex-col gap-3">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Product</p>
                    <a href="{{ route('login') }}" class="text-sm text-ink-2 hover:text-ink">Log in</a>
                    <a href="{{ route('register') }}" class="text-sm text-ink-2 hover:text-ink">Sign up</a>
                    <a href="#" class="text-sm text-ink-2 hover:text-ink">Live demo</a>
                </div>

                <div class="flex flex-col gap-3">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">Contact</p>
                    <a href="mailto:{{ config('trafficflow.billing_email') }}" class="text-sm text-ink-2 hover:text-ink">{{ config('trafficflow.billing_email') }}</a>
                    <p class="text-sm text-ink-muted">South Africa · GMT+2</p>
                </div>
            </div>

            <div class="mt-10 flex flex-col items-start justify-between gap-3 border-t border-line pt-6 text-[13px] text-ink-muted sm:flex-row sm:items-center">
                <span>© {{ now()->year }} {{ config('app.name') }}. All rights reserved.</span>
                <span>POPIA-compliant · Hosted in South Africa</span>
            </div>
        </div>
    </footer>

    @fluxScripts
</body>
</html>
