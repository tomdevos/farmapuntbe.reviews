<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Upload — {{ $upload->filename }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 space-y-2">
                <div><span class="font-medium">Zorginstelling:</span> {{ $upload->careCenter?->name }}</div>
                <div><span class="font-medium">Geüpload:</span> {{ $upload->uploaded_at?->format('d-m-Y H:i') }} door {{ $upload->user?->name ?? '—' }}</div>
                <div><span class="font-medium">Status:</span>
                    <span class="px-2 py-0.5 rounded text-xs
                        @if ($upload->status === 'processed') bg-emerald-100 text-emerald-800
                        @elseif ($upload->status === 'failed') bg-red-100 text-red-800
                        @else bg-yellow-100 text-yellow-800 @endif">
                        {{ $upload->status }}
                    </span>
                </div>
                @if ($upload->error)
                    <div class="text-red-700 mt-2"><strong>Fout:</strong> {{ $upload->error }}</div>
                @endif
            </div>

            @if ($upload->summary)
                @php($s = $upload->summary)
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <h3 class="font-semibold mb-3">Resultaat</h3>
                    <ul class="text-sm space-y-1">
                        <li>Bewoners: <strong>{{ $s['residents'] ?? 0 }}</strong></li>
                        @foreach (($s['schedules'] ?? []) as $type => $n)
                            <li>Schedules ({{ $type }}): <strong>{{ $n }}</strong></li>
                        @endforeach
                        <li>Nieuwe CNK-stubs: <strong>{{ count($s['unknown_cnks'] ?? []) }}</strong></li>
                        @if (! empty($s['dropped_rows']))
                            <li class="text-amber-700">Regels zonder bruikbare CNK (niet geïmporteerd): <strong>{{ count($s['dropped_rows']) }}</strong></li>
                        @endif
                    </ul>
                    @if (! empty($s['dropped_rows']))
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-amber-700">Toon niet-geïmporteerde regels</summary>
                            <ul class="mt-2 list-disc list-inside text-gray-600">
                                @foreach ($s['dropped_rows'] as $d)
                                    <li>{{ $d['resident'] }} — {{ $d['name'] }} (CNK "{{ $d['cnk'] }}")</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                    @if (! empty($s['unknown_cnks']))
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-emerald-700">Toon onbekende CNK's</summary>
                            <ul class="mt-2 list-disc list-inside text-gray-600">
                                @foreach ($s['unknown_cnks'] as $u)
                                    <li>{{ $u['cnk'] }} — {{ $u['name'] }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endif

            <a href="{{ route('care-centers.show', $upload->careCenter) }}" class="inline-block text-emerald-700 hover:underline">
                → Naar {{ $upload->careCenter?->name }}
            </a>
        </div>
    </div>
</x-app-layout>
