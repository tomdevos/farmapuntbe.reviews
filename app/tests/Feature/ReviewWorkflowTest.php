<?php

namespace Tests\Feature;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\Medication;
use App\Models\MedicationSchedule;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewAttention;
use App\Models\ReviewFinding;
use App\Models\User;
use App\Services\Scraping\PhilScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function resident(): Resident
    {
        $careCenter = CareCenter::create(['name' => 'WZC Test', 'slug' => 'wzc-test']);
        $department = Department::create([
            'care_center_id' => $careCenter->id, 'name' => 'Afdeling', 'slug' => 'afdeling',
        ]);

        return Resident::create([
            'department_id' => $department->id,
            'first_name' => 'Joanna', 'last_name' => 'De Groef', 'slug' => 'joanna-de-groef',
        ]);
    }

    private function draftFor(Resident $resident): Review
    {
        return Review::create([
            'resident_id' => $resident->id,
            'started_on' => now()->toDateString(),
            'status' => Review::STATUS_DRAFT,
        ]);
    }

    public function test_a_draft_review_can_be_deleted(): void
    {
        $resident = $this->resident();
        $review = $this->draftFor($resident);
        $finding = ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_MANUAL,
            'title' => 'Observatie', 'position' => 1,
        ]);

        $this->actingAs(User::factory()->create())
            ->delete(route('reviews.destroy', $review))
            ->assertRedirect(route('residents.show', $resident));

        $this->assertModelMissing($review);
        $this->assertModelMissing($finding);
    }

    public function test_a_finalized_review_cannot_be_deleted(): void
    {
        $review = $this->draftFor($this->resident());
        $review->update(['status' => Review::STATUS_FINALIZED, 'finalized_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->delete(route('reviews.destroy', $review))
            ->assertForbidden();

        $this->assertModelExists($review);
    }

    public function test_an_explanation_can_be_added_to_an_automatic_finding(): void
    {
        $review = $this->draftFor($this->resident());
        $finding = ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_PHIL,
            'title' => 'A + B', 'severity' => 'matig', 'position' => 1,
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('reviews.findings.update', [$review, $finding]), [
                'note_md' => 'Bewust zo gehouden — opgevolgd door de arts.',
            ])
            ->assertRedirect();

        $this->assertSame('Bewust zo gehouden — opgevolgd door de arts.', $finding->fresh()->note_md);
    }

    public function test_an_observation_and_an_attention_can_be_edited(): void
    {
        $review = $this->draftFor($this->resident());
        $finding = ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_MANUAL,
            'title' => 'Oude titel', 'body_md' => 'oud', 'position' => 1,
        ]);
        $attention = ReviewAttention::create([
            'review_id' => $review->id, 'label' => 'Oud label', 'body_md' => 'oud', 'position' => 1,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('reviews.findings.update', [$review, $finding]), [
            'title' => 'Nieuwe titel', 'body_md' => 'nieuw',
        ])->assertRedirect();
        $this->actingAs($user)->patch(route('reviews.attentions.update', [$review, $attention]), [
            'label' => 'Nieuw label', 'body_md' => 'nieuw',
        ])->assertRedirect();

        $this->assertSame('Nieuwe titel', $finding->fresh()->title);
        $this->assertSame('Nieuw label', $attention->fresh()->label);
    }

    public function test_actions_return_to_the_section_they_were_fired_from(): void
    {
        $review = $this->draftFor($this->resident());

        $response = $this->actingAs(User::factory()->create())
            ->from(route('reviews.show', $review))
            ->post(route('reviews.findings.store', $review), ['title' => 'Nieuwe observatie']);

        $finding = ReviewFinding::where('review_id', $review->id)->firstOrFail();
        $response->assertRedirect(route('reviews.show', $review) . "#finding-{$finding->id}");
    }

    public function test_opening_an_unscreened_review_screens_it(): void
    {
        $resident = $this->resident();
        $review = $this->draftFor($resident);
        $this->assertNull($review->gheops_screened_at);

        $response = $this->actingAs(User::factory()->create())->get(route('reviews.show', $review));

        $response->assertOk()
            ->assertSee('id="gheops"', false)
            ->assertSee('id="phil"', false)
            ->assertSee('id="observaties"', false)
            ->assertSee('Review verwijderen');
        $this->assertNotNull($review->fresh()->gheops_screened_at);
    }

    public function test_opening_a_finalized_review_leaves_it_untouched(): void
    {
        $review = $this->draftFor($this->resident());
        $review->update(['status' => Review::STATUS_FINALIZED, 'finalized_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Nog niet gescreend')
            ->assertDontSee('Review verwijderen');

        $this->assertNull($review->fresh()->gheops_screened_at);
    }

    public function test_products_phil_does_not_know_are_left_out_of_the_check(): void
    {
        $resident = $this->resident();
        $real = Medication::create(['cnk' => '1234567', 'name' => 'Echt product']);
        $ownPrep = Medication::create(['cnk' => '9800001', 'name' => 'MB EIGEN BEREIDING']);

        foreach ([$real, $ownPrep] as $medication) {
            MedicationSchedule::create([
                'resident_id' => $resident->id,
                'medication_id' => $medication->id,
                'schedule_type' => MedicationSchedule::TYPE_CHRONIC,
            ]);
        }

        $this->assertTrue(Medication::isPseudoCnk('9800001'));
        $this->assertTrue(Medication::isPseudoCnk('9999001'));
        $this->assertFalse(Medication::isPseudoCnk('1234567'));

        $skipped = PhilScraper::skippedMedications($resident);
        $this->assertSame(['9800001'], $skipped->pluck('cnk')->all());
    }
}
