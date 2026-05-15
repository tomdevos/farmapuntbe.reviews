<div class="cover">
    <div class="logo">farmapunt<span class="plus">.+</span></div>
    <div style="margin: 18mm 0 0;">
        <div class="eyebrow">Medicatiereview</div>
        <h1>{{ $metadata['title'] }}</h1>
        <div class="subtitle">{{ $metadata['subtitle'] }}</div>
        <hr class="accent-line" style="margin-left:auto; margin-right:auto; width:60%;">

        <table class="meta" style="margin-left:auto; margin-right:auto; max-width:140mm;">
            <tr><td class="label">Bestemmeling</td><td>{{ $metadata['bestemmeling'] }}</td></tr>
            <tr><td class="label">Woonzorgcentrum</td><td>{{ $metadata['wzc'] }}</td></tr>
            <tr><td class="label">Apotheek</td><td>{{ $metadata['apotheek'] }}</td></tr>
            <tr><td class="label">Opgesteld door</td><td>{{ $metadata['opgesteld_door'] }}</td></tr>
            <tr><td class="label">Datum</td><td>{{ $metadata['datum'] }}</td></tr>
            <tr><td class="label">Onderwerp</td><td>{{ $metadata['onderwerp'] }}</td></tr>
        </table>

        <p style="margin-top:30mm; font-size:8pt; color:#888;">
            <span class="farmapunt-green" style="font-weight:600;">VERTROUWELIJK</span> · Document met persoonsgebonden medische gegevens — uitsluitend bestemd voor de behandelend arts.
        </p>
    </div>
</div>
