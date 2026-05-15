<?php

namespace App\Jobs;

use App\Models\MedicationSchemaUpload;
use App\Services\MedicationSchemaImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMedicationSchemaUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $uploadId) {}

    public function handle(MedicationSchemaImporter $importer): void
    {
        $upload = MedicationSchemaUpload::findOrFail($this->uploadId);
        $importer->process($upload);
    }
}
