<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ $department->careCenter->name }} — Afdeling {{ $department->name }}
            </h2>
            <a href="{{ route('care-centers.show', $department->careCenter) }}" class="text-sm text-emerald-700 hover:underline">← {{ $department->careCenter->name }}</a>
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
                    <strong>Phil-interacties voor deze afdeling</strong>
                    <p class="text-gray-500 text-xs">Plant een job per bewoner met actieve medicatie. Queue worker moet draaien (<code>php artisan queue:work</code>).</p>
                </div>
                <form method="POST" action="{{ route('phil.bulk.department', $department) }}" onsubmit="return confirm('Phil-fetch voor alle bewoners van afdeling {{ $department->name }}?');">
                    @csrf
                    <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm">↻ Phil bulk-fetch</button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bewoner</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kamer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Behandelend arts</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($department->residents as $resident)
                            <tr>
                                <td class="px-6 py-3">
                                    <a href="{{ route('residents.show', $resident) }}" class="text-emerald-700 hover:underline">
                                        {{ $resident->display_name }}
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-500">{{ $resident->room }}</td>
                                <td class="px-6 py-3 text-sm text-gray-500">{{ $resident->doctor_name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-6 text-gray-500">Geen bewoners.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
