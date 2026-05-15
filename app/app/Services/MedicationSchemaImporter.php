<?php

namespace App\Services;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Medication;
use App\Models\MedicationSchedule;
use App\Models\MedicationSchemaUpload;
use App\Models\Resident;
use App\Support\Slug;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class MedicationSchemaImporter
{
    private const SHEETS = [
        MedicationSchedule::TYPE_CHRONIC => 'Chronische medicatie',
        MedicationSchedule::TYPE_TEMP => 'Tijdelijke medicatie',
        MedicationSchedule::TYPE_PRN => 'Indien nodig medicatie',
        MedicationSchedule::TYPE_FORBIDDEN => 'Verboden medicatie',
    ];

    private const TIME_SLOTS = ['06:00', '08:00', '12:00', '14:00', '17:00', '18:00', '20:00', '22:00'];

    /**
     * Returns a summary array: counts of residents/schedules per type +
     * unknown CNKs encountered (auto-stubbed).
     */
    public function process(MedicationSchemaUpload $upload): array
    {
        $upload->update([
            'status' => MedicationSchemaUpload::STATUS_PROCESSING,
        ]);

        try {
            $summary = DB::transaction(function () use ($upload) {
                return $this->run($upload);
            });
            $upload->update([
                'status' => MedicationSchemaUpload::STATUS_PROCESSED,
                'processed_at' => now(),
                'summary' => $summary,
            ]);
            return $summary;
        } catch (\Throwable $e) {
            $upload->update([
                'status' => MedicationSchemaUpload::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function run(MedicationSchemaUpload $upload): array
    {
        $careCenter = $upload->careCenter;
        $path = storage_path('app/private/' . ltrim($upload->stored_path, '/'));
        if (! is_file($path)) {
            $path = storage_path('app/' . ltrim($upload->stored_path, '/'));
        }
        if (! is_file($path)) {
            throw new \RuntimeException("Upload file not found at {$upload->stored_path}");
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $rowsBySheet = [];
        foreach (self::SHEETS as $type => $sheetName) {
            $rowsBySheet[$type] = $this->readSheet($spreadsheet, $sheetName);
        }

        $residentsByKey = $this->groupByResident($rowsBySheet);

        $unknownCnks = [];
        $residentsTouched = 0;
        $scheduleCounts = array_fill_keys(array_keys(self::SHEETS), 0);

        foreach ($residentsByKey as $r) {
            if ($r['name'] === '') {
                continue;
            }

            $departmentName = $r['department'] !== '' ? $r['department'] : 'Onbekend';
            $department = Department::firstOrCreate(
                ['care_center_id' => $careCenter->id, 'slug' => Slug::make($departmentName)],
                ['name' => $departmentName],
            );

            [$first, $last] = Slug::splitResidentName($r['name']);
            $slug = Slug::make($last . ' ' . $first);
            if ($slug === '') {
                $slug = Slug::make($r['name']);
            }

            $resident = Resident::updateOrCreate(
                ['department_id' => $department->id, 'slug' => $slug],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'room' => $r['room'],
                    'doctor_name' => $r['doctor'],
                ],
            );

            $resident->schedules()->delete();

            foreach (self::SHEETS as $type => $_) {
                foreach ($r[$type] as $row) {
                    $cnk = Medication::normalizeCnk((string) ($row['CNK'] ?? ''));
                    if ($cnk === null) {
                        continue;
                    }
                    $med = Medication::firstOrCreate(
                        ['cnk' => $cnk],
                        [
                            'name' => trim((string) ($row['Medicatienaam'] ?? $cnk)),
                            'status' => 'nog-niet-verrijkt',
                            'medicatiegroep' => $row['Medicatiegroep'] !== null
                                ? [trim((string) $row['Medicatiegroep'])]
                                : null,
                        ],
                    );
                    if ($med->wasRecentlyCreated) {
                        $unknownCnks[$cnk] = trim((string) ($row['Medicatienaam'] ?? $cnk));
                    }

                    MedicationSchedule::create([
                        'resident_id' => $resident->id,
                        'medication_id' => $med->id,
                        'schedule_type' => $type,
                        'start_on' => $this->parseDate($row['Begindatum'] ?? null),
                        'end_on' => $this->parseDate($row['Einddatum'] ?? null),
                        'frequency' => $this->str($row['Frequentie'] ?? null),
                        'unit' => $this->str($row['Eenheid'] ?? null),
                        'dosages' => $this->dosagesFor($type, $row),
                        'notes' => $this->str($row['Opmerkingen'] ?? null),
                    ]);
                    $scheduleCounts[$type]++;
                }
            }

            $residentsTouched++;
        }

        return [
            'residents' => $residentsTouched,
            'schedules' => $scheduleCounts,
            'unknown_cnks' => array_map(
                fn ($name, $cnk) => ['cnk' => (string) $cnk, 'name' => $name],
                $unknownCnks,
                array_keys($unknownCnks),
            ),
        ];
    }

    private function readSheet($spreadsheet, string $sheetName): array
    {
        $ws = $spreadsheet->getSheetByName($sheetName);
        if ($ws === null) {
            return [];
        }
        $rows = $ws->toArray(null, true, true, false);
        if (count($rows) < 2) {
            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $rows[0]);
        $records = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $record = [];
            foreach ($header as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $record[$name] = $row[$idx] ?? null;
            }
            $records[] = $record;
        }
        return $records;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }
        return true;
    }

    private function groupByResident(array $rowsBySheet): array
    {
        $residents = [];
        foreach ($rowsBySheet as $type => $rows) {
            foreach ($rows as $row) {
                $name = trim((string) ($row['Resident'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $afd = trim((string) ($row['Afdeling'] ?? ''));
                $kamer = trim((string) ($row['Kamer'] ?? ''));
                $key = $afd . '|' . $kamer . '|' . $name;
                if (! isset($residents[$key])) {
                    $residents[$key] = [
                        'name' => $name,
                        'department' => $afd,
                        'room' => $kamer,
                        'doctor' => trim((string) ($row['Dokter'] ?? '')),
                        MedicationSchedule::TYPE_CHRONIC => [],
                        MedicationSchedule::TYPE_TEMP => [],
                        MedicationSchedule::TYPE_PRN => [],
                        MedicationSchedule::TYPE_FORBIDDEN => [],
                    ];
                }
                if ($residents[$key]['doctor'] === '') {
                    $residents[$key]['doctor'] = trim((string) ($row['Dokter'] ?? ''));
                }
                $residents[$key][$type][] = $row;
            }
        }
        return $residents;
    }

    private function dosagesFor(string $type, array $row): ?array
    {
        if ($type === MedicationSchedule::TYPE_CHRONIC || $type === MedicationSchedule::TYPE_TEMP) {
            $out = [];
            foreach (self::TIME_SLOTS as $slot) {
                $val = $row[$slot] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $out[$slot] = is_numeric($val) ? (float) $val : trim((string) $val);
            }
            return count($out) > 0 ? $out : null;
        }
        if ($type === MedicationSchedule::TYPE_PRN) {
            return array_filter([
                'min' => $this->num($row['Minimumdosis'] ?? null),
                'max' => $this->num($row['Maximumdosis'] ?? null),
                'max_per_day' => $this->num($row['Maximaal aantal dosissen per dag'] ?? null),
                'trigger' => $this->str($row['Geven bij'] ?? null),
                'interval' => $this->str($row['Tijd tussen toedienen'] ?? null),
            ], fn ($v) => $v !== null);
        }
        if ($type === MedicationSchedule::TYPE_FORBIDDEN) {
            $reden = $this->str($row['Reden'] ?? null);
            return $reden !== null ? ['reden' => $reden] : null;
        }
        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $value);
                return Carbon::instance($dt)->toDateString();
            } catch (\Throwable) {
                // fall through
            }
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    private function num(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }
}
