<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Zorginstellingen</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($careCenters as $cc)
                        <li class="px-6 py-4 flex items-center justify-between">
                            <div>
                                <a href="{{ route('care-centers.show', $cc) }}" class="text-lg font-medium text-emerald-700 hover:underline">
                                    {{ $cc->name }}
                                </a>
                                @if ($cc->address)
                                    <div class="text-sm text-gray-500">{{ $cc->address }}</div>
                                @endif
                            </div>
                            <span class="text-sm text-gray-500">{{ $cc->departments_count }} afdelingen</span>
                        </li>
                    @empty
                        <li class="px-6 py-8 text-gray-500">Nog geen zorginstelling. <a href="{{ route('uploads.create') }}" class="text-emerald-700 hover:underline">Upload een medicatieschema</a>.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
