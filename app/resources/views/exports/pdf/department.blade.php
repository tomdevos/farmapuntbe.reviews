<h1 class="dept-title">Afdeling {{ $department->name }}</h1>
<p class="dept-meta">{{ $department->residents->count() }} bewoners</p>

@foreach ($department->residents as $idx => $resident)
    @php($rev = $reviewsByResident->get($resident->id))
    <div class="resident">
        <div class="header">
            <div><span class="num">{{ $idx + 1 }}.</span> <span class="name">{{ $resident->display_name }}</span></div>
            <div class="doctor">Behandelend arts: {{ $resident->doctor_name ?: '—' }}</div>
        </div>
        <div class="body">
            @if ($rev && $rev->findings->where('dismissed_at', null)->isNotEmpty())
                @foreach ($rev->findings->whereNull('dismissed_at') as $f)
                    @php($title = (string) $f->title)
                    @php($hasColon = str_contains($title, ':'))
                    @php($lead = $hasColon ? \Illuminate\Support\Str::beforeLast($title, ':') : $title)
                    @php($tail = $hasColon ? trim(\Illuminate\Support\Str::after($title, ':')) : '')
                    <p>
                        <strong class="med">{{ $lead }}{{ $tail !== '' ? ':' : '' }}</strong>
                        {{ $tail }}
                        @if ($f->body_md)<br><span style="color:#555;">{{ $f->body_md }}</span>@endif
                    </p>
                @endforeach
            @else
                <p style="color: #999; font-style: italic;">Alles ok.</p>
            @endif
        </div>
    </div>
@endforeach
