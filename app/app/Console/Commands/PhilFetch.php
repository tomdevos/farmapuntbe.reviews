<?php

namespace App\Console\Commands;

use App\Models\Resident;
use App\Models\Review;
use App\Services\Scraping\PhilScraper;
use App\Services\Screening\GheopsScreener;
use Illuminate\Console\Command;

class PhilFetch extends Command
{
    protected $signature = 'phil:fetch
                            {resident? : resident slug; omit + --all to fetch everyone}
                            {--all}
                            {--department= : department slug}
                            {--skip-fetched : skip residents whose draft review already has phil interactions}';

    protected $description = 'Fetch Phil interactions synchronously (local Chrome) for one or more residents.';

    public function handle(PhilScraper $scraper, GheopsScreener $screener): int
    {
        $query = Resident::query()->whereNull('archived_at');

        if ($department = $this->option('department')) {
            $query->whereHas('department', fn ($q) => $q->where('slug', $department));
        }

        if ($slug = $this->argument('resident')) {
            $query->where('slug', $slug);
        } elseif (! $this->option('all') && ! $this->option('department')) {
            $this->error('Specify a resident slug, --all, or --department.');
            return self::FAILURE;
        }

        $residents = $query->orderBy('slug')->get();
        if ($residents->isEmpty()) {
            $this->warn('No residents matched.');
            return self::SUCCESS;
        }

        $ok = $failed = $skipped = 0;
        foreach ($residents as $i => $resident) {
            $review = Review::where('resident_id', $resident->id)
                ->where('status', Review::STATUS_DRAFT)
                ->latest('started_on')
                ->first();

            if ($review === null) {
                $review = Review::create([
                    'resident_id' => $resident->id,
                    'user_id' => null,
                    'started_on' => now()->toDateString(),
                    'due_on' => now()->addMonths(6)->toDateString(),
                    'status' => Review::STATUS_DRAFT,
                ]);
            }

            // A review born here never went through the "Nieuwe review"-button,
            // so screen it or it would show up empty in the UI.
            $screener->ensureScreened($review);

            if ($this->option('skip-fetched') && $resident->philInteractions()->exists()) {
                $skipped++;
                continue;
            }

            $label = sprintf('[%d/%d] %s', $i + 1, $residents->count(), $resident->slug);
            try {
                $scraper->fetchForResident($resident, $review);
                $ok++;
                $this->info("$label OK");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("$label FAILED: {$e->getMessage()}");
            }
        }

        $this->info("Klaar: {$ok} gelukt, {$failed} gefaald, {$skipped} overgeslagen.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
