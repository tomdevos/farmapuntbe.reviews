<?php

namespace App\Http\Controllers;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\ReviewExport;
use App\Services\Export\DocxReviewExporter;
use App\Services\Export\PdfReviewExporter;
use App\Services\Export\ResidentGrouping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewExportController extends Controller
{
    public function create()
    {
        return view('exports.create', [
            'careCenters' => CareCenter::with('departments')->orderBy('name')->get(),
            'residents' => Resident::with('department.careCenter')
                ->whereNull('archived_at')
                ->orderBy('last_name')
                ->get(),
        ]);
    }

    public function store(Request $request, PdfReviewExporter $pdf, DocxReviewExporter $docx)
    {
        $data = $request->validate([
            'scope' => 'required|in:resident,department,care_center',
            'cc' => 'nullable|integer|exists:care_centers,id',
            'dept' => 'nullable|integer|exists:departments,id',
            'res' => 'nullable|integer|exists:residents,id',
            'format' => 'required|in:pdf,docx',
            'sort' => 'nullable|in:' . implode(',', ResidentGrouping::SORTS),
        ]);

        // Server-side scope→id resolution so the form doesn't depend on JS.
        $scopeIdField = match ($data['scope']) {
            'care_center' => 'cc',
            'department' => 'dept',
            'resident' => 'res',
        };
        $scopeId = $data[$scopeIdField] ?? null;
        if (! $scopeId) {
            return back()
                ->withInput()
                ->withErrors([$scopeIdField => 'Selecteer een ' . $data['scope'] . '.']);
        }

        $sort = $data['sort'] ?? ResidentGrouping::SORT_ALPHABETICAL;
        $userId = $request->user()?->id;
        try {
            $export = match ([$data['scope'], $data['format']]) {
                ['resident', 'pdf'] => $pdf->exportResident(Resident::findOrFail($scopeId), null, $userId, $sort),
                ['department', 'pdf'] => $pdf->exportDepartment(Department::findOrFail($scopeId), $userId, $sort),
                ['care_center', 'pdf'] => $pdf->exportCareCenter(CareCenter::findOrFail($scopeId), $userId, $sort),
                ['resident', 'docx'] => $docx->exportResident(Resident::findOrFail($scopeId), null, $userId, $sort),
                ['department', 'docx'] => $docx->exportDepartment(Department::findOrFail($scopeId), $userId, $sort),
                ['care_center', 'docx'] => $docx->exportCareCenter(CareCenter::findOrFail($scopeId), $userId, $sort),
            };
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['export' => 'Export mislukt: ' . $e->getMessage()]);
        }

        return redirect()->route('exports.download', $export);
    }

    public function download(ReviewExport $export)
    {
        $disk = Storage::disk('local');
        $relative = 'private/' . ltrim($export->path, '/');
        if (! $disk->exists($relative)) {
            $relative = ltrim($export->path, '/');
        }
        abort_unless($disk->exists($relative), 404);

        return $disk->download($relative, basename($export->path));
    }
}
