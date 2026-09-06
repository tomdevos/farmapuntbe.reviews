<?php

namespace App\Services\Export;

use App\Models\ReviewFinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a review's findings into the lines the doctor's document shows:
 * one green label ("Durogesic + Lorazepam:") with the grey explanation
 * underneath. Both exporters (docx and pdf) go through here so the layout
 * cannot drift apart again.
 */
class FindingPresenter
{
    /**
     * @param  Collection<int, ReviewFinding>  $findings
     * @return list<array{label: string, lines: list<string>}>
     */
    public function present(Collection $findings): array
    {
        $rows = $findings
            ->whereNull('dismissed_at')
            ->sortBy('position')
            ->values()
            ->map(fn (ReviewFinding $f) => $this->row($f))
            ->all();

        return $this->bundle($rows);
    }

    /**
     * @return array{label: string, lines: list<string>, note: string, pair: ?array{string, string}}
     */
    private function row(ReviewFinding $finding): array
    {
        $title = trim((string) $finding->title);

        // Split on the FIRST colon, and only when there is one. Str::beforeLast()
        // and Str::after() both return the whole subject when the needle is
        // missing, which is how the product name used to be printed twice.
        $label = $title;
        $rest = '';
        if (str_contains($title, ':')) {
            $label = rtrim(Str::before($title, ':'));
            $rest = trim(Str::after($title, ':'));
        }

        $note = trim((string) $finding->note_md);
        $lines = array_values(array_filter([
            $rest,
            $this->plain((string) $finding->body_md),
            $note,
        ], fn ($line) => $line !== ''));

        return [
            'label' => $label,
            'lines' => $lines,
            'note' => $note,
            'pair' => $this->pair($label),
        ];
    }

    /**
     * GheopsScreener writes "_Rationale:_ …"; PhpWord and Blade print those
     * underscores literally, so strip the markdown emphasis.
     */
    private function plain(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        return preg_replace('/_([^_\n]+)_/u', '$1', $body) ?? $body;
    }

    /**
     * "A + B" → ['A', 'B']. Anything else (no plus, or more than two sides)
     * cannot be bundled.
     *
     * @return ?array{string, string}
     */
    private function pair(string $label): ?array
    {
        $label = trim(preg_replace('/\s*\(voedingsinteractie\)\s*$/u', '', $label) ?? $label);
        $sides = array_map('trim', explode(' + ', $label));
        if (count($sides) !== 2 || $sides[0] === '' || $sides[1] === '') {
            return null;
        }
        return [$sides[0], $sides[1]];
    }

    /**
     * Findings that carry the exact same explanation are merged onto one line,
     * the way the pharmacist does by hand in Word:
     * "Durogesic + Lorazepam, Tradonal Odis en Tramadol Retard: …".
     *
     * @param  list<array{label: string, lines: list<string>, note: string, pair: ?array{string, string}}>  $rows
     * @return list<array{label: string, lines: list<string>}>
     */
    private function bundle(array $rows): array
    {
        $groups = [];
        foreach ($rows as $i => $row) {
            if ($row['note'] === '' || $row['pair'] === null) {
                continue;
            }
            $groups[$this->normalize($row['note'])][] = $i;
        }

        $merged = [];   // index of the row that carries the bundle → label
        $absorbed = []; // indexes folded into an earlier row
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $pairs = array_map(fn ($i) => $rows[$i]['pair'], $indexes);
            $label = $this->bipartiteLabel($pairs);
            if ($label === null) {
                continue;
            }
            $first = array_shift($indexes);
            $merged[$first] = $label;
            foreach ($indexes as $i) {
                $absorbed[$i] = true;
            }
        }

        $out = [];
        foreach ($rows as $i => $row) {
            if (isset($absorbed[$i])) {
                continue;
            }
            $label = $merged[$i] ?? $row['label'];
            // A bundled line drops the per-finding leftovers (rest-of-title,
            // generated body) and keeps the shared explanation.
            $lines = isset($merged[$i]) ? [$row['note']] : $row['lines'];
            $out[] = ['label' => $label, 'lines' => $lines];
        }

        return $out;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');
    }

    /**
     * The pairs only merge when together they form a complete bipartite set
     * (every left member combined with every right member). Anything else is
     * left as separate lines rather than inventing a combination.
     *
     * @param  list<array{string, string}>  $pairs
     */
    private function bipartiteLabel(array $pairs): ?string
    {
        $left = [];
        $right = [];
        $side = function (string $name) use (&$left, &$right): ?string {
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($left[$key])) {
                return 'left';
            }
            if (isset($right[$key])) {
                return 'right';
            }
            return null;
        };
        $add = function (array &$bucket, string $name): void {
            $bucket[mb_strtolower($name, 'UTF-8')] ??= $name;
        };

        [$a, $b] = $pairs[0];
        $add($left, $a);
        $add($right, $b);

        foreach (array_slice($pairs, 1) as [$a, $b]) {
            $sideA = $side($a);
            $sideB = $side($b);
            if ($sideA === 'left' || $sideB === 'right') {
                $add($left, $a);
                $add($right, $b);
            } elseif ($sideA === 'right' || $sideB === 'left') {
                $add($right, $a);
                $add($left, $b);
            } else {
                return null; // Unrelated pair: nothing ties it to this group.
            }
        }

        // Every left member must be combined with every right member exactly
        // once; a partial set would suggest combinations Phil never reported.
        $seen = [];
        foreach ($pairs as [$a, $b]) {
            $a = mb_strtolower($a, 'UTF-8');
            $b = mb_strtolower($b, 'UTF-8');
            if (isset($left[$a]) && isset($right[$b])) {
                $seen["$a|$b"] = true;
            } elseif (isset($left[$b]) && isset($right[$a])) {
                $seen["$b|$a"] = true;
            } else {
                return null;
            }
        }
        if (count($seen) !== count($pairs) || count($seen) !== count($left) * count($right)) {
            return null;
        }

        return $this->joinNl(array_values($left)) . ' + ' . $this->joinNl(array_values($right));
    }

    /**
     * @param  list<string>  $items
     */
    private function joinNl(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' en ' . $last;
    }
}
