<?php

namespace App\Services;

use App\Models\FindingNoteTemplate;
use App\Models\ReviewFinding;
use App\Support\Slug;
use Illuminate\Support\Str;

/**
 * The pharmacist writes the same sentence over and over ("Controle Kalium
 * wegens risico hypokaliëmie"). This keeps one copy per recurring finding and
 * offers it again on the next review, for any resident in any wzc.
 */
class NoteLibrary
{
    /**
     * The field holding the pharmacist's own text. For generated findings that
     * is note_md (body_md is overwritten on every refresh); a manual
     * observation has no generated half, so its text lives in body_md.
     */
    public static function field(ReviewFinding $finding): string
    {
        return $finding->source === ReviewFinding::SOURCE_MANUAL ? 'body_md' : 'note_md';
    }

    /**
     * Stable across residents, reviews and word order — deliberately unlike
     * review_findings.fingerprint, which also keys on severity and on the order
     * Phil happened to list the pair in.
     */
    public function keyFor(ReviewFinding $finding): ?string
    {
        $title = trim((string) $finding->title);
        if ($title === '') {
            return null;
        }

        if ($finding->source === ReviewFinding::SOURCE_GHEOPS) {
            return $finding->gheops_criterion_id ? 'gheops:' . $finding->gheops_criterion_id : null;
        }

        if ($finding->source === ReviewFinding::SOURCE_PHIL) {
            $food = (bool) preg_match('/\(voedingsinteractie\)\s*$/u', $title);
            $bare = trim(preg_replace('/\s*\(voedingsinteractie\)\s*$/u', '', $title) ?? $title);
            $sides = array_values(array_filter(array_map(
                fn ($s) => mb_strtolower(trim($s), 'UTF-8'),
                explode(' + ', $bare),
            )));
            if (count($sides) !== 2) {
                return null;
            }
            sort($sides);
            return ($food ? 'philfood:' : 'phil:') . sha1(implode('|', $sides));
        }

        $slug = Slug::make(str_contains($title, ':') ? Str::before($title, ':') : $title);
        return $slug === '' ? null : 'manual:' . $slug;
    }

    /**
     * Fill the finding's text from the library when it is still empty. Returns
     * true when something was filled in — never overwrites what is already
     * there, and never saves.
     */
    public function suggest(ReviewFinding $finding): bool
    {
        $field = self::field($finding);
        if (trim((string) $finding->{$field}) !== '') {
            return false;
        }

        $key = $this->keyFor($finding);
        if ($key === null) {
            return false;
        }

        $template = FindingNoteTemplate::where('key', $key)->first();
        if (! $template) {
            return false;
        }

        $finding->{$field} = $template->note_md;
        $finding->note_suggested_at = now();

        return true;
    }

    /**
     * Store (or refresh) the sentence for this finding. Called after the
     * pharmacist saves, so the library always holds her latest wording.
     */
    public function remember(ReviewFinding $finding): ?FindingNoteTemplate
    {
        $text = trim((string) $finding->{self::field($finding)});
        $key = $this->keyFor($finding);
        if ($text === '' || $key === null) {
            return null;
        }

        $existing = FindingNoteTemplate::where('key', $key)->first();
        if ($existing) {
            $existing->update([
                'label' => $this->label($finding),
                'note_md' => $text,
                'times_used' => $existing->times_used + 1,
                'last_used_at' => now(),
            ]);
            return $existing;
        }

        return FindingNoteTemplate::create([
            'key' => $key,
            'source' => $finding->source,
            'label' => $this->label($finding),
            'note_md' => $text,
            'times_used' => 1,
            'last_used_at' => now(),
        ]);
    }

    private function label(ReviewFinding $finding): string
    {
        $title = trim((string) $finding->title);
        if ($finding->source === ReviewFinding::SOURCE_MANUAL && str_contains($title, ':')) {
            $title = rtrim(Str::before($title, ':'));
        }
        return Str::limit($title, 250, '');
    }
}
