<?php

namespace Tests\Feature;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Services\Export\DocxReviewExporter;
use App\Services\Export\ResidentGrouping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocxExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_document_prints_each_product_once_and_without_italics(): void
    {
        $careCenter = CareCenter::create(['name' => 'WZC Test', 'slug' => 'wzc-test']);
        $department = Department::create([
            'care_center_id' => $careCenter->id, 'name' => 'Geburt', 'slug' => 'geburt',
        ]);
        $resident = Resident::create([
            'department_id' => $department->id,
            'first_name' => 'Joanna', 'last_name' => 'De Groef', 'slug' => 'joanna-de-groef',
        ]);
        $review = Review::create([
            'resident_id' => $resident->id,
            'started_on' => now()->toDateString(),
            'status' => Review::STATUS_DRAFT,
        ]);
        ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_PHIL,
            'title' => 'Metformine + Indapamide', 'severity' => 'matig', 'position' => 1,
            'note_md' => 'Verminderde hypoglykemiërende werking: controle bloedglucose.',
        ]);

        $export = app(DocxReviewExporter::class)->exportResident($resident, $review);
        $file = storage_path('app/private/' . $export->path);
        $xml = $this->documentXml($file);
        @unlink($file);

        // The product name used to be printed twice: green, then black.
        $this->assertSame(1, substr_count($xml, 'Metformine + Indapamide'));

        // Her explanation reads like every other line: grey, upright.
        $run = $this->runContaining($xml, 'controle bloedglucose');
        $this->assertStringNotContainsString('<w:i/>', $run);
        $this->assertStringContainsString('555555', $run);
    }

    public function test_per_doctor_puts_every_doctor_in_one_block_with_the_department_per_resident(): void
    {
        $careCenter = CareCenter::create(['name' => 'WZC Test', 'slug' => 'wzc-test']);
        $marga = Department::create(['care_center_id' => $careCenter->id, 'name' => 'Marga', 'slug' => 'marga']);
        $zonne = Department::create(['care_center_id' => $careCenter->id, 'name' => 'Zonnebloem', 'slug' => 'zonnebloem']);

        $this->residentWithReview($marga, 'Joanna', 'De Groef', 'Dr. Aerts');
        $this->residentWithReview($zonne, 'Frans', 'Willems', 'Dr. Aerts');
        $this->residentWithReview($marga, 'Maria', 'Peeters', 'Dr. Janssens');

        $export = app(DocxReviewExporter::class)
            ->exportCareCenter($careCenter, null, ResidentGrouping::SORT_DOCTOR);
        $file = storage_path('app/private/' . $export->path);
        $xml = $this->documentXml($file);
        @unlink($file);

        // Eén blok per arts — niet één per afdeling.
        $this->assertSame(1, substr_count($xml, 'Dr. Aerts'));
        $this->assertSame(1, substr_count($xml, 'Dr. Janssens'));
        $this->assertStringNotContainsString('Afdeling Marga', $xml);

        // Dr. Aerts krijgt zijn twee bewoners, elk met hun afdeling in de kopregel.
        $this->assertStringContainsString('Afdeling: Marga', $xml);
        $this->assertStringContainsString('Afdeling: Zonnebloem', $xml);
        $this->assertStringContainsString('Gegroepeerd per behandelend arts', $xml);
    }

    private function residentWithReview(Department $department, string $first, string $last, string $doctor): Resident
    {
        $resident = Resident::create([
            'department_id' => $department->id,
            'first_name' => $first,
            'last_name' => $last,
            'slug' => \Illuminate\Support\Str::slug("{$first} {$last}"),
            'doctor_name' => $doctor,
        ]);
        Review::create([
            'resident_id' => $resident->id,
            'started_on' => now()->toDateString(),
            'status' => Review::STATUS_DRAFT,
        ]);

        return $resident;
    }

    private function documentXml(string $file): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true, "Kon {$file} niet openen.");
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return (string) $xml;
    }

    private function runContaining(string $xml, string $needle): string
    {
        preg_match('#<w:r>(?:(?!</w:r>).)*' . preg_quote($needle, '#') . '.*?</w:r>#s', $xml, $m);
        $this->assertNotEmpty($m, "Geen run gevonden met '{$needle}'.");

        return $m[0];
    }
}
