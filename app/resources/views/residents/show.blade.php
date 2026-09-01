<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $resident->display_name }}
            </h2>
            <a href="{{ route('departments.show', $resident->department) }}" class="text-sm text-emerald-700 hover:underline">
                ← Afdeling {{ $resident->department->name }}
            </a>
        </div>
    </x-slot>

    @php
        // Time-slot columns follow the data: union of all dosage keys in the
        // active schema (carecenters use different rounds), standard grid as fallback.
        $timeSlots = $chronic->concat($temp)
            ->pluck('dosages')
            ->filter()
            ->flatMap(fn ($d) => array_keys($d))
            ->filter(fn ($k) => preg_match('/^\d{1,2}:\d{2}$/', (string) $k))
            ->unique()->sort()->values()->all();
        if (count($timeSlots) === 0) {
            $timeSlots = ['06:00', '08:00', '12:00', '14:00', '17:00', '18:00', '20:00', '22:00'];
        }
    @endphp

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 bg-emerald-50 text-emerald-800 rounded">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-3 bg-red-50 text-red-700 rounded">
                    @foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div><span class="text-gray-500">Kamer:</span> <strong>{{ $resident->room ?: '—' }}</strong></div>
                    <div><span class="text-gray-500">Afdeling:</span> <strong>{{ $resident->department->name }}</strong></div>
                    <div><span class="text-gray-500">Zorginstelling:</span> <strong>{{ $resident->department->careCenter->name }}</strong></div>
                    <div><span class="text-gray-500">Behandelend arts:</span> <strong>{{ $resident->doctor_name ?: '—' }}</strong></div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-semibold">Therapieschema</h3>
                    @php
                        // Eén klik maakte vroeger meteen een nieuwe review aan; de
                        // bevestiging noemt daarom wat er staat te gebeuren.
                        $openReview = $resident->reviews->firstWhere('status', 'draft');
                        $lastFinalized = $resident->reviews->firstWhere('status', 'finalized');
                        $confirmMsg = $openReview
                            ? 'Er loopt al een review sinds ' . $openReview->started_on->format('d-m-Y') . '. Je gaat verder in die review. Doorgaan?'
                            : ($lastFinalized
                                ? 'De vorige review is gefinaliseerd op ' . $lastFinalized->finalized_at?->format('d-m-Y') . '. Toch een nieuwe review starten?'
                                : 'Een nieuwe review starten voor ' . $resident->display_name . '?');
                    @endphp
                    <form method="POST" action="{{ route('reviews.start', $resident) }}"
                          onsubmit="return confirm(@js($confirmMsg))">
                        @csrf
                        <button type="submit" class="text-sm text-emerald-700 hover:underline">
                            {{ $openReview ? 'Verder in lopende review →' : 'Nieuwe review starten →' }}
                        </button>
                    </form>
                </div>

                @foreach ([['Chronische medicatie', $chronic], ['Tijdelijke medicatie', $temp]] as [$title, $rows])
                    <div class="px-6 pt-4 pb-2">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $title }}</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-3 py-2 text-left">Medicatie</th>
                                    <th class="px-3 py-2 text-left">Actief bestanddeel</th>
                                    <th class="px-3 py-2 text-left">Frequentie</th>
                                    <th class="px-3 py-2 text-left">Begin</th>
                                    <th class="px-3 py-2 text-left">Einde</th>
                                    @foreach ($timeSlots as $slot)
                                        <th class="px-2 py-2 text-center">{{ $slot }}</th>
                                    @endforeach
                                    <th class="px-3 py-2 text-left">Opmerkingen</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($rows as $row)
                                    @php($d = $row->dosages ?? [])
                                    <tr>
                                        <td class="px-3 py-2 font-medium">{{ $row->medication->name }}</td>
                                        <td class="px-3 py-2">
                                            @foreach ($row->medication->activeIngredients as $ai)
                                                <span class="inline-block px-1.5 py-0.5 mr-1 mb-1 bg-emerald-50 text-emerald-800 rounded text-[10px]">{{ $ai->name }}</span>
                                            @endforeach
                                        </td>
                                        <td class="px-3 py-2 text-gray-500">{{ $row->frequency }}</td>
                                        <td class="px-3 py-2 text-gray-500">{{ optional($row->start_on)->format('d-m-Y') }}</td>
                                        <td class="px-3 py-2 text-gray-500">{{ optional($row->end_on)->format('d-m-Y') }}</td>
                                        @foreach ($timeSlots as $slot)
                                            <td class="px-2 py-2 text-center {{ ($d[$slot] ?? null) ? 'font-semibold text-emerald-700' : 'text-gray-300' }}">
                                                {{ $d[$slot] ?? '' }}
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2 text-gray-500">{{ $row->notes }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ 6 + count($timeSlots) }}" class="px-3 py-3 text-gray-500 italic">Geen.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endforeach

                <div class="px-6 pt-4 pb-2">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Indien nodig medicatie</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-3 py-2 text-left">Medicatie</th>
                                <th class="px-3 py-2 text-left">Min</th>
                                <th class="px-3 py-2 text-left">Max</th>
                                <th class="px-3 py-2 text-left">Max/dag</th>
                                <th class="px-3 py-2 text-left">Geven bij</th>
                                <th class="px-3 py-2 text-left">Interval</th>
                                <th class="px-3 py-2 text-left">Opmerkingen</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($prn as $row)
                                @php($d = $row->dosages ?? [])
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $row->medication->name }}</td>
                                    <td class="px-3 py-2">{{ $d['min'] ?? '' }}</td>
                                    <td class="px-3 py-2">{{ $d['max'] ?? '' }}</td>
                                    <td class="px-3 py-2">{{ $d['max_per_day'] ?? '' }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $d['trigger'] ?? '' }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $d['interval'] ?? '' }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ $row->notes }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-3 text-gray-500 italic">Geen.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($forbidden->isNotEmpty())
                    <div class="px-6 pt-4 pb-2">
                        <h4 class="text-sm font-semibold text-red-700">Verboden medicatie</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs divide-y divide-gray-200">
                            <thead class="bg-red-50">
                                <tr>
                                    <th class="px-3 py-2 text-left">Medicatie</th>
                                    <th class="px-3 py-2 text-left">Reden</th>
                                    <th class="px-3 py-2 text-left">Opmerkingen</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($forbidden as $row)
                                    <tr>
                                        <td class="px-3 py-2 font-medium">{{ $row->medication->name }}</td>
                                        <td class="px-3 py-2">{{ ($row->dosages ?? [])['reden'] ?? '' }}</td>
                                        <td class="px-3 py-2 text-gray-500">{{ $row->notes }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold">Reviews</h3>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($resident->reviews as $review)
                        <li class="px-6 py-3 flex items-center justify-between">
                            <a href="{{ route('reviews.show', $review) }}" class="text-emerald-700 hover:underline">
                                {{ $review->started_on->format('d-m-Y') }} — {{ $review->status }}
                            </a>
                            <span class="text-sm text-gray-500">{{ $review->user?->name }}</span>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-gray-500">Nog geen reviews voor deze bewoner.</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</x-app-layout>
