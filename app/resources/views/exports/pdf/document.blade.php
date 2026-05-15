<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>{{ $metadata['title'] }} — {{ $careCenter->name }}</title>
    <style>
        @page { size: A4; margin: 18mm 18mm 22mm 18mm; }
        body { font-family: 'Lato', 'Helvetica Neue', Arial, sans-serif; color: #222; font-size: 10.5pt; line-height: 1.45; }
        h1, h2, h3 { color: #2f3a2e; font-weight: 600; margin: 0 0 4mm; }
        .farmapunt-green { color: #3CA84B; }
        .accent-line { border: 0; border-top: 2px solid #3CA84B; margin: 4mm 0 8mm; }
        .eyebrow { text-transform: uppercase; letter-spacing: 4px; color: #3CA84B; font-size: 9pt; font-weight: 600; }
        .footer-confidential { color: #888; font-size: 8pt; }
        table.meta { border-collapse: collapse; margin-top: 12mm; width: 100%; }
        table.meta td { padding: 2mm 4mm; vertical-align: top; }
        table.meta td.label { color: #3CA84B; font-weight: 600; width: 36mm; }
        .page-break { page-break-after: always; }

        /* Cover */
        .cover { text-align: center; padding-top: 50mm; }
        .cover h1 { font-size: 28pt; margin: 4mm 0 2mm; color: #222; }
        .cover .subtitle { font-size: 14pt; color: #555; margin-bottom: 6mm; }
        .logo { font-family: 'Lato', sans-serif; font-weight: 700; font-size: 28pt; }
        .logo .plus { color: #3CA84B; }

        /* Department block */
        .dept-title { font-size: 18pt; padding-bottom: 1mm; border-bottom: 2px solid #3CA84B; margin-bottom: 4mm; }
        .dept-meta { font-style: italic; color: #888; margin-bottom: 4mm; font-size: 9pt; }
        .resident { border-left: 3px solid #d8e7d2; padding: 2mm 3mm; margin-bottom: 5mm; page-break-inside: avoid; }
        .resident .header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1mm; }
        .resident .header .name { font-weight: 600; }
        .resident .header .num { font-weight: 600; }
        .resident .header .doctor { color: #3CA84B; font-size: 9pt; }
        .resident .body { font-size: 10pt; }
        .resident .body p { margin: 1mm 0; }
        .resident .body strong.med { color: #3CA84B; }

        ul.attentions { list-style-type: disc; padding-left: 6mm; }
        ul.attentions li { margin-bottom: 1.5mm; }
        ul.attentions li .label { color: #3CA84B; font-weight: 600; }

        .toc-list { list-style: none; padding: 0; }
        .toc-list li { padding: 1mm 0; border-bottom: 1px dotted #e2e2e2; }
    </style>
</head>
<body>

@include('exports.pdf.cover', ['metadata' => $metadata])
<div class="page-break"></div>

@if ($includeVoorwoord)
    @include('exports.pdf.toc', ['departments' => $departments])
    <div class="page-break"></div>
    @include('exports.pdf.voorwoord')
    <div class="page-break"></div>
@endif

@if ($includeAttentions && $attentions->isNotEmpty())
    @include('exports.pdf.attentions', ['attentions' => $attentions])
    <div class="page-break"></div>
@endif

@foreach ($departments as $i => $dept)
    @include('exports.pdf.department', [
        'department' => $dept,
        'reviewsByResident' => $reviewsByResident,
    ])
    @if (! $loop->last)<div class="page-break"></div>@endif
@endforeach

</body>
</html>
