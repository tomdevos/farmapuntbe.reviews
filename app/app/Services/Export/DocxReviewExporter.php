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

    public function exportResident(Resident $resident, ?Review $review = null, ?int $userId = null): ReviewExport
    {
        $resident->loadMissing('department.careCenter');
        $review = $review ?? $resident->reviews()->latest('started_on')->first();
        if (! $review) {
            throw new \RuntimeException('Geen review gevonden voor deze bewoner.');
        }
        $word = $this->makeDocument($resident->department->careCenter, $review);
        $this->addDepartmentSection(
            $word,
            $resident->department,
            collect([$resident]),
            collect([$resident->id => $review->load('findings', 'attentions')]),
        );

        $path = $this->save($word, "resident-{$resident->slug}-{$review->started_on->format('Ymd')}.docx");
        return $this->record(ReviewExport::SCOPE_RESIDENT, $resident->id, $path, $userId);
    }

    public function exportDepartment(Department $department, ?int $userId = null): ReviewExport
    {
        $department->loadMissing(['careCenter', 'residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);
        $reviews = $this->latestReviews($department->residents);
        $word = $this->makeDocument($department->careCenter);
        $this->addDepartmentSection($word, $department, $department->residents, $reviews);

        $path = $this->save($word, "department-{$department->slug}-" . now()->format('Ymd') . '.docx');
        return $this->record(ReviewExport::SCOPE_DEPARTMENT, $department->id, $path, $userId);
    }

    public function exportCareCenter(CareCenter $careCenter, ?int $userId = null): ReviewExport
    {
        $careCenter->loadMissing(['departments.residents' => fn ($q) => $q->whereNull('archived_at')->orderBy('last_name')]);
        $allResidents = $careCenter->departments->flatMap->residents;
        $reviews = $this->latestReviews($allResidents);

        $word = $this->makeDocument($careCenter);
        $this->addVoorwoord($word, $careCenter);

        $attentions = $reviews->flatMap->attentions->unique('label');
        if ($attentions->isNotEmpty()) {
            $this->addAttentions($word, $attentions);
        }

        foreach ($careCenter->departments as $dept) {
            $this->addDepartmentSection($word, $dept, $dept->residents, $reviews);
        }

        $path = $this->save($word, "wzc-{$careCenter->slug}-" . now()->format('Ymd') . '.docx');
        return $this->record(ReviewExport::SCOPE_CARE_CENTER, $careCenter->id, $path, $userId);
    }

    private function makeDocument(CareCenter $cc, ?Review $review = null): PhpWord
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

    private function addDepartmentSection(PhpWord $word, Department $dept, Collection $residents, Collection $reviews): void
    {
        $section = $word->addSection();
        $section->addText("Afdeling {$dept->name}", ['bold' => true, 'size' => 16, 'color' => '2f3a2e']);
        $section->addText("{$residents->count()} bewoners", ['italic' => true, 'size' => 9, 'color' => '888888']);
        $section->addTextBreak(1);

        foreach ($residents->values() as $idx => $resident) {
            $rev = $reviews->get($resident->id);
            $header = $section->addTextRun();
            $header->addText(($idx + 1) . '. ', ['bold' => true]);
            $header->addText($resident->display_name, ['bold' => true]);
            $header->addText('   ', []);
            $header->addText('Behandelend arts: ' . ($resident->doctor_name ?: '—'), ['italic' => true, 'color' => self::FARMAPUNT_GREEN, 'size' => 9]);

            if ($rev && $rev->findings->whereNull('dismissed_at')->isNotEmpty()) {
                foreach ($rev->findings->whereNull('dismissed_at') as $f) {
                    $p = $section->addTextRun();
                    $p->addText(\Illuminate\Support\Str::beforeLast($f->title, ':') . ': ', ['bold' => true, 'color' => self::FARMAPUNT_GREEN]);
                    $rest = trim(\Illuminate\Support\Str::after($f->title, ':'));
                    if ($rest !== '') $p->addText($rest);
                    if ($f->body_md) {
                        $section->addText($f->body_md, ['size' => 9, 'color' => '555555']);
                    }
                    if ($f->note_md) {
                        $section->addText($f->note_md, ['size' => 9, 'italic' => true, 'color' => self::FARMAPUNT_GREEN]);
                    }
                }
            } else {
                $section->addText('Alles ok.', ['italic' => true, 'color' => '999999']);
            }
            $section->addTextBreak(1);
        }
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
