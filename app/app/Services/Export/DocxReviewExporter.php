<?php

namespace App\Services\Export;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewExport;
use Illuminate\Support\Collection;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Font;

class DocxReviewExporter
{
    private const FARMAPUNT_GREEN = '3CA84B';

    public function __construct(
        private readonly FindingPresenter $presenter,
        private readonly ResidentGrouping $grouping,
    ) {
    }

    public function exportResident(Resident $resident, ?Review $review = null, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $resident->loadMissing('department.careCenter');
        $review = $review ?? $resident->reviews()->latest('started_on')->first();
        if (! $review) {
            throw new \RuntimeException('Geen review gevonden voor deze bewoner.');
        }
        $word = $this->makeDocument($resident->department->careCenter, $sort, $review);
        $this->addGroups(
            $word,
            $this->grouping->groups(collect([$resident]), $sort),
            collect([$resident->id => $review->load('findings', 'attentions')]),
        );

        $path = $this->save($word, "resident-{$resident->slug}-{$review->started_on->format('Ymd')}" . $this->sortSuffix($sort) . ".docx");
        return $this->record(ReviewExport::SCOPE_RESIDENT, $resident->id, $path, $userId);
    }

    public function exportDepartment(Department $department, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $department->loadMissing(['careCenter', 'residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);
        $department->residents->each->setRelation('department', $department);
        $reviews = $this->latestReviews($department->residents);
        $word = $this->makeDocument($department->careCenter, $sort);
        $this->addGroups($word, $this->grouping->groups($department->residents, $sort), $reviews);

        $path = $this->save($word, "department-{$department->slug}-" . now()->format('Ymd') . $this->sortSuffix($sort) . '.docx');
        return $this->record(ReviewExport::SCOPE_DEPARTMENT, $department->id, $path, $userId);
    }

    public function exportCareCenter(CareCenter $careCenter, ?int $userId = null, string $sort = ResidentGrouping::SORT_ALPHABETICAL): ReviewExport
    {
        $careCenter->loadMissing(['departments.residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);
        $allResidents = $careCenter->departments->flatMap(
            fn ($d) => $d->residents->each->setRelation('department', $d)
        );
        $reviews = $this->latestReviews($allResidents);

        $word = $this->makeDocument($careCenter, $sort);
        $this->addVoorwoord($word, $careCenter);

        $attentions = $reviews->flatMap->attentions->unique('label');
        if ($attentions->isNotEmpty()) {
            $this->addAttentions($word, $attentions);
        }

        $this->addGroups($word, $this->grouping->groups($allResidents, $sort), $reviews);

        $path = $this->save($word, "wzc-{$careCenter->slug}-" . now()->format('Ymd') . $this->sortSuffix($sort) . '.docx');
        return $this->record(ReviewExport::SCOPE_CARE_CENTER, $careCenter->id, $path, $userId);
    }

    private function makeDocument(CareCenter $cc, string $sort, ?Review $review = null): PhpWord
    {
        $word = new PhpWord();
        $word->setDefaultFontName('Lato');
        $word->setDefaultFontSize(11);

        $section = $word->addSection();
        $section->addText('FARMAPUNT.+', ['bold' => true, 'size' => 26, 'color' => self::FARMAPUNT_GREEN], ['alignment' => Jc::CENTER]);
        $section->addText('MEDICATIEREVIEW', ['size' => 9, 'color' => self::FARMAPUNT_GREEN, 'allCaps' => true], ['alignment' => Jc::CENTER, 'spaceBefore' => 600]);
        $section->addText('Bespreking medicatieschema\'s', ['size' => 22, 'bold' => true], ['alignment' => Jc::CENTER]);
        $section->addText("Woonzorgcentrum {$cc->name}", ['size' => 12, 'color' => '555555'], ['alignment' => Jc::CENTER, 'spaceAfter' => 600]);

        $section->addTextBreak(1);
        $rows = [
            ['Bestemmeling', 'Behandelend arts'],
            ['Woonzorgcentrum', $cc->name . ($cc->address ? " — {$cc->address}" : '')],
            ['Apotheek', 'Apotheek Farmapunt'],
            ['Opgesteld door', $review?->user?->name ?? 'Apotheker Farmapunt'],
            ['Datum', ($review?->started_on ?? now())->translatedFormat('j F Y')],
            ['Onderwerp', 'Periodieke medicatiereview'],
            ['Indeling', $sort === ResidentGrouping::SORT_DOCTOR
                ? 'Gegroepeerd per behandelend arts'
                : 'Alfabetisch per afdeling'],
        ];
        $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(3500)->addText($label, ['color' => self::FARMAPUNT_GREEN, 'bold' => true]);
            $table->addCell(7500)->addText($value);
        }

        $section->addTextBreak(2);
        $section->addText('VERTROUWELIJK · Document met persoonsgebonden medische gegevens — uitsluitend bestemd voor de behandelend arts.', ['size' => 8, 'italic' => true, 'color' => '888888']);

        return $word;
    }

    private function addVoorwoord(PhpWord $word, CareCenter $cc): void
    {
        $section = $word->addSection();
        $section->addText('Voorwoord', ['bold' => true, 'size' => 18, 'color' => '2f3a2e']);
        $section->addText(str_repeat('—', 30), ['color' => self::FARMAPUNT_GREEN]);
        $section->addText('Geachte dokter,');
        $section->addText("In bijlage vindt u de periodieke medicatiereview van de bewoners van {$cc->name}, opgesteld door apotheek Farmapunt. De review werd uitgevoerd op basis van de actuele medicatieschema's en heeft tot doel de farmacotherapie van elke bewoner te optimaliseren.");
        $section->addText('De opmerkingen vormen een uitgangspunt voor overleg. Definitieve beslissingen over starten, stoppen of aanpassen van medicatie blijven steeds in handen van de voorschrijvend arts.');
        $section->addTextBreak(1);
        $section->addText('Met collegiale groeten,');
        $section->addText('Het team van Apotheek Farmapunt', ['bold' => true, 'color' => self::FARMAPUNT_GREEN]);
    }

    private function addAttentions(PhpWord $word, Collection $attentions): void
    {
        $section = $word->addSection();
        $section->addText('Algemene aandachtspunten', ['bold' => true, 'size' => 18, 'color' => '2f3a2e']);
        $section->addText('Onderstaande aandachtspunten zijn van toepassing op meerdere bewoners.', ['italic' => true, 'color' => '555555']);
        $section->addTextBreak(1);
        foreach ($attentions as $a) {
            $run = $section->addListItem('', 0);
            $run->addText($a->label . ': ', ['bold' => true, 'color' => self::FARMAPUNT_GREEN]);
            if ($a->body_md) {
                $run->addText($a->body_md);
            }
        }
    }

    private function addGroups(PhpWord $word, Collection $groups, Collection $reviews): void
    {
        foreach ($groups as $group) {
            $this->addGroupSection($word, $group, $reviews);
        }
    }

    /** @param array{label: string, residents: Collection, show_department: bool} $group */
    private function addGroupSection(PhpWord $word, array $group, Collection $reviews): void
    {
        $residents = $group['residents'];

        $section = $word->addSection();
        $section->addText($group['label'], ['bold' => true, 'size' => 16, 'color' => '2f3a2e']);
        $section->addText("{$residents->count()} bewoners", ['italic' => true, 'size' => 9, 'color' => '888888']);
        $section->addTextBreak(1);

        foreach ($residents->values() as $idx => $resident) {
            $rev = $reviews->get($resident->id);
            $header = $section->addTextRun();
            $header->addText(($idx + 1) . '. ', ['bold' => true]);
            $header->addText($resident->display_name, ['bold' => true]);
            $header->addText('   ', []);
            $header->addText(
                $group['show_department']
                    ? 'Afdeling: ' . ($resident->department?->name ?: '—')
                    : 'Behandelend arts: ' . ($resident->doctor_name ?: '—'),
                ['italic' => true, 'color' => self::FARMAPUNT_GREEN, 'size' => 9],
            );

            $lines = $rev ? $this->presenter->present($rev->findings) : [];
            if ($lines !== []) {
                foreach ($lines as $line) {
                    $section->addText($line['label'] . ':', ['bold' => true, 'color' => self::FARMAPUNT_GREEN]);
                    foreach ($line['lines'] as $text) {
                        $section->addText($text, ['size' => 9, 'color' => '555555']);
                    }
                }
            } else {
                $section->addText('Alles ok.', ['italic' => true, 'color' => '999999']);
            }
            $section->addTextBreak(1);
        }
    }

    /** Houdt de twee indelingen van dezelfde dag uit elkaars bestandsnaam. */
    private function sortSuffix(string $sort): string
    {
        return $sort === ResidentGrouping::SORT_DOCTOR ? '-per-arts' : '';
    }

    private function latestReviews(Collection $residents): Collection
    {
        return $residents
            ->map(fn ($r) => $r->reviews()->latest('started_on')->with('findings', 'attentions')->first())
            ->filter()
            ->keyBy('resident_id');
    }

    private function save(PhpWord $word, string $filename): string
    {
        $dir = storage_path('app/private/exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $absPath = $dir . '/' . $filename;
        IOFactory::createWriter($word, 'Word2007')->save($absPath);
        return 'exports/' . $filename;
    }

    private function record(string $scope, int $scopeId, string $path, ?int $userId): ReviewExport
    {
        return ReviewExport::create([
            'scope' => $scope,
            'scope_id' => $scopeId,
            'format' => ReviewExport::FORMAT_DOCX,
            'path' => $path,
            'generated_by' => $userId,
            'generated_at' => now(),
        ]);
    }
}
