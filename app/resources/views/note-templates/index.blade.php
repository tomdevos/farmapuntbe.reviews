<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Uitleg-bibliotheek</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="px-4 py-3 bg-emerald-50 text-emerald-800 rounded text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Elke zin die je bij een interactie, criterium of observatie bewaart, komt hier terecht en wordt
                        automatisch voorgesteld zodra hetzelfde terugkomt — bij een andere bewoner of een ander WZC.
                        Wijzigingen gelden vanaf de volgende review; wat al in een review staat, verandert niet mee.
                    </p>
                    <form method="GET" class="mt-3">
                        <input type="search" name="q" value="{{ $q }}" placeholder="Zoek op product of tekst…"
                               class="w-full sm:w-80 text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </form>
                </div>

                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($templates as $t)
                        <li class="px-6 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="font-medium text-emerald-700">{{ $t->label }}</span>
                                    <span class="ml-2 text-[10px] uppercase px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $t->source }}</span>
                                </div>
                                <div class="text-xs text-gray-500 whitespace-nowrap">
                                    {{ $t->times_used }}× gebruikt
                                    @if ($t->last_used_at) · {{ $t->last_used_at->format('d-m-Y') }} @endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('note-templates.update', $t) }}" class="mt-2 space-y-2">
                                @csrf @method('PATCH')
                                <textarea name="note_md" rows="2" required
                                          class="w-full text-sm rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900">{{ $t->note_md }}</textarea>
                                <button class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs">Opslaan</button>
                            </form>
                            <form method="POST" action="{{ route('note-templates.destroy', $t) }}" class="mt-2"
                                  onsubmit="return confirm('Deze uitleg uit de bibliotheek verwijderen?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-gray-500 hover:text-red-600">✕ Verwijderen</button>
                            </form>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-gray-500 text-sm">
                            @if ($q !== '')
                                Niets gevonden voor “{{ $q }}”.
                            @else
                                Nog niets bewaard. Zodra je bij een bevinding uitleg opslaat, verschijnt ze hier.
                            @endif
                        </li>
                    @endforelse
                </ul>
            </div>

            {{ $templates->links() }}
        </div>
    </div>
</x-app-layout>
