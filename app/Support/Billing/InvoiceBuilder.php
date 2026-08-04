<?php

namespace App\Support\Billing;

use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationType;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a month's charges into invoices.
 *
 * Owners get one consolidated invoice with a line per site, because an owner
 * running six malls does not want six debit orders. Shops get a single-line
 * invoice for their flat fee.
 *
 * Generation is idempotent per billable and period: the monthly job can be
 * retried, or run twice by a nervous operator, without double-billing anyone.
 */
class InvoiceBuilder
{
    public function __construct(protected BillingCalculator $calculator) {}

    public function forOwner(Organization $owner, CarbonInterface $periodStart): Invoice
    {
        $period = $this->period($periodStart);

        if ($existing = $this->existingInvoice($owner, $period['start'])) {
            return $existing;
        }

        // Charges are proration-aware, so passing the period start keeps a
        // site added on the 20th billed for the 20th → 30th only.
        $charges = $this->calculator->chargesForOwner($owner, $period['start']);

        // Seat-based line is flat across all sites, so it lives on the owner
        // invoice as its own single line rather than being folded into any
        // one site's charge.
        $operatorSeats = $this->calculator->securityOperatorSeatCount($owner);
        $operatorSeatTotal = $this->calculator->securityOperatorSeatCharge($owner);

        return DB::transaction(function () use ($owner, $period, $charges, $operatorSeats, $operatorSeatTotal): Invoice {
            $subtotal = $charges->sum(fn (SiteCharge $c) => $c->total()) + $operatorSeatTotal;

            $invoice = $this->createInvoice($owner, $period, $subtotal);

            foreach ($charges as $charge) {
                $this->linesFor($invoice, $charge);
            }

            if ($operatorSeats > 0) {
                $rate = (float) config('trafficflow.security_operator_monthly_amount');

                $invoice->lines()->create([
                    'site_id' => null,
                    'kind' => InvoiceLineKind::SecurityOperatorSeats,
                    'label' => sprintf(
                        'Security operator seats — %d × R%s',
                        $operatorSeats,
                        number_format($rate, 2),
                    ),
                    'amount' => $operatorSeatTotal,
                    'meta' => ['seats' => $operatorSeats, 'rate' => $rate],
                ]);
            }

            return $invoice->load('lines');
        });
    }

    public function forShop(Organization $shop, CarbonInterface $periodStart): Invoice
    {
        $period = $this->period($periodStart);

        if ($existing = $this->existingInvoice($shop, $period['start'])) {
            return $existing;
        }

        $amount = (float) ($shop->shopSubscription->monthly_amount ?? 0);

        return DB::transaction(function () use ($shop, $period, $amount): Invoice {
            $invoice = $this->createInvoice($shop, $period, $amount);

            $invoice->lines()->create([
                'site_id' => $shop->parent_site_id,
                'kind' => InvoiceLineKind::ShopSubscription,
                'label' => config('app.name').' access — '.$period['start']->format('F Y'),
                'amount' => $amount,
            ]);

            return $invoice->load('lines');
        });
    }

    /**
     * Everyone due an invoice for the month: owners with at least one site,
     * and shops whose subscription is being paid. Trialing shops are skipped
     * so their first invoice arrives when the trial ends.
     *
     * @return Collection<int, Invoice>
     */
    public function generateForPeriod(CarbonInterface $periodStart): Collection
    {
        $invoices = collect();

        Organization::query()
            ->where('type', OrganizationType::Owner)
            ->whereHas('sites')
            ->each(function (Organization $owner) use ($periodStart, $invoices): void {
                $invoices->push($this->forOwner($owner, $periodStart));
            });

        Organization::query()
            ->where('type', OrganizationType::Shop)
            ->whereHas('shopSubscription', fn ($query) => $query->where('status', SubscriptionStatus::Active))
            ->each(function (Organization $shop) use ($periodStart, $invoices): void {
                $invoices->push($this->forShop($shop, $periodStart));
            });

        return $invoices;
    }

    protected function linesFor(Invoice $invoice, SiteCharge $charge): void
    {
        // A brand-new site with zero cameras has no charge to write; skipping
        // the lines entirely keeps the invoice tidy rather than parading three
        // R0.00 rows at the operator.
        if ($charge->total() <= 0.0 && $charge->cameraCount === 0) {
            return;
        }

        $suffix = $charge->wasProrated()
            ? sprintf(' (prorated %s%%)', number_format($charge->prorationFactor * 100, 1))
            : '';

        $invoice->lines()->create([
            'site_id' => $charge->site->getKey(),
            'kind' => InvoiceLineKind::BaseFee,
            'label' => $charge->site->name.' — '.$charge->tier->label().' base fee'.$suffix,
            'amount' => $charge->baseFee,
            'meta' => $charge->meta(),
        ]);

        if ($charge->cameraSurcharge > 0) {
            $invoice->lines()->create([
                'site_id' => $charge->site->getKey(),
                'kind' => InvoiceLineKind::CameraSurcharge,
                'label' => $charge->site->name.' — additional cameras'.$suffix,
                'amount' => $charge->cameraSurcharge,
                'meta' => $charge->meta(),
            ]);
        }

        // A site with no paying shops still gets the line at zero, so the
        // owner can see the resale opportunity they are not using.
        $invoice->lines()->create([
            'site_id' => $charge->site->getKey(),
            'kind' => InvoiceLineKind::VariableFee,
            'label' => sprintf(
                '%s — %d cameras × %d shops%s',
                $charge->site->name,
                $charge->cameraCount,
                $charge->payingShopCount,
                $suffix,
            ),
            'amount' => $charge->variableFee,
            'meta' => $charge->meta(),
        ]);
    }

    /**
     * @param  array{start: CarbonInterface, end: CarbonInterface}  $period
     */
    protected function createInvoice(Organization $billable, array $period, float $amount): Invoice
    {
        return $billable->invoices()->create([
            'number' => Invoice::nextNumber($period['start']),
            'amount' => round($amount, 2),
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'status' => InvoiceStatus::Pending,
        ]);
    }

    protected function existingInvoice(Organization $billable, CarbonInterface $periodStart): ?Invoice
    {
        return $billable->invoices()
            ->where('period_start', $periodStart->toDateString())
            ->where('status', '!=', InvoiceStatus::Void)
            ->first();
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}
     */
    protected function period(CarbonInterface $periodStart): array
    {
        // copy() first: the caller may hand us a mutable Carbon and would not
        // expect its own variable to move.
        return [
            'start' => $periodStart->copy()->startOfMonth(),
            'end' => $periodStart->copy()->endOfMonth(),
        ];
    }
}
