<?php

namespace App\Services\Screening;

use App\Models\ActiveIngredient;
use App\Models\DrugClassIngredient;
use App\Models\GheopsCriterion;
use App\Models\MedicationSchedule;
use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewFinding;
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

        // Wipe existing GheOPS findings for this review — same-day reruns replace.
        $review->findings()->where('source', ReviewFinding::SOURCE_GHEOPS)->delete();

        $matches = collect();
        $position = 0;

        foreach ($this->criteria() as $criterion) {
            $matched = $this->matchCriterion($criterion, $residentInns);
            if (count($matched) === 0) {
                continue;
            }
            if ($criterion->list_num === 4 && ! $this->isInteractionHit($criterion, $matched)) {
                continue;
            }

            $finding = $this->createFinding($review, $criterion, $matched, $position++);
            $matches->push($finding);
        }

        return $matches;
    }

    /**
     * @return array<string, string> slug => display
     */
    private function residentIngredients(Resident $resident): array
    {
        $rows = MedicationSchedule::query()
            ->where('resident_id', $resident->id)
            ->whereIn('schedule_type', [
                MedicationSchedule::TYPE_CHRONIC,
                MedicationSchedule::TYPE_TEMP,
                MedicationSchedule::TYPE_PRN,
            ])
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

    private function createFinding(
        Review $review,
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

        return ReviewFinding::create([
            'review_id' => $review->id,
            'source' => ReviewFinding::SOURCE_GHEOPS,
            'gheops_criterion_id' => $criterion->id,
            'title' => "Lijst {$criterion->list_num}, Criterium {$criterion->nr}: {$criterion->title}",
            'body_md' => implode("\n\n", $body),
            'position' => $position,
        ]);
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
