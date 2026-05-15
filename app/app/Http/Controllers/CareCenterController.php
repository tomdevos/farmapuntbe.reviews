<?php

namespace App\Http\Controllers;

use App\Models\CareCenter;

class CareCenterController extends Controller
{
    public function index()
    {
        return view('care-centers.index', [
            'careCenters' => CareCenter::withCount('departments')->orderBy('name')->get(),
        ]);
    }

    public function show(CareCenter $careCenter)
    {
        $careCenter->load(['departments' => fn ($q) => $q->orderBy('name')->withCount('residents')]);
        return view('care-centers.show', ['careCenter' => $careCenter]);
    }
}
