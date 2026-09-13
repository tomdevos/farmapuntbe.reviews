<?php

namespace Tests\Feature;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Resident;
use App\Models\Review;
use App\Services\Export\PdfReviewExporter;
use App\Services\Export\ResidentGrouping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rendert de blades van het PDF-document; Chrome zelf blijft erbuiten. */
class PdfExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_alphabetical_keeps_the_department_headings(): void
    {
        $html = $this->render(ResidentGrouping::SORT_ALPHABETICAL);

        $this->assertStringContainsString('Afdeling Marga', $html);
        $this->assertStringContainsString('Afdeling Zonnebloem', $html);
        $this->assertStringContainsString('Behandelend arts: Dr. Aerts', $html);
        $this->assertStringContainsString('Alfabetisch per afdeling', $html);
    }

    public function test_per_doctor_swaps_the_headings_for_the_doctors(): void
    {
        $html = $this->render(ResidentGrouping::SORT_DOCTOR);

        $this->assertStringNotContainsString('Afdeling Marga', $html);
        $this->assertStringContainsString('<h1 class="dept-title">Dr. Aerts</h1>', $html);
        $this->assertStringContainsString('<h1 class="dept-title">Dr. Janssens</h1>', $html);
        $this->assertStringContainsString('Afdeling: Zonnebloem', $html);
        $this->assertStringContainsString('Gegroepeerd per behandelend arts', $html);

        // De inhoudstafel volgt dezelfde indeling.
        $this->assertStringContainsString('<li>Dr. Aerts</li>', $html);
    }

    private function render(string $sort): string
    {
        $careCenter = CareCenter::create(['name' => 'WZC Test', 'slug' => 'wzc-test']);
        $marga = Department::create(['care_center_id' => $careCenter->id, 'name' => 'Marga', 'slug' => 'marga']);
        $zonne = Department::create(['care_center_id' => $careCenter->id, 'name' => 'Zonnebloem', 'slug' => 'zonnebloem']);

        $this->residentWithReview($marga, 'Joanna', 'De Groef', 'Dr. Aerts');
        $this->residentWithReview($zonne, 'Frans', 'Willems', 'Dr. Aerts');
        $this->residentWithReview($marga, 'Maria', 'Peeters', 'Dr. Janssens');

        $exporter = new class (app(ResidentGrouping::class)) extends PdfReviewExporter {
            public string $html = '';

            protected function renderPdf(string $html, string $filename): string
            {
                $this->html = $html;

                return 'exports/' . $filename;
            }
        };
        $exporter->exportCareCenter($careCenter, null, $sort);

        return $exporter->html;
    }

    private function residentWithReview(Department $department, string $first, string $last, string $doctor): void
    {
        $resident = Resident::create([
            'department_id' => $department->id,
            'first_name' => $first,
            'last_name' => $last,
            'slug' => Str::slug("{$first} {$last}"),
            'doctor_name' => $doctor,
        ]);
        Review::create([
            'resident_id' => $resident->id,
            'started_on' => now()->toDateString(),
            'status' => Review::STATUS_DRAFT,
        ]);
    }
}
