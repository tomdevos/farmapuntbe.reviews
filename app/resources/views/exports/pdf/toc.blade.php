<h1>Inhoud</h1>
<hr class="accent-line">
<ul class="toc-list">
    <li>Voorwoord</li>
    <li>Algemene aandachtspunten</li>
    @foreach ($departments as $dept)
        <li>Afdeling {{ $dept->name }}</li>
    @endforeach
    <li>Contact &amp; samenwerking</li>
</ul>
