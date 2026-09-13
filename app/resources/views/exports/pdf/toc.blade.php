<h1>Inhoud</h1>
<hr class="accent-line">
<ul class="toc-list">
    <li>Voorwoord</li>
    <li>Algemene aandachtspunten</li>
    @foreach ($groups as $group)
        <li>{{ $group['label'] }}</li>
    @endforeach
    <li>Contact &amp; samenwerking</li>
</ul>
