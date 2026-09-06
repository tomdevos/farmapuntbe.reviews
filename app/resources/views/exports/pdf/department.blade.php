<h1 class="dept-title">Afdeling {{ $department->name }}</h1>
<p class="dept-meta">{{ $department->residents->count() }} bewoners</p>

@php($presenter = app(\App\Services\Export\FindingPresenter::class))

@foreach ($department->residents as $idx => $resident)
    @php($rev = $reviewsByResident->get($resident->id))
    @php($lines = $rev ? $presenter->present($rev->findings) : [])
    <div class="resident">
        <div class="header">
            <div><span class="num">{{ $idx + 1 }}.</span> <span class="name">{{ $resident->display_name }}</span></div>
            <div class="doctor">Behandelend arts: {{ $resident->doctor_name ?: '—' }}</div>
        </div>
        <div class="body">
            @forelse ($lines as $line)
                <p>
                    <strong class="med">{{ $line['label'] }}:</strong>
                    @foreach ($line['lines'] as $text)
                        <br><span style="color:#555;">{{ $text }}</span>
                    @endforeach
                </p>
            @empty
                <p style="color: #999; font-style: italic;">Alles ok.</p>
            @endforelse
        </div>
    </div>
@endforeach
