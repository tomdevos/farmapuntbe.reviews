<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $careCenter->name }}</h2>
            <a href="{{ route('care-centers.index') }}" class="text-sm text-emerald-700 hover:underline">← Zorginstellingen</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="p-3 bg-emerald-50 text-emerald-800 rounded">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-3 bg-red-50 text-red-700 rounded">
                    @foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 flex items-center justify-between">
                <div class="text-sm">
                    <strong>Phil-interacties bulk-ophalen</strong>
                    <p class="text-gray-500 text-xs">Plant 1 job per bewoner in de queue. Vergeet niet <code>php artisan queue:work</code> te draaien.</p>
                </div>
                <form method="POST" action="{{ route('phil.bulk.care-center', $careCenter) }}" onsubmit="return confirm('Phil-fetch voor alle bewoners van {{ $careCenter->name }} starten?');">
                    @csrf
                    <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">↻ Volledig WZC</button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($careCenter->departments as $dept)
                        <li class="px-6 py-4 flex items-center justify-between gap-3">
                            <a href="{{ route('departments.show', $dept) }}" class="text-emerald-700 hover:underline">
                                Afdeling {{ $dept->name }}
                            </a>
                            <div class="flex items-center gap-3">
                                <span class="text-sm text-gray-500">{{ $dept->residents_count }} bewoners</span>
                                <form method="POST" action="{{ route('phil.bulk.department', $dept) }}" onsubmit="return confirm('Phil-fetch voor alle bewoners van afdeling {{ $dept->name }}?');">
                                    @csrf
                                    <button class="text-xs px-2 py-1 bg-white dark:bg-gray-900 text-emerald-700 border border-emerald-600 hover:bg-emerald-50 rounded">↻ Phil</button>
                                </form>
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-gray-500">Nog geen afdelingen geïmporteerd.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
