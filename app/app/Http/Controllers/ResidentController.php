<?php

namespace App\Http\Controllers;

use App\Models\MedicationSchedule;
use App\Models\Resident;

class ResidentController extends Controller
{
    public function show(Resident $resident)
    {
        $resident->load([
            'department.careCenter',
            'reviews.user',
        ]);

        $schedules = $resident->schedules()
            ->with(['medication.activeIngredients'])
            ->get()
            ->groupBy('schedule_type');

        return view('residents.show', [
            'resident' => $resident,
            'chronic' => $schedules[MedicationSchedule::TYPE_CHRONIC] ?? collect(),
            'temp' => $schedules[MedicationSchedule::TYPE_TEMP] ?? collect(),
            'prn' => $schedules[MedicationSchedule::TYPE_PRN] ?? collect(),
            'forbidden' => $schedules[MedicationSchedule::TYPE_FORBIDDEN] ?? collect(),
        ]);
    }
}
