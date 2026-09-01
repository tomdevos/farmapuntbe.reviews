<?php

namespace App\Console\Commands;

use App\Models\CareCenter;
use App\Models\MedicationSchemaUpload;
use App\Services\MedicationSchemaImporter;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SchemaImport extends Command
{
    protected $signature = 'schema:import
                            {file : Absolute or relative path to a medication-schema xlsx}
                            {--care-center= : Care center name (defaults to filename prefix)}';

    protected $description = 'CLI alternative to the upload UI: ingest a medication-schema xlsx for a care center.';

    public function handle(MedicationSchemaImporter $importer): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $file = base_path($file);
        }
        if (! is_file($file)) {
            $this->error("File not found: {$this->argument('file')}");
            return self::FAILURE;
        }

        $basename = basename($file);
        $ccName = $this->option('care-center');
        if (! $ccName) {
            $prefix = preg_split('/[_\-\s]/', pathinfo($basename, PATHINFO_FILENAME))[0] ?? 'onbekend';
            $ccName = ucfirst($prefix);
        }
        $careCenter = CareCenter::firstOrCreate(
            ['slug' => Slug::make($ccName)],
            ['name' => $ccName],
        );

        $storedPath = 'uploads/medication-schemas/' . date('Y/m/d') . '/' . uniqid('cli-') . '-' . $basename;
        Storage::disk('local')->put($storedPath, file_get_contents($file));

        $upload = MedicationSchemaUpload::create([
            'user_id' => null,
            'care_center_id' => $careCenter->id,
            'filename' => $basename,
            'stored_path' => $storedPath,
            'uploaded_at' => now(),
        ]);

        $this->info("Processing upload #{$upload->id} for care center '{$careCenter->name}'...");
        $summary = $importer->process($upload);

        $this->info("Residents: {$summary['residents']}");
        foreach ($summary['schedules'] as $type => $count) {
            $this->info("  - {$type}: {$count}");
        }
        $this->info("Unknown CNKs stubbed: " . count($summary['unknown_cnks']));
        if ($dropped = $summary['dropped_rows'] ?? []) {
            $this->warn('Regels zonder bruikbare CNK (niet geïmporteerd): ' . count($dropped));
            foreach ($dropped as $d) {
                $this->line("  {$d['resident']} — {$d['name']} (CNK \"{$d['cnk']}\")");
            }
        }
        return self::SUCCESS;
    }
}
