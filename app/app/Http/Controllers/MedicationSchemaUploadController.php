<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMedicationSchemaUpload;
use App\Models\CareCenter;
use App\Models\MedicationSchemaUpload;
use App\Services\MedicationSchemaImporter;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MedicationSchemaUploadController extends Controller
{
    public function create()
    {
        return view('uploads.create', [
            'careCenters' => CareCenter::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, MedicationSchemaImporter $importer)
    {
        $data = $request->validate([
            'care_center_name' => 'required_without:care_center_id|nullable|string|max:120',
            'care_center_id' => 'required_without:care_center_name|nullable|exists:care_centers,id',
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
            'process_now' => 'nullable|boolean',
        ]);

        $careCenter = $data['care_center_id']
            ? CareCenter::findOrFail($data['care_center_id'])
            : CareCenter::firstOrCreate(
                ['slug' => Slug::make($data['care_center_name'])],
                ['name' => $data['care_center_name']],
            );

        $file = $request->file('file');
        $stored = $file->store('uploads/medication-schemas/' . date('Y/m/d'), 'local');

        $upload = MedicationSchemaUpload::create([
            'user_id' => $request->user()?->id,
            'care_center_id' => $careCenter->id,
            'filename' => $file->getClientOriginalName(),
            'stored_path' => $stored,
            'uploaded_at' => now(),
        ]);

        if ($request->boolean('process_now', true)) {
            $importer->process($upload);
        } else {
            ProcessMedicationSchemaUpload::dispatch($upload->id);
        }

        return redirect()->route('uploads.show', $upload);
    }

    public function show(MedicationSchemaUpload $upload)
    {
        $upload->load('careCenter', 'user');
        return view('uploads.show', ['upload' => $upload]);
    }
}
