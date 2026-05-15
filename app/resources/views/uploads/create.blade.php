<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Upload medicatieschema</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8">

                @if ($errors->any())
                    <div class="mb-4 p-4 bg-red-50 text-red-700 border border-red-200 rounded">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" class="space-y-6">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Zorginstelling</label>
                        <select name="care_center_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <option value="">— nieuwe zorginstelling —</option>
                            @foreach ($careCenters as $cc)
                                <option value="{{ $cc->id }}">{{ $cc->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="care_center_name" placeholder="Naam (enkel als 'nieuw')" class="mt-2 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900" value="{{ old('care_center_name') }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Medicatieschema xlsx</label>
                        <input type="file" name="file" required accept=".xlsx,.xls" class="mt-1 block w-full text-sm">
                        <p class="text-xs text-gray-500 mt-1">Verwacht sheets: <em>Chronische / Tijdelijke / Indien nodig / Verboden medicatie</em>.</p>
                    </div>

                    <div class="flex items-center justify-between">
                        <label class="flex items-center text-sm">
                            <input type="checkbox" name="process_now" value="1" checked class="rounded border-gray-300 text-emerald-600">
                            <span class="ms-2">Onmiddellijk verwerken (anders queue)</span>
                        </label>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md">
                            Upload &amp; verwerk
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
