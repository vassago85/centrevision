<?php

namespace App\Jobs;

use App\Enums\OrganizationType;
use App\Enums\PayoutStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
use App\Models\Scopes\SiteScope;
use App\Models\SiteSubscription;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Works out what each referring partner earned for a month.
 *
 * Commission is paid on money actually received, not on money invoiced: an
 * owner who never paid earns the partner nothing.
 *
 * A site handshake names the partner who brought that mall. That partner
 * earns their commission_rate on every paid line for the site — base fee,
 * extra cameras, shop variable — so a 1/3 installer split applies to
 * whatever business they bring, not a one-off rand cut. Sites without a
 * handshake still fall back to the owner's referred-by partner at that
 * partner's rate.
 *
 * Reruns overwrite a pending payout — an invoice paid late should still be
 * picked up — but never touch one that has already been paid out.
 */
class GeneratePartnerPayouts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public ?string $periodStart = null) {}

    public function uniqueId(): string
    {
        return $this->period()->format('Y-m');
    }

    public function handle(): void
    {
        $start = $this->period();
        $end = $start->copy()->endOfMonth();
        $buckets = $this->attributePaidInvoices($start, $end);

        Partner::query()->each(function (Partner $partner) use ($start, $end, $buckets): void {
            $this->payoutFor($partner, $start, $end, $buckets[$partner->getKey()] ?? 0.0);
        });
    }

    protected function payoutFor(Partner $partner, CarbonInterface $start, CarbonInterface $end, float $revenue): void
    {
        $existing = PartnerPayout::query()
            ->where('partner_id', $partner->getKey())
            ->where('period_start', $start->toDateString())
            ->first();

        if ($existing?->status === PayoutStatus::Paid) {
            return;
        }

        $revenue = round($revenue, 2);
        $rate = (float) $partner->commission_rate;

        $attributes = [
            'period_end' => $end,
            'revenue_base' => $revenue,
            'commission_rate' => $rate,
            'commission_amount' => $partner->shareOf($revenue),
            'status' => PayoutStatus::Pending,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        PartnerPayout::create([
            'partner_id' => $partner->getKey(),
            'period_start' => $start,
            ...$attributes,
        ]);

        Log::info('Generated partner payout', [
            'partner_id' => $partner->getKey(),
            'period' => $start->format('Y-m'),
            'revenue_base' => $revenue,
        ]);
    }

    /**
     * Walk every paid invoice in the period and bucket amounts per partner.
     *
     * @return array<int, float>
     */
    protected function attributePaidInvoices(CarbonInterface $start, CarbonInterface $end): array
    {
        $buckets = [];

        Invoice::query()
            ->paid()
            ->with(['lines', 'billable'])
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->each(function (Invoice $invoice) use (&$buckets): void {
                $this->attributeInvoice($invoice, $buckets);
            });

        return $buckets;
    }

    /**
     * @param  array<int, float>  $buckets
     */
    protected function attributeInvoice(Invoice $invoice, array &$buckets): void
    {
        if ($invoice->lines->isEmpty()) {
            $partnerId = $this->fallbackPartnerId($invoice);

            if ($partnerId !== null) {
                $this->addRevenue($buckets, $partnerId, (float) $invoice->amount);
            }

            return;
        }

        foreach ($invoice->lines as $line) {
            $this->attributeLine($invoice, $line, $buckets);
        }
    }

    /**
     * @param  array<int, float>  $buckets
     */
    protected function attributeLine(Invoice $invoice, InvoiceLine $line, array &$buckets): void
    {
        $meta = $line->meta ?? [];
        $partnerId = isset($meta['partner_id']) ? (int) $meta['partner_id'] : null;

        if ($partnerId === 0) {
            $partnerId = null;
        }

        $partnerId ??= $this->fallbackPartnerId($invoice, $line);

        if ($partnerId === null) {
            return;
        }

        $this->addRevenue($buckets, $partnerId, (float) $line->amount);
    }

    /**
     * Owner invoices without a site handshake land on the referred-by partner.
     * Shop invoices inherit the parent site's agreement partner, then the
     * owner referrer.
     */
    protected function fallbackPartnerId(Invoice $invoice, ?InvoiceLine $line = null): ?int
    {
        $billable = $invoice->billable;

        if (! $billable instanceof Organization) {
            return null;
        }

        if ($billable->type === OrganizationType::Shop) {
            $siteId = $line?->site_id ?? $billable->parent_site_id;

            if ($siteId !== null) {
                $subscription = SiteSubscription::query()
                    ->withoutGlobalScope(SiteScope::class)
                    ->where('site_id', $siteId)
                    ->first();

                if ($subscription?->partner_id) {
                    return (int) $subscription->partner_id;
                }
            }

            return $billable->commissionPartner()?->getKey();
        }

        return $billable->referred_by_partner_id;
    }

    /**
     * @param  array<int, float>  $buckets
     */
    protected function addRevenue(array &$buckets, int $partnerId, float $amount): void
    {
        $buckets[$partnerId] = ($buckets[$partnerId] ?? 0.0) + $amount;
    }

    protected function period(): CarbonInterface
    {
        return $this->periodStart !== null
            ? Date::parse($this->periodStart)->startOfMonth()
            : Date::now()->subMonthNoOverflow()->startOfMonth();
    }
}
