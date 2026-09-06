@props(['findings'])

{{-- Hoeveel automatisch overgenomen zinnen nog nagekeken moeten worden. --}}
@php($open = $findings->whereNull('dismissed_at')->whereNotNull('note_suggested_at')->count())
@if ($open > 0)
    <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-normal align-middle">
        {{ $open }} voorstel{{ $open === 1 ? '' : 'len' }} na te kijken
    </span>
@endif
