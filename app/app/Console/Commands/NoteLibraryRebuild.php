<?php

namespace App\Console\Commands;

use App\Models\ReviewFinding;
use App\Services\NoteLibrary;
use Illuminate\Console\Command;

/**
 * Vult de uitleg-bibliotheek met alles wat al in bestaande reviews staat, zodat
 * ze niet leeg begint. Idempotent: de meest recent bewaarde tekst wint en het
 * aantal gebruiken wordt geteld, niet opgeteld.
 */
class NoteLibraryRebuild extends Command
{
    protected $signature = 'review:notes-rebuild';

    protected $description = 'Bouw de uitleg-bibliotheek op uit de uitleg die al in reviews staat';

    public function handle(NoteLibrary $library): int
    {
        /** @var array<string, array{finding: ReviewFinding, count: int}> $perKey */
        $perKey = [];

        ReviewFinding::query()
            ->where(fn ($q) => $q->whereNotNull('note_md')->orWhereNotNull('body_md'))
            ->orderBy('updated_at')
            ->chunkById(200, function ($findings) use ($library, &$perKey) {
                foreach ($findings as $finding) {
                    // Een voorstel dat ze nog niet nagekeken heeft, is geen bron
                    // — anders bevestigt de bibliotheek zichzelf.
                    if ($finding->note_suggested_at) {
                        continue;
                    }
                    if (trim((string) $finding->{NoteLibrary::field($finding)}) === '') {
                        continue;
                    }
                    $key = $library->keyFor($finding);
                    if ($key === null) {
                        continue;
                    }
                    // Oplopend op updated_at, dus de laatst bewaarde tekst wint.
                    $perKey[$key] = [
                        'finding' => $finding,
                        'count' => ($perKey[$key]['count'] ?? 0) + 1,
                    ];
                }
            });

        foreach ($perKey as $entry) {
            $template = $library->remember($entry['finding']);
            $template?->update(['times_used' => $entry['count']]);
        }

        $this->info(count($perKey) . ' zin(nen) in de uitleg-bibliotheek.');

        return self::SUCCESS;
    }
}
