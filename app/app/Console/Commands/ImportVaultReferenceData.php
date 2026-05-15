<?php

namespace App\Console\Commands;

use App\Models\ActiveIngredient;
use App\Models\Medication;
use App\Support\Frontmatter;
use App\Support\Slug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportVaultReferenceData extends Command
{
    protected $signature = 'reference:import
                            {--vault= : Override path to the Obsidian vault root}
                            {--dry-run : Parse and report counts without writing}';

    protected $description = 'Idempotent import of CNK + INN reference data from the Obsidian vault into the database.';

    private const AI_LINK_RE = '/\[\[knowledgebase\/active-ingredient\/([^\\\\|\]]+)\\\\*\|([^\]]+)\]\]/';

    public function handle(): int
    {
        $vaultPath = $this->option('vault') ?? env('VAULT_PATH', dirname(base_path()));
        $vault = rtrim((string) $vaultPath, '/');
        $innDir = $vault . '/knowledgebase/active-ingredient';
        $cnkDir = $vault . '/knowledgebase/cnk';

        if (! is_dir($innDir) || ! is_dir($cnkDir)) {
            $this->error("Vault not found at {$vault}.");
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $innCount = $this->importIngredients($innDir, $dry);
        [$cnkCount, $linkCount] = $this->importMedications($cnkDir, $dry);

        $this->info("INN entries:        {$innCount}");
        $this->info("CNK entries:        {$cnkCount}");
        $this->info("CNK→INN links:      {$linkCount}");
        if ($dry) {
            $this->warn('Dry-run — no changes persisted.');
        }
        return self::SUCCESS;
    }

    private function importIngredients(string $dir, bool $dry): int
    {
        $count = 0;
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $slug = Slug::make(basename($file, '.md'));
            if ($slug === '') {
                continue;
            }
            $contents = (string) file_get_contents($file);
            [$meta] = Frontmatter::parse($contents);

            $name = is_string($meta['naam'] ?? null) && $meta['naam'] !== ''
                ? (string) $meta['naam']
                : str_replace('-', ' ', $slug);
            $atc = is_string($meta['atc_code'] ?? null) ? (string) $meta['atc_code'] : null;
            $klass = is_string($meta['klasse'] ?? null) ? (string) $meta['klasse'] : null;

            if (! $dry) {
                ActiveIngredient::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'atc_code' => $atc !== '' ? $atc : null,
                        'drug_class' => $klass !== '' ? $klass : null,
                    ],
                );
            }
            $count++;
        }
        return $count;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function importMedications(string $dir, bool $dry): array
    {
        $cnkCount = 0;
        $linkCount = 0;

        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $cnk = Medication::normalizeCnk(basename($file, '.md'));
            if ($cnk === null) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            [$meta] = Frontmatter::parse($contents);

            $payload = [
                'name' => (string) ($meta['medicatienaam'] ?? $cnk),
                'brand' => is_string($meta['merknaam'] ?? null) ? $meta['merknaam'] : null,
                'manufacturer' => is_string($meta['producent'] ?? null) ? $meta['producent'] : null,
                'form' => is_string($meta['vorm'] ?? null) ? $meta['vorm'] : null,
                'atc_code' => is_string($meta['atc_code'] ?? null) ? $meta['atc_code'] : null,
                'category' => is_string($meta['categorie'] ?? null) ? $meta['categorie'] : null,
                'is_robot' => $this->asBool($meta['is_robot'] ?? null),
                'status' => is_string($meta['status'] ?? null) ? $meta['status'] : 'nog-niet-verrijkt',
                'bron' => is_string($meta['bron'] ?? null) ? $meta['bron'] : null,
                'aliassen' => $this->asList($meta['aliassen'] ?? null),
                'medicatiegroep' => $this->asList($meta['medicatiegroep'] ?? null),
            ];
            $payload = array_map(fn($v) => $v === '' ? null : $v, $payload);

            if ($dry) {
                $cnkCount++;
                continue;
            }

            DB::transaction(function () use ($cnk, $payload, $meta, &$cnkCount, &$linkCount) {
                $med = Medication::updateOrCreate(['cnk' => $cnk], $payload);
                $cnkCount++;

                $ingredientIds = [];
                $values = $meta['actief_bestanddeel'] ?? null;
                if (is_array($values)) {
                    foreach ($values as $raw) {
                        if (! is_string($raw)) {
                            continue;
                        }
                        $slug = $this->resolveIngredientSlug($raw);
                        if ($slug === null) {
                            continue;
                        }
                        $ingredient = ActiveIngredient::firstOrCreate(
                            ['slug' => $slug],
                            ['name' => str_replace('-', ' ', $slug)],
                        );
                        $ingredientIds[$ingredient->id] = true;
                    }
                }
                $med->activeIngredients()->sync(array_keys($ingredientIds));
                $linkCount += count($ingredientIds);
            });
        }

        return [$cnkCount, $linkCount];
    }

    private function resolveIngredientSlug(string $raw): ?string
    {
        if (preg_match(self::AI_LINK_RE, $raw, $m)) {
            return Slug::make($m[1]);
        }
        return Slug::make($raw) ?: null;
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'ja'], true);
        }
        return false;
    }

    private function asList(mixed $value): ?array
    {
        if (! is_array($value) || count($value) === 0) {
            return null;
        }
        return array_values(array_filter($value, fn($v) => is_string($v) && $v !== ''));
    }
}
