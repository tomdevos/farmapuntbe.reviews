<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Export medicatiereview</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if ($errors->any())
                <div class="p-3 bg-red-50 text-red-700 border border-red-200 rounded text-sm">
                    <strong class="block mb-1">Fout bij export:</strong>
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8">
                <form method="POST" action="{{ route('exports.store') }}" class="space-y-6"
                      x-data="{ scope: '{{ old('scope', 'care_center') }}' }">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium mb-2">Scope</label>
                        <div class="grid grid-cols-3 gap-2 text-sm">
                            @foreach (['care_center' => 'Zorginstelling', 'department' => 'Afdeling', 'resident' => 'Bewoner'] as $key => $label)
                                <label class="border rounded p-3 cursor-pointer flex items-center gap-2"
                                       :class="scope === '{{ $key }}' ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-gray-300'">
                                    <input type="radio" name="scope" value="{{ $key }}"
                                           x-model="scope"
                                           class="text-emerald-600">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div x-show="scope === 'care_center'">
                        <label class="block text-sm font-medium">Zorginstelling</label>
                        <select name="cc" :disabled="scope !== 'care_center'" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($careCenters as $cc)
                                <option value="{{ $cc->id }}" @selected(old('cc') == $cc->id)>{{ $cc->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="scope === 'department'">
                        <label class="block text-sm font-medium">Afdeling</label>
                        <select name="dept" :disabled="scope !== 'department'" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($careCenters as $cc)
                                @foreach ($cc->departments as $d)
                                    <option value="{{ $d->id }}" @selected(old('dept') == $d->id)>{{ $cc->name }} — {{ $d->name }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </div>

                    <div x-show="scope === 'resident'">
                        <label class="block text-sm font-medium">Bewoner</label>
                        <select name="res" :disabled="scope !== 'resident'" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            @foreach ($residents as $r)
                                <option value="{{ $r->id }}" @selected(old('res') == $r->id)>
                                    {{ $r->display_name }} — {{ $r->department->careCenter->name }} / {{ $r->department->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-2">Format</label>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input type="radio" name="format" value="pdf" @checked(old('format', 'pdf') === 'pdf') class="text-emerald-600"> PDF
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" name="format" value="docx" @checked(old('format') === 'docx') class="text-emerald-600"> DOCX
                            </label>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">PDF-render duurt typisch 5–30s afhankelijk van het aantal bewoners.</p>

                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded">
                        Genereer export
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
                <div class="px-6 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-sm">Recente exports</h3>
                </div>
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    @foreach (\App\Models\ReviewExport::latest('generated_at')->limit(8)->get() as $e)
                        <li class="px-6 py-2 flex items-center justify-between">
                            <span>{{ basename($e->path) }}</span>
                            <a href="{{ route('exports.download', $e) }}" class="text-emerald-700 hover:underline">↓ Download</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
