<?php

namespace App\Jobs;

use App\Support\Billing\InvoiceBuilder;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Bills everyone for a month.
 *
 * Runs on the first of the month for the month just finished, since the
 * variable fee depends on how many shops were actually paying and that is only
 * known once the period is over.
 */
class GenerateMonthlyInvoices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public ?string $periodStart = null) {}

    public function uniqueId(): string
    {
        return $this->period()->format('Y-m');
    }

    public function handle(InvoiceBuilder $builder): void
    {
        $period = $this->period();
        $invoices = $builder->generateForPeriod($period);

        Log::info('Generated monthly invoices', [
            'period' => $period->format('Y-m'),
            'count' => $invoices->count(),
            'total' => round($invoices->sum(fn ($invoice) => (float) $invoice->amount), 2),
        ]);
    }

    protected function period(): CarbonInterface
    {
        return $this->periodStart !== null
            ? Date::parse($this->periodStart)->startOfMonth()
            : Date::now()->subMonthNoOverflow()->startOfMonth();
    }
}
