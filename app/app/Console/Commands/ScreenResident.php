<?php

namespace App\Console\Commands;

use App\Models\Resident;
use App\Models\Review;
use App\Services\Screening\GheopsScreener;
use Illuminate\Console\Command;

class ScreenResident extends Command
{
    protected $signature = 'gheops:screen
                            {resident? : resident slug; omit + --all to screen everyone}
                            {--all}
                            {--department= : department slug}
                            {--user= : user id to attribute the review to}';

    protected $description = 'Run GheOP³S screening for one or more residents, persisting findings on a draft review.';

    public function handle(GheopsScreener $screener): int
    {
        $query = Resident::query()->whereNull('archived_at');

        if ($department = $this->option('department')) {
            $query->whereHas('department', fn ($q) => $q->where('slug', $department));
        }

        if ($slug = $this->argument('resident')) {
            $query->where('slug', $slug);
        } elseif (! $this->option('all')) {
            $this->error('Specify a resident slug, --all, or --department.');
            return self::FAILURE;
        }

        $residents = $query->get();
        if ($residents->isEmpty()) {
            $this->warn('No residents matched.');
            return self::SUCCESS;
        }

        $userId = $this->option('user') ? (int) $this->option('user') : null;

        foreach ($residents as $resident) {
            $review = Review::firstOrCreate(
                [
                    'resident_id' => $resident->id,
                    'started_on' => now()->toDateString(),
                    'status' => Review::STATUS_DRAFT,
                ],
                ['user_id' => $userId, 'due_on' => now()->addMonths(6)->toDateString()],
            );
            $findings = $screener->screen($resident, $review);
            $this->info(sprintf(
                '%s (%s) — %d findings',
                $resident->display_name,
                $resident->slug,
                $findings->count(),
            ));
        }

        return self::SUCCESS;
    }
}
