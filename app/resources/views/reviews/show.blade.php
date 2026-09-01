<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Review — {{ $resident->display_name }}
            </h2>
            <a href="{{ route('residents.show', $resident) }}" class="text-sm text-emerald-700 hover:underline">← Bewoner</a>
        </div>
    </x-slot>

    @php
        $findings = $review->findings->groupBy('source');
        $gheopsFindings = $findings['gheops'] ?? collect();
        $philFindings   = $findings['phil'] ?? collect();
        $manualFindings = $findings['manual'] ?? collect();
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

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm">
                <div><span class="text-gray-500">Datum:</span> <strong>{{ $review->started_on->format('d-m-Y') }}</strong></div>
                <div><span class="text-gray-500">Volgende review:</span> <strong>{{ optional($review->due_on)->format('d-m-Y') ?: '—' }}</strong></div>
                <div><span class="text-gray-500">Status:</span>
                    <strong class="{{ $review->status === 'finalized' ? 'text-emerald-700' : 'text-yellow-700' }}">{{ $review->status }}</strong>
                </div>
                <div><span class="text-gray-500">Apotheker:</span> <strong>{{ $review->user?->name ?? '—' }}</strong></div>
            </div>

            {{-- Therapieschema (collapsible) zodat de apotheker tijdens reviewen de medicatie zichtbaar houdt --}}
            <details class="bg-white dark:bg-gray-800 shadow rounded-lg" open>
                <summary class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 cursor-pointer flex items-center justify-between">
                    <h3 class="font-semibold">Therapieschema</h3>
                    <span class="text-xs text-gray-500">{{ $chronic->count() }} chronisch · {{ $temp->count() }} tijdelijk · {{ $prn->count() }} indien nodig · {{ $forbidden->count() }} verboden</span>
                </summary>

                @foreach ([['Chronische medicatie', $chronic], ['Tijdelijke medicatie', $temp]] as [$title, $rows])
                    @if ($rows->isNotEmpty())
                        <div class="px-6 pt-3 pb-1">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $title }}</h4>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-xs divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500">
                                    <tr>
                                        <th class="px-3 py-1.5 text-left font-medium">Medicatie</th>
                                        <th class="px-3 py-1.5 text-left font-medium">Actief bestanddeel</th>
                                        <th class="px-3 py-1.5 text-left font-medium">Freq.</th>
                                        @foreach ($timeSlots as $slot)
                                            <th class="px-1.5 py-1.5 text-center font-medium">{{ $slot }}</th>
                                        @endforeach
                                        <th class="px-3 py-1.5 text-left font-medium">Opmerkingen</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($rows as $row)
                                        @php($d = $row->dosages ?? [])
                                        <tr>
                                            <td class="px-3 py-1.5 font-medium">{{ $row->medication->name }}</td>
                                            <td class="px-3 py-1.5">
                                                @foreach ($row->medication->activeIngredients as $ai)
                                                    <span class="inline-block px-1 py-0.5 mr-1 bg-emerald-50 text-emerald-800 rounded text-[10px]">{{ $ai->name }}</span>
                                                @endforeach
                                            </td>
                                            <td class="px-3 py-1.5 text-gray-500">{{ $row->frequency }}</td>
                                            @foreach ($timeSlots as $slot)
                                                <td class="px-1.5 py-1.5 text-center {{ ($d[$slot] ?? null) ? 'font-semibold text-emerald-700' : 'text-gray-300' }}">
                                                    {{ $d[$slot] ?? '' }}
                                                </td>
                                            @endforeach
                                            <td class="px-3 py-1.5 text-gray-500">{{ $row->notes }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach

                @if ($prn->isNotEmpty())
                    <div class="px-6 pt-3 pb-1">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Indien nodig</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500">
                                <tr>
                                    <th class="px-3 py-1.5 text-left font-medium">Medicatie</th>
                                    <th class="px-3 py-1.5 text-left font-medium">Min</th>
                                    <th class="px-3 py-1.5 text-left font-medium">Max</th>
                                    <th class="px-3 py-1.5 text-left font-medium">Max/dag</th>
                                    <th class="px-3 py-1.5 text-left font-medium">Geven bij</th>
                                    <th class="px-3 py-1.5 text-left font-medium">Interval</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($prn as $row)
                                    @php($d = $row->dosages ?? [])
                                    <tr>
                                        <td class="px-3 py-1.5 font-medium">{{ $row->medication->name }}</td>
                                        <td class="px-3 py-1.5">{{ $d['min'] ?? '' }}</td>
                                        <td class="px-3 py-1.5">{{ $d['max'] ?? '' }}</td>
                                        <td class="px-3 py-1.5">{{ $d['max_per_day'] ?? '' }}</td>
                                        <td class="px-3 py-1.5 text-gray-500">{{ $d['trigger'] ?? '' }}</td>
                                        <td class="px-3 py-1.5 text-gray-500">{{ $d['interval'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($forbidden->isNotEmpty())
                    <div class="px-6 pt-3 pb-1">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-red-700">Verboden medicatie</h4>
                    </div>
                    <div class="overflow-x-auto pb-3">
                        <table class="min-w-full text-xs divide-y divide-gray-200 dark:divide-gray-700">
                            <tbody>
                                @foreach ($forbidden as $row)
                                    <tr>
                                        <td class="px-3 py-1.5 font-medium">{{ $row->medication->name }}</td>
                                        <td class="px-3 py-1.5 text-gray-500">{{ ($row->dosages ?? [])['reden'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </details>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {{-- 1. Auto-findings --}}
                <div class="space-y-6 lg:col-span-2">
                    <div id="gheops" class="bg-white dark:bg-gray-800 shadow rounded-lg scroll-mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold">GheOP³S-bevindingen ({{ $gheopsFindings->count() }})</h3>
                                <p class="text-xs text-gray-500">
                                    @if ($review->gheops_screened_at)
                                        Gescreend op {{ $review->gheops_screened_at->format('d-m-Y H:i') }}.
                                    @else
                                        Nog niet gescreend.
                                    @endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('reviews.refresh-gheops', $review) }}">
                                @csrf
                                <button class="text-sm text-emerald-700 hover:underline">↻ Vernieuwen</button>
                            </form>
                        </div>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($gheopsFindings as $f)
                                <li id="finding-{{ $f->id }}" class="px-6 py-3 scroll-mt-4 {{ $f->dismissed_at ? 'opacity-50' : '' }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-sm">
                                            <div class="font-medium {{ $f->dismissed_at ? 'line-through' : '' }}">{{ $f->title }}</div>
                                            @if ($f->body_md)
                                                <div class="text-gray-600 dark:text-gray-300 mt-1 whitespace-pre-line">{{ $f->body_md }}</div>
                                            @endif
                                        </div>
                                        <form method="POST" action="{{ route('reviews.findings.update', [$review, $f]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="dismiss" value="{{ $f->dismissed_at ? '0' : '1' }}">
                                            <button class="text-xs text-gray-500 hover:text-red-600">{{ $f->dismissed_at ? '↺ Herstel' : '✕ Negeer' }}</button>
                                        </form>
                                    </div>
                                    <x-finding-note :review="$review" :finding="$f" />
                                </li>
                            @empty
                                <li class="px-6 py-6 text-gray-500">
                                    @if ($review->gheops_screened_at)
                                        Geen GheOP³S-matches voor deze bewoner.
                                    @else
                                        Nog niet gescreend — klik op "Vernieuwen".
                                    @endif
                                </li>
                            @endforelse
                        </ul>
                    </div>

                    <div id="phil" class="bg-white dark:bg-gray-800 shadow rounded-lg scroll-mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <h3 class="font-semibold">Phil-interacties ({{ $philFindings->count() }})</h3>
                            <form method="POST" action="{{ route('reviews.refresh-phil', $review) }}">
                                @csrf
                                <button class="text-sm text-emerald-700 hover:underline" {{ $philStatus['has_jobs'] ? 'disabled' : '' }}>
                                    ↻ Ophalen via phil.apb.be
                                </button>
                            </form>
                        </div>

                        {{-- Phil-status balk: pending / running / last-fetched / failed --}}
                        <div class="px-6 py-2 text-xs border-b border-gray-100 dark:border-gray-800 flex items-center gap-3 flex-wrap">
                            @if ($philStatus['running'] > 0)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 bg-amber-100 text-amber-800 rounded">
                                    <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
                                    Bezig met ophalen…
                                </span>
                            @elseif ($philStatus['pending'] > 0)
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 bg-blue-100 text-blue-800 rounded">
                                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                                    {{ $philStatus['pending'] }} taak gepland in queue
                                </span>
                            @endif

                            @if ($philStatus['last_fetched_at'])
                                <span class="text-gray-500">
                                    Laatst opgehaald: <strong>{{ $philStatus['last_fetched_at']->diffForHumans() }}</strong>
                                    ({{ $philStatus['last_fetched_at']->translatedFormat('d-m-Y H:i') }}),
                                    {{ $philStatus['last_findings_count'] }} interactie(s)
                                </span>
                            @else
                                <span class="text-gray-500">Nog nooit opgehaald.</span>
                            @endif

                            @if ($philStatus['last_failed_at'])
                                <details class="ms-auto">
                                    <summary class="text-red-700 cursor-pointer">
                                        ✕ Vorige run faalde ({{ $philStatus['last_failed_at']->diffForHumans() }})
                                    </summary>
                                    <div class="mt-1 text-red-700 break-all">{{ $philStatus['last_failure'] }}</div>
                                </details>
                            @endif

                            @if ($philStatus['has_jobs'])
                                <span class="ms-auto text-gray-400">Pagina ververst niet automatisch — herlaad om voortgang te zien.</span>
                            @endif
                        </div>

                        @if ($philSkipped->isNotEmpty())
                            {{-- Phil kent geen eigen bereidingen; zonder deze melding lijkt
                                 "geen interactie" alsof het product wél nagekeken is. --}}
                            <div class="px-6 py-2 text-xs bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200 border-b border-amber-100 dark:border-amber-900">
                                <strong>Niet meegenomen in de Phil-controle</strong> (eigen bereiding, niet in Phil's catalogus) — zelf na te kijken:
                                {{ $philSkipped->pluck('name')->implode(', ') }}
                            </div>
                        @endif
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($philFindings as $f)
                                <li id="finding-{{ $f->id }}" class="px-6 py-3 scroll-mt-4 {{ $f->dismissed_at ? 'opacity-50' : '' }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-sm">
                                            <div class="font-medium {{ $f->dismissed_at ? 'line-through' : '' }}">
                                                @if ($f->severity)
                                                    <span class="inline-block px-1.5 py-0.5 mr-1 text-[10px] uppercase rounded
                                                        @switch($f->severity)
                                                            @case('ernstig') bg-red-100 text-red-800 @break
                                                            @case('matig') bg-amber-100 text-amber-800 @break
                                                            @case('voeding') bg-blue-100 text-blue-800 @break
                                                            @default bg-gray-100 text-gray-700
                                                        @endswitch">
                                                        {{ $f->severity }}
                                                    </span>
                                                @endif
                                                {{ $f->title }}
                                            </div>
                                            @if ($f->body_md)<div class="text-gray-600 dark:text-gray-300 text-sm mt-1">{{ $f->body_md }}</div>@endif
                                        </div>
                                        <form method="POST" action="{{ route('reviews.findings.update', [$review, $f]) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="dismiss" value="{{ $f->dismissed_at ? '0' : '1' }}">
                                            <button class="text-xs text-gray-500 hover:text-red-600">{{ $f->dismissed_at ? '↺ Herstel' : '✕ Negeer' }}</button>
                                        </form>
                                    </div>
                                    <x-finding-note :review="$review" :finding="$f" />
                                </li>
                            @empty
                                <li class="px-6 py-6 text-gray-500 text-sm">Nog geen Phil-data opgehaald.</li>
                            @endforelse
                        </ul>
                    </div>

                    <div id="observaties" class="bg-white dark:bg-gray-800 shadow rounded-lg scroll-mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="font-semibold">Manuele observaties ({{ $manualFindings->count() }})</h3>
                        </div>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($manualFindings as $f)
                                <li id="finding-{{ $f->id }}" class="px-6 py-3 scroll-mt-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-sm">
                                            <div class="font-medium">{{ $f->title }}</div>
                                            @if ($f->body_md)<div class="text-gray-600 dark:text-gray-300 mt-1 whitespace-pre-line">{{ $f->body_md }}</div>@endif
                                        </div>
                                        <form method="POST" action="{{ route('reviews.findings.destroy', [$review, $f]) }}"
                                              onsubmit="return confirm('Deze observatie verwijderen?')">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-gray-500 hover:text-red-600">✕</button>
                                        </form>
                                    </div>
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-emerald-700">✎ Bewerken</summary>
                                        <form method="POST" action="{{ route('reviews.findings.update', [$review, $f]) }}" class="mt-2 space-y-2">
                                            @csrf @method('PATCH')
                                            <input type="text" name="title" required value="{{ $f->title }}" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                                            <textarea name="body_md" rows="3" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ $f->body_md }}</textarea>
                                            <button class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">Opslaan</button>
                                        </form>
                                    </details>
                                </li>
                            @empty
                                <li class="px-6 py-6 text-gray-500 text-sm">Nog geen manuele observaties.</li>
                            @endforelse
                        </ul>
                        <form method="POST" action="{{ route('reviews.findings.store', $review) }}" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                            @csrf
                            <input type="text" name="title" required placeholder="Bv. 'Waarom 2× per dag Atorvastatine 20mg?'" value="{{ old('title') }}" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <textarea name="body_md" placeholder="Toelichting (markdown)" rows="2" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ old('body_md') }}</textarea>
                            <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">+ Observatie toevoegen</button>
                        </form>
                    </div>
                </div>

                {{-- 2. Aandachtspunten + finalize --}}
                <div class="space-y-6">
                    <div id="aandachtspunten" class="bg-white dark:bg-gray-800 shadow rounded-lg scroll-mt-4">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="font-semibold">Algemene aandachtspunten ({{ $review->attentions->count() }})</h3>
                            <p class="text-xs text-gray-500">Verschijnen in WZC-export vóór de bewonerlijst.</p>
                        </div>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($review->attentions as $a)
                                <li id="attention-{{ $a->id }}" class="px-6 py-3 scroll-mt-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-sm">
                                            <div class="font-medium text-emerald-700">{{ $a->label }}</div>
                                            @if ($a->body_md)<div class="text-gray-600 dark:text-gray-300 mt-1 whitespace-pre-line">{{ $a->body_md }}</div>@endif
                                        </div>
                                        <form method="POST" action="{{ route('reviews.attentions.destroy', [$review, $a]) }}"
                                              onsubmit="return confirm('Dit aandachtspunt verwijderen?')">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-gray-500 hover:text-red-600">✕</button>
                                        </form>
                                    </div>
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-500 cursor-pointer hover:text-emerald-700">✎ Bewerken</summary>
                                        <form method="POST" action="{{ route('reviews.attentions.update', [$review, $a]) }}" class="mt-2 space-y-2">
                                            @csrf @method('PATCH')
                                            <input type="text" name="label" required value="{{ $a->label }}" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                                            <textarea name="body_md" rows="3" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ $a->body_md }}</textarea>
                                            <button class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">Opslaan</button>
                                        </form>
                                    </details>
                                </li>
                            @empty
                                <li class="px-6 py-6 text-gray-500 text-sm">Geen aandachtspunten.</li>
                            @endforelse
                        </ul>
                        <form method="POST" action="{{ route('reviews.attentions.store', $review) }}" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                            @csrf
                            <input type="text" name="label" required placeholder="Bv. 'L-thyroxine en koffie'" value="{{ old('label') }}" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <textarea name="body_md" rows="2" placeholder="Bv. '30 min tot 1 uur interval respecteren.'" class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ old('body_md') }}</textarea>
                            <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">+ Toevoegen</button>
                        </form>
                    </div>

                    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                        <h3 class="font-semibold mb-2">Status &amp; afronding</h3>
                        @if ($review->status === 'finalized')
                            <p class="text-sm text-emerald-700">Gefinaliseerd op {{ $review->finalized_at?->format('d-m-Y H:i') }}.</p>
                        @else
                            <form method="POST" action="{{ route('reviews.finalize', $review) }}"
                                  onsubmit="return confirm('Deze review finaliseren?')">
                                @csrf
                                <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">✓ Review finaliseren</button>
                            </form>

                            {{-- Uitweg voor een per ongeluk gestarte review. Alleen voor
                                 een draft: een gefinaliseerde review blijft staan. --}}
                            <form method="POST" action="{{ route('reviews.destroy', $review) }}" class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700"
                                  onsubmit="return confirm('Deze review definitief verwijderen? Bevindingen, uitleg en aandachtspunten van deze review gaan mee. Eerdere reviews blijven behouden.')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-gray-500 hover:text-red-600">✕ Review verwijderen</button>
                                <p class="text-xs text-gray-400 mt-1">Voor een review die per ongeluk gestart is.</p>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
