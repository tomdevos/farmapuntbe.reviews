<?php

namespace App\Support;

/**
 * Minimal YAML-frontmatter parser for the Obsidian vault notes.
 *
 * Mirrors the indented-list syntax used across sources/*.py:
 *   key: value
 *   key: ""
 *   list_key:
 *     - "item"
 *     - "item"
 */
class Frontmatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function parse(string $text): array
    {
        if (! str_starts_with($text, "---\n")) {
            return [[], $text];
        }
        $end = strpos($text, "\n---\n", 4);
        if ($end === false) {
            return [[], $text];
        }
        $raw = substr($text, 4, $end - 4);
        $body = substr($text, $end + 5);

        $meta = [];
        $currentKey = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^  - (.*)$/', $line, $m)) {
                if ($currentKey === null) {
                    continue;
                }
                if (! isset($meta[$currentKey]) || ! is_array($meta[$currentKey])) {
                    $meta[$currentKey] = [];
                }
                $meta[$currentKey][] = self::unquote($m[1]);
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $m)) {
                $currentKey = $m[1];
                $value = trim($m[2]);
                if ($value === '') {
                    $meta[$currentKey] = [];
                } else {
                    $meta[$currentKey] = self::unquote($value);
                }
            }
        }

        return [$meta, $body];
    }

    private static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);
                return str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $inner);
            }
        }
        return $value;
    }
}
