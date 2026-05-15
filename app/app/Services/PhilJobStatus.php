<?php

namespace App\Services;

use App\Jobs\FetchPhilInteractions;
use App\Models\PhilInteraction;
use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view on Phil scraping state for a resident:
 *   - has the latest fetch run, when?
 *   - is a job currently pending in the queue?
 *   - is a job currently being executed by a worker?
 *   - did a previous job fail?
 *
 * Detection works against Laravel's database-backed `jobs` and `failed_jobs`
 * tables. The resident id is embedded in the serialized job payload — we
 * pattern-match on the standard PHP-serialised marker `"residentId";i:<n>;`.
 */
class PhilJobStatus
{
    public function for(Resident $resident): array
    {
        // Job payload is JSON-encoded, so the inner serialized PHP string
        // appears with escaped quotes (`\"residentId\"`). We match that form.
        $payloadNeedles = [
            class_basename(FetchPhilInteractions::class),
            sprintf('residentId\";i:%d;', $resident->id),
        ];

        $jobs = DB::table('jobs')
            ->where(function ($q) use ($payloadNeedles) {
                foreach ($payloadNeedles as $n) {
                    $q->where('payload', 'like', '%' . $n . '%');
                }
            })
            ->select(['id', 'reserved_at', 'available_at', 'attempts'])
            ->get();

        $pending = $jobs->whereNull('reserved_at')->count();
        $running = $jobs->whereNotNull('reserved_at')->count();

        $failed = DB::table('failed_jobs')
            ->where(function ($q) use ($payloadNeedles) {
                foreach ($payloadNeedles as $n) {
                    $q->where('payload', 'like', '%' . $n . '%');
                }
            })
            ->latest('failed_at')
            ->select(['failed_at', 'exception'])
            ->first();

        $latest = PhilInteraction::where('resident_id', $resident->id)
            ->latest('fetched_at')
            ->first();

        return [
            'pending' => $pending,
            'running' => $running,
            'has_jobs' => $pending + $running > 0,
            'last_fetched_at' => $latest?->fetched_at,
            'last_findings_count' => $latest ? $latest->findings()->count() : 0,
            'last_failed_at' => $failed?->failed_at ? Carbon::parse($failed->failed_at) : null,
            'last_failure' => $failed?->exception ? \Illuminate\Support\Str::limit(strtok($failed->exception, "\n"), 200) : null,
        ];
    }
}
