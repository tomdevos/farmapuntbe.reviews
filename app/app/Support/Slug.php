<?php

namespace App\Support;

class Slug
{
    /**
     * NFKD-strip-accents → lowercase → [^a-z0-9]+ to "-" → trim.
     * Must stay consistent with sources/build_resident_notes.py slugify().
     */
    public static function make(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KD) ?: $value;
        }
        $value = preg_replace('/\p{M}+/u', '', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Splits "Voornaam ACHTERNAAM" into [first, last]. The surname is the
     * trailing run of all-uppercase tokens (e.g. "VAN DEN NEST").
     */
    public static function splitResidentName(string $full): array
    {
        $tokens = preg_split('/\s+/', trim($full)) ?: [];
        if (count($tokens) === 0) {
            return ['', ''];
        }
        $surname = [];
        while (count($tokens) > 0) {
            $last = end($tokens);
            $cleaned = preg_replace('/[^\p{L}\']/u', '', $last) ?? '';
            if ($cleaned !== '' && mb_strtoupper($cleaned, 'UTF-8') === $cleaned) {
                array_unshift($surname, array_pop($tokens));
            } else {
                break;
            }
        }
        $first = trim(implode(' ', $tokens));
        $last = trim(implode(' ', $surname));
        if ($last === '') {
            $last = $first;
            $first = '';
        }
        return [$first, $last];
    }
}
