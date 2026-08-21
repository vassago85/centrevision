<?php

namespace App\Support\Analytics;

use App\Models\PlateEvent;
use App\Support\PlateNumber;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owner + security-operator view of every plate detection at their site.
 *
 * Reads plate_events (never visits) so that entry-only sites and unmatched
 * detections still show up — the security desk wants to see the raw camera
 * feed of the day, not the derived visit pairs. The global SiteScope on
 * PlateEvent narrows to sites the tenant can reach; callers pass filters
 * for the visible date window, camera and plate search on top of that.
 */
class PlateActivityLog
{
    /**
     * A paginated log for the current tenant, oldest filters left to the
     * caller so the same helper drives both the full list and the "one
     * plate" drill-down without duplicating query logic.
     *
     * $plateNumber is compared against the *normalised* form (letters and
     * digits only, upper-cased) so operators can type "JD 45 GP" or
     * "jd45gp" and get the same rows back.
     */
    public function paginate(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $cameraId = null,
        ?string $plateNumber = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return $this->baseQuery($from, $to, $cameraId, $plateNumber)
            ->with('camera:id,name')
            ->orderByDesc('captured_at')
            ->paginate($perPage);
    }

    /**
     * Distinct plate count over the same filters — used by the header
     * summary so operators know how many vehicles the current view spans
     * without having to page through every row.
     */
    public function uniquePlates(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $cameraId = null,
        ?string $plateNumber = null,
    ): int {
        return $this->baseQuery($from, $to, $cameraId, $plateNumber)
            ->distinct()
            ->count('plate_number');
    }

    /**
     * @return Builder<PlateEvent>
     */
    protected function baseQuery(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $cameraId,
        ?string $plateNumber,
    ): Builder {
        $query = PlateEvent::query()
            ->whereBetween('captured_at', [$from, $to]);

        if ($cameraId !== null) {
            $query->where('camera_id', $cameraId);
        }

        if ($plateNumber !== null && $plateNumber !== '') {
            $normalised = PlateNumber::normalise($plateNumber);

            // Substring match: operators typically remember the numeric
            // middle of a plate ("045") rather than the whole thing.
            $query->where('plate_number', 'like', '%'.$normalised.'%');
        }

        return $query;
    }
}
