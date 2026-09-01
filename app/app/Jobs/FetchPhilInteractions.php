<?php

namespace App\Jobs;

use App\Models\Resident;
use App\Models\Review;
use App\Services\Scraping\PhilScraper;
use App\Services\Screening\GheopsScreener;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchPhilInteractions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public int $residentId, public ?int $reviewId = null) {}

    public function handle(PhilScraper $scraper, GheopsScreener $screener): void
    {
        $resident = Resident::findOrFail($this->residentId);

        // Prefer the explicitly-attached review; otherwise reuse the latest
        // draft or open a new one so the findings have a home.
        $review = $this->reviewId
            ? Review::find($this->reviewId)
            : Review::where('resident_id', $resident->id)
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

        $scraper->fetchForResident($resident, $review);
    }
}
