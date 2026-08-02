<?php

namespace App\Jobs;

use App\Enums\PayoutStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Partner;
use App\Models\PartnerPayout;
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
 * owner who never paid earns the partner nothing. Shops inherit the partner of
 * the owner whose site they trade in, so their fees count too.
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

        Partner::query()->with('organizations')->each(function (Partner $partner) use ($start, $end): void {
            $this->payoutFor($partner, $start, $end);
        });
    }

    protected function payoutFor(Partner $partner, CarbonInterface $start, CarbonInterface $end): void
    {
        $existing = PartnerPayout::query()
            ->where('partner_id', $partner->getKey())
            ->where('period_start', $start->toDateString())
            ->first();

        if ($existing?->status === PayoutStatus::Paid) {
            return;
        }

        $revenue = $this->settledRevenue($partner, $start, $end);
        $rate = (float) $partner->commission_rate;

        $attributes = [
            'period_end' => $end,
            'revenue_base' => $revenue,
            'commission_rate' => $rate,
            'commission_amount' => round($revenue * $rate, 2),
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
     * Everything paid in the period by the partner's owners and by the shops
     * sitting inside those owners' sites.
     */
    protected function settledRevenue(Partner $partner, CarbonInterface $start, CarbonInterface $end): float
    {
        $ownerIds = $partner->organizations->modelKeys();

        if ($ownerIds === []) {
            return 0.0;
        }

        $shopIds = Organization::query()
            ->select('id')
            ->whereIn('parent_site_id', function ($query) use ($ownerIds): void {
                $query->select('id')->from('sites')->whereIn('organization_id', $ownerIds);
            })
            ->pluck('id')
            ->all();

        return round((float) Invoice::query()
            ->paid()
            ->where('billable_type', (new Organization)->getMorphClass())
            ->whereIn('billable_id', [...$ownerIds, ...$shopIds])
            ->whereBetween('period_start', [$start->toDateString(), $end->toDateString()])
            ->sum('amount'), 2);
    }

    protected function period(): CarbonInterface
    {
        return $this->periodStart !== null
            ? Date::parse($this->periodStart)->startOfMonth()
            : Date::now()->subMonthNoOverflow()->startOfMonth();
    }
}
