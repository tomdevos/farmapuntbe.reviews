<h1>Algemene aandachtspunten</h1>
<hr class="accent-line">
<p>Onderstaande aandachtspunten zijn van toepassing op meerdere bewoners. Ze vormen een algemene leidraad bij de farmacologische opvolging en dienen mee in rekening genomen te worden bij elke individuele evaluatie.</p>

<ul class="attentions">
    @foreach ($attentions->unique('label') as $a)
        <li>
            <span class="label">{{ $a->label }}</span>@if ($a->body_md): {{ $a->body_md }}@endif
        </li>
    @endforeach
</ul>
