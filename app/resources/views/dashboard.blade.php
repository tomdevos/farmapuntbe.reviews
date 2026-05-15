<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <form method="GET" action="{{ route('dashboard') }}" class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 flex gap-2">
                <input type="search" name="q" value="{{ $q }}" placeholder="Zoek bewoner op naam of slug…" class="flex-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                <button class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded">Zoek</button>
            </form>

            @if ($searchResults !== null)
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                    <div class="px-6 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold">Resultaten ({{ $searchResults->count() }})</h3>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($searchResults as $r)
                            <li class="px-6 py-2 flex items-center justify-between">
                                <a href="{{ route('residents.show', $r) }}" class="text-emerald-700 hover:underline">{{ $r->display_name }}</a>
                                <span class="text-sm text-gray-500">{{ $r->department->careCenter->name }} / {{ $r->department->name }}</span>
                            </li>
                        @empty
                            <li class="px-6 py-4 text-gray-500">Geen bewoners gevonden.</li>
                        @endforelse
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
                    <div class="text-sm text-gray-500">Zorginstellingen</div>
                    <div class="text-3xl font-semibold mt-1">{{ $careCenters->count() }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
                    <div class="text-sm text-gray-500">Actieve bewoners</div>
                    <div class="text-3xl font-semibold mt-1">{{ $residentCount }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
                    <div class="text-sm text-gray-500">Openstaande reviews</div>
                    <div class="text-3xl font-semibold mt-1">{{ $openReviews->count() }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Zorginstellingen</h3>
                    <a href="{{ route('uploads.create') }}" class="inline-flex items-center px-3 py-1 text-sm bg-emerald-600 text-white rounded-md hover:bg-emerald-700">
                        Upload medicatieschema
                    </a>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($careCenters as $cc)
                        <li class="px-6 py-3 flex items-center justify-between">
                            <a href="{{ route('care-centers.show', $cc) }}" class="text-emerald-700 hover:underline">
                                {{ $cc->name }}
                            </a>
                            <span class="text-sm text-gray-500">{{ $cc->departments->count() }} afdelingen</span>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-gray-500">Nog geen zorginstelling. Upload een medicatieschema om te starten.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Openstaande reviews</h3>
                    <a href="{{ route('exports.create') }}" class="text-sm text-emerald-700 hover:underline">→ Export genereren</a>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($openReviews as $review)
                        <li class="px-6 py-3 flex items-center justify-between">
                            <a href="{{ route('reviews.show', $review) }}" class="text-emerald-700 hover:underline">
                                {{ $review->resident->display_name }}
                            </a>
                            <span class="text-sm text-gray-500">
                                {{ $review->resident->department->careCenter->name }} · {{ $review->started_on->translatedFormat('j F Y') }}
                            </span>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-gray-500">Geen openstaande reviews.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Recente uploads</h3>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($recentUploads as $upload)
                        <li class="px-6 py-3 flex items-center justify-between">
                            <a href="{{ route('uploads.show', $upload) }}" class="text-emerald-700 hover:underline">
                                {{ $upload->filename }}
                            </a>
                            <span class="text-sm text-gray-500">
                                {{ $upload->careCenter?->name }} · {{ $upload->uploaded_at?->diffForHumans() }} · {{ $upload->status }}
                            </span>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-gray-500">Nog geen uploads.</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</x-app-layout>
