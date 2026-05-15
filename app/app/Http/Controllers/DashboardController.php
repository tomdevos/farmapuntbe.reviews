<?php

namespace App\Http\Controllers;

use App\Models\CareCenter;
use App\Models\MedicationSchemaUpload;
use App\Models\Resident;
use App\Models\Review;

class DashboardController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $searchResults = null;
        if ($q !== '') {
            $searchResults = Resident::query()
                ->whereNull('archived_at')
                ->where(function ($qq) use ($q) {
                    $qq->where('last_name', 'like', "%{$q}%")
                       ->orWhere('first_name', 'like', "%{$q}%")
                       ->orWhere('slug', 'like', "%{$q}%");
                })
                ->with('department.careCenter')
                ->orderBy('last_name')
                ->take(20)
                ->get();
        }

        return view('dashboard', [
            'careCenters' => CareCenter::with('departments')->orderBy('name')->get(),
            'residentCount' => Resident::whereNull('archived_at')->count(),
            'openReviews' => Review::where('status', Review::STATUS_DRAFT)
                ->with('resident.department.careCenter')
                ->latest('started_on')
                ->take(10)
                ->get(),
            'recentUploads' => MedicationSchemaUpload::with('careCenter', 'user')
                ->latest('uploaded_at')
                ->take(5)
                ->get(),
            'q' => $q,
            'searchResults' => $searchResults,
        ]);
    }
}
