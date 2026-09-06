<?php

namespace Tests\Unit;

use App\Models\ReviewFinding;
use App\Services\Export\FindingPresenter;
use Tests\TestCase;

class FindingPresenterTest extends TestCase
{
    private function finding(array $attributes): ReviewFinding
    {
        return ReviewFinding::make($attributes + [
            'source' => ReviewFinding::SOURCE_PHIL,
            'position' => 0,
        ]);
    }

    /** @param list<ReviewFinding> $findings */
    private function present(array $findings): array
    {
        return (new FindingPresenter())->present(collect($findings));
    }

    public function test_a_title_without_a_colon_is_printed_once(): void
    {
        $lines = $this->present([
            $this->finding(['title' => 'Metformine + Indapamide', 'note_md' => 'Controle bloedglucose.']),
        ]);

        // Str::beforeLast() + Str::after() both fall back to the whole title,
        // which used to print the product name twice (green, then black).
        $this->assertSame([
            ['label' => 'Metformine + Indapamide', 'lines' => ['Controle bloedglucose.']],
        ], $lines);
    }

    public function test_a_title_splits_on_the_first_colon_only(): void
    {
        $lines = $this->present([
            $this->finding([
                'source' => ReviewFinding::SOURCE_GHEOPS,
                'title' => 'Lijst 4, Criterium 12: NSAID: vermijden',
            ]),
        ]);

        $this->assertSame([
            ['label' => 'Lijst 4, Criterium 12', 'lines' => ['NSAID: vermijden']],
        ], $lines);
    }

    public function test_markdown_emphasis_is_stripped_from_the_generated_body(): void
    {
        $lines = $this->present([
            $this->finding([
                'source' => ReviewFinding::SOURCE_GHEOPS,
                'title' => 'Lijst 1, Criterium 3: Benzodiazepine',
                'body_md' => '_Rationale:_ valrisico',
            ]),
        ]);

        $this->assertSame(['Benzodiazepine', 'Rationale: valrisico'], $lines[0]['lines']);
    }

    public function test_dismissed_findings_are_left_out(): void
    {
        $lines = $this->present([
            $this->finding(['title' => 'A + B', 'note_md' => 'x', 'dismissed_at' => now()]),
            $this->finding(['title' => 'C + D', 'note_md' => 'y']),
        ]);

        $this->assertCount(1, $lines);
        $this->assertSame('C + D', $lines[0]['label']);
    }

    public function test_pairs_sharing_one_product_and_one_explanation_are_bundled(): void
    {
        $note = 'Verhoogd risico op sedatie, ademhalingsdepressie + verhoogd valrisico.';
        $lines = $this->present([
            $this->finding(['title' => 'Durogesic + Lorazepam', 'note_md' => $note, 'position' => 1]),
            $this->finding(['title' => 'Durogesic + Tradonal Odis', 'note_md' => $note, 'position' => 2]),
            $this->finding(['title' => 'Durogesic + Tramadol Retard', 'note_md' => $note, 'position' => 3]),
        ]);

        $this->assertSame([
            ['label' => 'Durogesic + Lorazepam, Tradonal Odis en Tramadol Retard', 'lines' => [$note]],
        ], $lines);
    }

    public function test_a_full_cross_product_bundles_on_both_sides(): void
    {
        $note = 'Controle Kalium wegens risico hypokaliëmie.';
        $lines = $this->present([
            $this->finding(['title' => 'Burinex + Duovent', 'note_md' => $note, 'position' => 1]),
            $this->finding(['title' => 'Burinex + Trixeo', 'note_md' => $note, 'position' => 2]),
            $this->finding(['title' => 'Indapamide + Duovent', 'note_md' => $note, 'position' => 3]),
            $this->finding(['title' => 'Indapamide + Trixeo', 'note_md' => $note, 'position' => 4]),
        ]);

        $this->assertSame([
            ['label' => 'Burinex en Indapamide + Duovent en Trixeo', 'lines' => [$note]],
        ], $lines);
    }

    public function test_an_incomplete_set_is_not_bundled(): void
    {
        $note = 'Controle Kalium.';
        $lines = $this->present([
            $this->finding(['title' => 'Burinex + Duovent', 'note_md' => $note, 'position' => 1]),
            $this->finding(['title' => 'Burinex + Trixeo', 'note_md' => $note, 'position' => 2]),
            $this->finding(['title' => 'Indapamide + Duovent', 'note_md' => $note, 'position' => 3]),
        ]);

        // Indapamide + Trixeo was never reported, so inventing it would be wrong.
        $this->assertCount(3, $lines);
    }

    public function test_different_explanations_stay_apart(): void
    {
        $lines = $this->present([
            $this->finding(['title' => 'Durogesic + Lorazepam', 'note_md' => 'Sedatie.', 'position' => 1]),
            $this->finding(['title' => 'Durogesic + Trazodone', 'note_md' => 'Serotoninesyndroom.', 'position' => 2]),
        ]);

        $this->assertCount(2, $lines);
    }
}
