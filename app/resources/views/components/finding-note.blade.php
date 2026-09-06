@props(['review', 'finding'])

{{-- Uitleg van de apotheker bij een automatische bevinding. Blijft staan bij
     een "Vernieuwen" (upsert op fingerprint), en komt mee in de export. --}}
<details class="mt-2" {{ $finding->note_md ? 'open' : '' }}>
    <summary class="text-xs text-gray-500 cursor-pointer hover:text-emerald-700">
        {{ $finding->note_md ? '✎ Uitleg' : '+ Uitleg toevoegen' }}
        @if ($finding->note_suggested_at)
            <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px]">
                voorstel uit eerdere review — nakijken
            </span>
        @endif
    </summary>
    <form method="POST" action="{{ route('reviews.findings.update', [$review, $finding]) }}" class="mt-2 space-y-2">
        @csrf @method('PATCH')
        <textarea name="note_md" rows="3" placeholder="Bv. 'Bewust zo gehouden — dosis opgevolgd door dr. X.'"
                  class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ $finding->note_md }}</textarea>
        <button class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">
            {{ $finding->note_suggested_at ? 'Uitleg bevestigen' : 'Uitleg opslaan' }}
        </button>
    </form>
</details>
