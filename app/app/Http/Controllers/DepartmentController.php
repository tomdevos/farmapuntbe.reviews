<?php

namespace App\Http\Controllers;

use App\Models\Department;

class DepartmentController extends Controller
{
    public function show(Department $department)
    {
        $department->load(['careCenter', 'residents' => fn ($q) => $q
            ->whereNull('archived_at')
            ->orderBy('last_name')
            ->orderBy('first_name')]);

        return view('departments.show', ['department' => $department]);
    }
}
