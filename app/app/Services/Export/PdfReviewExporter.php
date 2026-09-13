<?php

namespace App\Services\Export;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewExport;
use Illuminate\Support\Collection;
use Spatie\Browsershot\Browsershot;

class PdfReviewExporter
{
    public function __construct(private readonly ResidentGrouping $grouping)
    {
    }

    public function exportResident(Resident $resident, ?Review $review = null, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $resident->loadMissing('department.careCenter');
        $review = $review ?? $resident->reviews()->latest('started_on')->first();
        if ($review === null) {
            throw new \RuntimeException('Geen review gevonden voor deze bewoner.');
        }
        $html = view('exports.pdf.document', [
            'scope' => 'resident',
            'careCenter' => $resident->department->careCenter,
            'groups' => $this->grouping->groups(collect([$resident]), $sort),
            'reviewsByResident' => collect([$resident->id => $review->load('findings.activeIngredient', 'findings.gheopsCriterion', 'attentions')]),
            'attentions' => $review->attentions,
            'metadata' => $this->metadataFor($resident->department->careCenter, $sort, $review),
            'includeVoorwoord' => false,
            'includeAttentions' => false,
        ])->render();

        $path = $this->renderPdf($html, "resident-{$resident->slug}-{$review->started_on->format('Ymd')}" . $this->sortSuffix($sort) . ".pdf");

        return ReviewExport::create([
            'scope' => ReviewExport::SCOPE_RESIDENT,
            'scope_id' => $resident->id,
            'format' => ReviewExport::FORMAT_PDF,
            'path' => $path,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);
    }

    public function exportDepartment(Department $department, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $department->loadMissing(['careCenter', 'residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);
        $department->residents->each->setRelation('department', $department);
        $reviewsByResident = $this->latestReviewsForResidents($department->residents);

        $allAttentions = $reviewsByResident->flatMap->attentions;

        $html = view('exports.pdf.document', [
            'scope' => 'department',
            'careCenter' => $department->careCenter,
            'groups' => $this->grouping->groups($department->residents, $sort),
            'reviewsByResident' => $reviewsByResident,
            'attentions' => $allAttentions,
            'metadata' => $this->metadataFor($department->careCenter, $sort),
            'includeVoorwoord' => false,
            'includeAttentions' => $allAttentions->isNotEmpty(),
        ])->render();

        $path = $this->renderPdf($html, "department-{$department->slug}-" . now()->format('Ymd') . $this->sortSuffix($sort) . '.pdf');

        return ReviewExport::create([
            'scope' => ReviewExport::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'format' => ReviewExport::FORMAT_PDF,
            'path' => $path,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);
    }

    public function exportCareCenter(CareCenter $careCenter, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $careCenter->loadMissing(['departments.residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);

        $allResidents = $careCenter->departments->flatMap(
            fn ($d) => $d->residents->each->setRelation('department', $d)
        );
        $reviewsByResident = $this->latestReviewsForResidents($allResidents);
        $allAttentions = $reviewsByResident->flatMap->attentions;

        $html = view('exports.pdf.document', [
            'scope' => 'care_center',
            'careCenter' => $careCenter,
            'groups' => $this->grouping->groups($allResidents, $sort),
            'reviewsByResident' => $reviewsByResident,
            'attentions' => $allAttentions,
            'metadata' => $this->metadataFor($careCenter, $sort),
            'includeVoorwoord' => true,
            'includeAttentions' => true,
        ])->render();

        $path = $this->renderPdf($html, "wzc-{$careCenter->slug}-" . now()->format('Ymd') . $this->sortSuffix($sort) . '.pdf');

        return ReviewExport::create([
            'scope' => ReviewExport::SCOPE_CARE_CENTER,
            'scope_id' => $careCenter->id,
            'format' => ReviewExport::FORMAT_PDF,
            'path' => $path,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);
    }

    /** Houdt de twee indelingen van dezelfde dag uit elkaars bestandsnaam. */
    private function sortSuffix(string $sort): string
    {
        return $sort === ResidentGrouping::SORT_DOCTOR ? '-per-arts' : '';
    }

    private function latestReviewsForResidents(Collection $residents): Collection
    {
        return $residents
            ->map(fn ($r) => $r->reviews()->latest('started_on')->with(['findings.activeIngredient', 'findings.gheopsCriterion', 'attentions'])->first())
            ->filter()
            ->keyBy('resident_id');
    }

    private function metadataFor(CareCenter $cc, string $sort, ?Review $review = null): array
    {
        return [
            'title' => 'Bespreking medicatieschema\'s',
            'subtitle' => "Woonzorgcentrum {$cc->name}",
            'bestemmeling' => 'Behandelend arts',
            'wzc' => $cc->name . ($cc->address ? " — {$cc->address}" : ''),
            'apotheek' => 'Apotheek Farmapunt',
            'opgesteld_door' => $review?->user?->name ?? 'Apotheker Farmapunt',
            'datum' => ($review?->started_on ?? now())->translatedFormat('j F Y'),
            'onderwerp' => 'Periodieke medicatiereview',
            'indeling' => $sort === ResidentGrouping::SORT_DOCTOR
                ? 'Gegroepeerd per behandelend arts'
                : 'Alfabetisch per afdeling',
        ];
    }

    protected function renderPdf(string $html, string $filename): string
    {
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $absPath = $dir . '/' . $filename;

        $shot = Browsershot::html($html)
            ->noSandbox()
            ->setNodeBinary(trim((string) shell_exec('command -v node')))
            ->setNpmBinary(trim((string) shell_exec('command -v npm')))
            ->setIncludePath('/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin')
            ->setNodeModulePath(base_path('node_modules'))
            ->setOption('args', ['--no-sandbox', '--disable-dev-shm-usage'])
            ->format('A4')
            ->margins(15, 15, 15, 15)
            ->showBackground();

        if ($chrome = config('chrome.binary')) {
            $shot->setChromePath($chrome);
        }

        $shot->savePdf($absPath);

        return 'exports/' . $filename;
    }
}
