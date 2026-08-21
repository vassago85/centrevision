<?php

namespace App\Jobs;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\Alerts\AlertEvaluator;
use App\Support\Alerts\AlertSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluatePatternAlertRules implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'evaluate-pattern-alerts';
    }

    public function handle(AlertEvaluator $evaluator): void
    {
        Site::query()
            ->withoutGlobalScope(SiteScope::class)
            ->cursor()
            ->each(function (Site $site) use ($evaluator): void {
                if (! AlertSettings::for($site)->enabled()) {
                    return;
                }

                $evaluator->evaluateDwellForSite($site);
                $evaluator->evaluateMultiEntryForSite($site);
                $evaluator->evaluateOddHourForSite($site);
            });
    }
}
