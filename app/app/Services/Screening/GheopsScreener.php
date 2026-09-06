<?php

namespace App\Services\Screening;

use App\Models\ActiveIngredient;
use App\Models\DrugClassIngredient;
use App\Models\GheopsCriterion;
use App\Models\MedicationSchedule;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Services\NoteLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Direct port of sources/screen_resident.py.
 *
 * Three match modes (any one is sufficient):
 *   a) Direct: resident INN slug matches a criterion's `stoffen`.
 *   b) Text:   the resident's INN base word appears in the criterion NAME
 *              (not rationale/alternatief — avoids false positives).
 *   c) Group:  criterion has a drug class in `groepen`, resident has an INN
 *              in that class via drug_class_ingredients pivot.
 *
 * Lijst 4 (interactions) additionally requires matches on BOTH sides of a "+"
 * in the title. If there's no "+", we require ≥2 matched ingredients before
 * treating it as a hit (avoids noise from truncated titles).
 */
class GheopsScreener
{
    public function __construct(private readonly NoteLibrary $library)
    {
    }

    /** @var array<string, array<string>>|null */
    private ?array $classMembersByClass = null;

    /** @var array<int, GheopsCriterion>|null */
    private ?array $criteriaCache = null;

    /**
     * Run the screening against the resident's medications (chronic + temp + prn,
     * forbidden excluded). Stores findings on the given review and returns them.
     */
    public function screen(Resident $resident, Review $review): Collection
    {
        $residentInns = $this->residentIngredients($resident);

        // Reruns upsert per criterium instead of wiping the lot, so the
        // pharmacist's uitleg and "negeer" survive a refresh.
        $existing = $review->findings()
            ->where('source', ReviewFinding::SOURCE_GHEOPS)
            ->get()
            ->keyBy('gheops_criterion_id');

        $matches = collect();
        $position = 0;
        $seen = [];

        foreach ($this->criteria() as $criterion) {
            $matched = $this->matchCriterion($criterion, $residentInns);
            if (count($matched) === 0) {
                continue;
            }
            if ($criterion->list_num === 4 && ! $this->isInteractionHit($criterion, $matched)) {
                continue;
            }

            $seen[] = $criterion->id;
            $matches->push(
                $this->upsertFinding($review, $existing->get($criterion->id), $criterion, $matched, $position++)
            );
        }

        // Criteria that no longer match (medication stopped, schema changed).
        $review->findings()
            ->where('source', ReviewFinding::SOURCE_GHEOPS)
            ->when($seen !== [], fn ($q) => $q->where(function ($q) use ($seen) {
                $q->whereNull('gheops_criterion_id')->orWhereNotIn('gheops_criterion_id', $seen);
            }))
            ->delete();

        $review->update(['gheops_screened_at' => now()]);

        return $matches;
    }

    /**
     * Screen unless this review has been screened before. Used by every path
     * that opens a review without the pharmacist pressing "Nieuwe review"
     * (the Phil job, phil:fetch, and opening an old review). A finalized
     * review is a closed record and is never touched — use screen() for that.
     */
    public function ensureScreened(Review $review, bool $force = false): void
    {
        if ($review->status === Review::STATUS_FINALIZED) {
            return;
        }
        if (! $force && $review->gheops_screened_at !== null) {
            return;
        }
        if ($resident = $review->resident) {
            $this->screen($resident, $review);
        }
    }

    /**
     * @return array<string, string> slug => display
     */
    private function residentIngredients(Resident $resident): array
    {
        $rows = MedicationSchedule::query()
            ->where('resident_id', $resident->id)
            ->active()
            ->with('medication.activeIngredients')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            foreach ($row->medication?->activeIngredients ?? [] as $ai) {
                $out[$ai->slug] = $ai->name;
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $residentInns
     * @return array<string, string> slug => display for matches
     */
    private function matchCriterion(GheopsCriterion $criterion, array $residentInns): array
    {
        $matches = [];

        // (a) Direct slug match.
        $criterionSlugs = $criterion->activeIngredients->pluck('slug')->all();
        foreach ($residentInns as $slug => $display) {
            if (in_array($slug, $criterionSlugs, true)) {
                $matches[$slug] = $display;
            }
        }

        // (b) Text match against criterion title.
        $title = mb_strtolower($criterion->title ?? '');
        if ($title !== '') {
            foreach ($residentInns as $slug => $display) {
                $base = self::baseInn($display !== '' ? $display : $slug);
                if (mb_strlen($base) < 5) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($base, '/') . '\b/u', $title)) {
                    $matches[$slug] = $display;
                }
            }
        }

        // (c) Drug class match.
        foreach ($criterion->groups as $group) {
            $klass = mb_strtolower($group->drug_class);
            $members = $this->classMembers()[$klass] ?? null;
            if (! $members) {
                continue;
            }
            foreach ($residentInns as $slug => $display) {
                $base = self::baseInn($display !== '' ? $display : $slug);
                if ($base !== '' && in_array($base, $members, true)) {
                    $matches[$slug] = $display;
                }
            }
        }

        return $matches;
    }

    private function isInteractionHit(GheopsCriterion $criterion, array $matched): bool
    {
        $crit = $criterion->title ?? '';
        if (! str_contains($crit, '+')) {
            return count($matched) >= 2;
        }
        $sides = array_map('trim', explode('+', $crit));
        foreach ($sides as $side) {
            $sideLower = mb_strtolower($side);
            $hit = false;
            foreach ($matched as $slug => $display) {
                $base = self::baseInn($display !== '' ? $display : $slug);
                if ($base !== '' && preg_match('/\b' . preg_quote($base, '/') . '\b/u', $sideLower)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                foreach ($this->classMembers() as $klass => $members) {
                    if (! preg_match('/\b' . preg_quote($klass, '/') . '\b/u', $sideLower)) {
                        continue;
                    }
                    foreach ($matched as $slug => $display) {
                        $base = self::baseInn($display !== '' ? $display : $slug);
                        if (in_array($base, $members, true)) {
                            $hit = true;
                            break 2;
                        }
                    }
                }
            }
            if (! $hit) {
                return false;
            }
        }
        return count($sides) >= 2;
    }

    private function upsertFinding(
        Review $review,
        ?ReviewFinding $existing,
        GheopsCriterion $criterion,
        array $matched,
        int $position,
    ): ReviewFinding {
        $matchStr = count($matched) > 0 ? implode(', ', $matched) : '(groep-match)';
        $body = [];
        if ($criterion->comorbiditeit) {
            $body[] = '_Comorbiditeit:_ ' . $criterion->comorbiditeit;
        }
        if ($criterion->rationale) {
            $body[] = '_Rationale:_ ' . $criterion->rationale;
        }
        if ($criterion->alternatief) {
            $body[] = '_Alternatief:_ ' . $criterion->alternatief;
        }
        $body[] = '_Matches:_ ' . $matchStr;

        $attributes = [
            'title' => "Lijst {$criterion->list_num}, Criterium {$criterion->nr}: {$criterion->title}",
            'body_md' => implode("\n\n", $body),
            'fingerprint' => 'gheops:' . $criterion->id,
            'position' => $position,
        ];

        // note_md and dismissed_at are deliberately left alone.
        if ($existing) {
            $existing->update($attributes);
            // A refresh also fills in what she wrote about this criterium
            // before, but only where she has not written anything herself.
            if ($this->library->suggest($existing)) {
                $existing->save();
            }
            return $existing;
        }

        $finding = ReviewFinding::make($attributes + [
            'review_id' => $review->id,
            'source' => ReviewFinding::SOURCE_GHEOPS,
            'gheops_criterion_id' => $criterion->id,
        ]);
        $this->library->suggest($finding);
        $finding->save();

        return $finding;
    }

    /**
     * @return Collection<int, GheopsCriterion>
     */
    private function criteria(): Collection
    {
        if ($this->criteriaCache === null) {
            $this->criteriaCache = GheopsCriterion::with(['activeIngredients:id,slug', 'groups'])
                ->orderBy('list_num')
                ->orderBy('nr')
                ->get()
                ->all();
        }
        return collect($this->criteriaCache);
    }

    /**
     * Returns drug_class (lower-case) → list of INN base words (lowercased).
     *
     * @return array<string, array<string>>
     */
    private function classMembers(): array
    {
        if ($this->classMembersByClass !== null) {
            return $this->classMembersByClass;
        }
        $rows = DrugClassIngredient::with('activeIngredient:id,slug,name')->get();
        $out = [];
        foreach ($rows as $row) {
            $klass = mb_strtolower($row->drug_class);
            $name = $row->activeIngredient->name ?? '';
            $base = self::baseInn($name !== '' ? $name : ($row->activeIngredient->slug ?? ''));
            if ($base === '') {
                continue;
            }
            $out[$klass][] = $base;
        }
        foreach ($out as $k => $list) {
            $out[$k] = array_values(array_unique($list));
        }
        return $this->classMembersByClass = $out;
    }

    public static function baseInn(string $display): string
    {
        $cleaned = mb_strtolower(str_replace('-', ' ', trim($display)));
        $parts = preg_split('/\s+/', $cleaned) ?: [];
        return $parts[0] ?? '';
    }
}
