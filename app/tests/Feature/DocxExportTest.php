<?php

namespace Tests\Feature;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Services\Export\DocxReviewExporter;
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
