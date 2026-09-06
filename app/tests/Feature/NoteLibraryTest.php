<?php

namespace Tests\Feature;

use App\Models\CareCenter;
use App\Models\Department;
use App\Models\FindingNoteTemplate;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\User;
use App\Services\NoteLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function reviewFor(string $slug): Review
    {
        $careCenter = CareCenter::firstOrCreate(['slug' => 'wzc-test'], ['name' => 'WZC Test']);
        $department = Department::firstOrCreate(
            ['care_center_id' => $careCenter->id, 'slug' => 'afdeling'],
            ['name' => 'Afdeling'],
        );
        $resident = Resident::create([
            'department_id' => $department->id,
            'first_name' => 'Bewoner', 'last_name' => $slug, 'slug' => $slug,
        ]);

        return Review::create([
            'resident_id' => $resident->id,
            'started_on' => now()->toDateString(),
            'status' => Review::STATUS_DRAFT,
        ]);
    }

    private function philFinding(Review $review, string $title): ReviewFinding
    {
        return ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_PHIL,
            'title' => $title, 'severity' => 'matig', 'position' => 1,
        ]);
    }

    public function test_saving_an_explanation_stores_it_in_the_library(): void
    {
        $review = $this->reviewFor('een');
        $finding = $this->philFinding($review, 'Lorazepam + Tramadol');

        $this->actingAs(User::factory()->create())
            ->patch(route('reviews.findings.update', [$review, $finding]), [
                'note_md' => 'Verhoogd risico op sedatie.',
            ])->assertRedirect();

        $this->assertDatabaseHas('finding_note_templates', [
            'source' => 'phil',
            'label' => 'Lorazepam + Tramadol',
            'note_md' => 'Verhoogd risico op sedatie.',
        ]);
    }

    public function test_the_same_pair_in_reverse_order_reuses_the_explanation(): void
    {
        $library = app(NoteLibrary::class);
        $first = $this->philFinding($this->reviewFor('een'), 'Lorazepam + Tramadol');
        $first->note_md = 'Verhoogd risico op sedatie.';
        $library->remember($first);

        // Phil lists the pair the other way round for the next resident.
        $second = $this->philFinding($this->reviewFor('twee'), 'Tramadol + Lorazepam');
        $this->assertTrue($library->suggest($second));

        $this->assertSame('Verhoogd risico op sedatie.', $second->note_md);
        $this->assertNotNull($second->note_suggested_at);
    }

    public function test_an_explanation_she_wrote_herself_is_never_overwritten(): void
    {
        $library = app(NoteLibrary::class);
        $first = $this->philFinding($this->reviewFor('een'), 'Lorazepam + Tramadol');
        $first->note_md = 'Verhoogd risico op sedatie.';
        $library->remember($first);

        $second = $this->philFinding($this->reviewFor('twee'), 'Lorazepam + Tramadol');
        $second->note_md = 'Bewust zo gehouden, dosis afgebouwd.';

        $this->assertFalse($library->suggest($second));
        $this->assertSame('Bewust zo gehouden, dosis afgebouwd.', $second->note_md);
    }

    public function test_saving_a_suggestion_confirms_it_and_updates_the_library(): void
    {
        $review = $this->reviewFor('een');
        $finding = $this->philFinding($review, 'Lorazepam + Tramadol');
        $finding->update(['note_md' => 'Voorstel.', 'note_suggested_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->patch(route('reviews.findings.update', [$review, $finding]), [
                'note_md' => 'Voorstel, aangepast.',
            ])->assertRedirect();

        $this->assertNull($finding->fresh()->note_suggested_at);
        $this->assertDatabaseHas('finding_note_templates', ['note_md' => 'Voorstel, aangepast.']);
    }

    public function test_a_new_observation_without_text_takes_over_the_earlier_one(): void
    {
        $user = User::factory()->create();
        $first = $this->reviewFor('een');
        $this->actingAs($user)->post(route('reviews.findings.store', $first), [
            'title' => 'Haldol',
            'body_md' => 'Niet innemen met koffie/thee.',
        ])->assertRedirect();

        $second = $this->reviewFor('twee');
        $this->actingAs($user)->post(route('reviews.findings.store', $second), [
            'title' => 'Haldol: opvolgen',
        ])->assertRedirect();

        $finding = $second->findings()->firstOrFail();
        $this->assertSame('Niet innemen met koffie/thee.', $finding->body_md);
        $this->assertNotNull($finding->note_suggested_at);
    }

    public function test_the_rebuild_command_seeds_the_library_from_existing_reviews(): void
    {
        $review = $this->reviewFor('een');
        $this->philFinding($review, 'Lorazepam + Tramadol')->update(['note_md' => 'Sedatie.']);
        // A suggestion she has not checked yet must not confirm itself.
        ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_PHIL,
            'title' => 'Burinex + Duovent', 'note_md' => 'Voorstel.',
            'note_suggested_at' => now(), 'position' => 2,
        ]);

        $this->artisan('review:notes-rebuild')->assertSuccessful();
        $this->artisan('review:notes-rebuild')->assertSuccessful();

        $this->assertSame(1, FindingNoteTemplate::count());
        $this->assertSame(1, FindingNoteTemplate::firstOrFail()->times_used);
    }

    public function test_a_long_observation_title_still_fits_the_key_column(): void
    {
        $library = app(NoteLibrary::class);
        $title = 'Sommige geneesmiddelen kunnen inwerken op de suikerverlagende werking van Jardiance.';
        $review = $this->reviewFor('een');
        $finding = ReviewFinding::create([
            'review_id' => $review->id, 'source' => ReviewFinding::SOURCE_MANUAL,
            'title' => $title, 'body_md' => 'Bloedglucose en HbA1c regelmatig controleren.', 'position' => 1,
        ]);

        $this->assertLessThanOrEqual(64, strlen((string) $library->keyFor($finding)));
        $this->assertNotNull($library->remember($finding));

        // En dezelfde observatie bij een andere bewoner vindt hem terug.
        $second = ReviewFinding::create([
            'review_id' => $this->reviewFor('twee')->id, 'source' => ReviewFinding::SOURCE_MANUAL,
            'title' => $title, 'position' => 1,
        ]);
        $this->assertTrue($library->suggest($second));
    }

    public function test_the_library_page_lists_and_removes_a_sentence(): void
    {
        $template = FindingNoteTemplate::create([
            'key' => 'phil:abc', 'source' => 'phil', 'label' => 'Lorazepam + Tramadol',
            'note_md' => 'Sedatie.', 'times_used' => 1, 'last_used_at' => now(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('note-templates.index'))
            ->assertOk()
            ->assertSee('Lorazepam + Tramadol');

        $this->actingAs($user)->delete(route('note-templates.destroy', $template))->assertRedirect();
        $this->assertModelMissing($template);
    }
}
