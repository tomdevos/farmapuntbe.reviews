<?php

namespace App\Http\Controllers;

use App\Jobs\FetchPhilInteractions;
use App\Models\CareCenter;
use App\Models\Department;
use App\Models\MedicationSchedule;
use App\Models\Resident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhilBulkController extends Controller
{
    public function forDepartment(Request $request, Department $department)
    {
        if (! env('PHIL_USER') || ! env('PHIL_PASS')) {
            return back()->withErrors(['phil' => 'Phil-credentials ontbreken in .env (PHIL_USER, PHIL_PASS).']);
        }

        [$queued, $skipped] = $this->dispatchForResidents(
            Resident::where('department_id', $department->id)->whereNull('archived_at')->get()
        );

        return back()->with('status', "Phil-fetch ingepland voor {$queued} bewoner(s) van afdeling {$department->name}." . ($skipped > 0 ? " {$skipped} bewoner(s) overgeslagen (geen CNK's)." : ''));
    }

    public function forCareCenter(Request $request, CareCenter $careCenter)
    {
        if (! env('PHIL_USER') || ! env('PHIL_PASS')) {
            return back()->withErrors(['phil' => 'Phil-credentials ontbreken in .env (PHIL_USER, PHIL_PASS).']);
        }

        $residents = Resident::whereIn('department_id', $careCenter->departments()->pluck('id'))
            ->whereNull('archived_at')
            ->get();

        [$queued, $skipped] = $this->dispatchForResidents($residents);

        return back()->with('status', "Phil-fetch ingepland voor {$queued} bewoner(s) van {$careCenter->name}." . ($skipped > 0 ? " {$skipped} bewoner(s) overgeslagen (geen CNK's)." : ''));
    }

    /**
     * @return array{0: int, 1: int} [queued, skipped]
     */
    private function dispatchForResidents($residents): array
    {
        // Pre-filter: only residents with at least one non-9999 CNK on an
        // active schedule. Saves the worker from churning through empty cases.
        $residentIdsWithCnks = MedicationSchedule::query()
            ->whereIn('resident_id', $residents->pluck('id'))
            ->whereIn('schedule_type', [
                MedicationSchedule::TYPE_CHRONIC,
                MedicationSchedule::TYPE_TEMP,
                MedicationSchedule::TYPE_PRN,
            ])
            ->join('medications', 'medications.id', '=', 'medication_schedules.medication_id')
            ->where('medications.cnk', 'not like', '9999%')
            ->distinct()
            ->pluck('medication_schedules.resident_id')
            ->all();
        $eligibleIds = array_flip($residentIdsWithCnks);

        $queued = 0;
        $skipped = 0;
        foreach ($residents as $resident) {
            if (! isset($eligibleIds[$resident->id])) {
                $skipped++;
                continue;
            }
            FetchPhilInteractions::dispatch($resident->id);
            $queued++;
        }
        return [$queued, $skipped];
    }
}
