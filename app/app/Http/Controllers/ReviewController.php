<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\Review;
use App\Models\ReviewAttention;
use App\Models\ReviewFinding;
use App\Jobs\FetchPhilInteractions;
use App\Services\PhilJobStatus;
use App\Services\Screening\GheopsScreener;
use App\Services\Scraping\PhilScraper;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function start(Request $request, Resident $resident, GheopsScreener $screener)
    {
        $review = Review::firstOrCreate(
            [
                'resident_id' => $resident->id,
                'status' => Review::STATUS_DRAFT,
            ],
            [
                'user_id' => $request->user()?->id,
                'started_on' => now()->toDateString(),
                'due_on' => now()->addMonths(6)->toDateString(),
            ],
        );

        $screener->screen($resident, $review);

        return redirect()->route('reviews.show', $review);
    }

    public function show(Review $review, PhilJobStatus $philStatus)
    {
        $review->load([
            'resident.department.careCenter',
            'findings.gheopsCriterion',
            'findings.activeIngredient',
            'attentions',
            'user',
        ]);

        $resident = $review->resident;
        $schedules = $resident->schedules()
            ->with(['medication.activeIngredients'])
            ->get()
            ->groupBy('schedule_type');

        return view('reviews.show', [
            'review' => $review,
            'resident' => $resident,
            'chronic' => $schedules['chronic'] ?? collect(),
            'temp' => $schedules['temp'] ?? collect(),
            'prn' => $schedules['prn'] ?? collect(),
            'forbidden' => $schedules['forbidden'] ?? collect(),
            'philStatus' => $philStatus->for($resident),
        ]);
    }

    public function refreshGheops(Review $review, GheopsScreener $screener)
    {
        $screener->screen($review->resident, $review);
        return redirect()->route('reviews.show', $review)->with('status', 'GheOPS-screening vernieuwd.');
    }

    public function refreshPhil(Review $review)
    {
        if (! env('PHIL_USER') || ! env('PHIL_PASS')) {
            return back()->withErrors(['phil' => 'Phil-credentials ontbreken in .env (PHIL_USER, PHIL_PASS).']);
        }
        FetchPhilInteractions::dispatch($review->resident_id, $review->id);
        return back()->with('status', 'Phil-fetch ingepland (queue).');
    }

    public function storeFinding(Request $request, Review $review)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body_md' => 'nullable|string',
        ]);

        ReviewFinding::create([
            'review_id' => $review->id,
            'source' => ReviewFinding::SOURCE_MANUAL,
            'title' => $data['title'],
            'body_md' => $data['body_md'] ?? null,
            'position' => ($review->findings()->max('position') ?? 0) + 1,
        ]);

        return back()->with('status', 'Observatie toegevoegd.');
    }

    public function updateFinding(Request $request, Review $review, ReviewFinding $finding)
    {
        abort_unless($finding->review_id === $review->id, 404);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body_md' => 'sometimes|nullable|string',
            'dismiss' => 'sometimes|boolean',
        ]);

        if (array_key_exists('dismiss', $data)) {
            $finding->dismissed_at = $data['dismiss'] ? now() : null;
        }
        if (array_key_exists('title', $data)) $finding->title = $data['title'];
        if (array_key_exists('body_md', $data)) $finding->body_md = $data['body_md'];
        $finding->save();

        return back();
    }

    public function destroyFinding(Review $review, ReviewFinding $finding)
    {
        abort_unless($finding->review_id === $review->id, 404);
        $finding->delete();
        return back();
    }

    public function storeAttention(Request $request, Review $review)
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'body_md' => 'nullable|string',
        ]);

        ReviewAttention::create([
            'review_id' => $review->id,
            'label' => $data['label'],
            'body_md' => $data['body_md'] ?? null,
            'position' => ($review->attentions()->max('position') ?? 0) + 1,
        ]);

        return back()->with('status', 'Aandachtspunt toegevoegd.');
    }

    public function destroyAttention(Review $review, ReviewAttention $attention)
    {
        abort_unless($attention->review_id === $review->id, 404);
        $attention->delete();
        return back();
    }

    public function finalize(Review $review)
    {
        $review->update([
            'status' => Review::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);
        return back()->with('status', 'Review gefinaliseerd.');
    }
}
