<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    {{-- Plain CSS 2.1 only, so the same markup renders in browsers AND in the PDF engine. --}}
    <style>
        @page { margin: 18mm 17mm; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.5; color: #111827; margin: 0; }
        .wrap { max-width: 46rem; margin: 2rem auto; padding: 0 1.25rem; }
        h1 { font-size: 19pt; margin: 0 0 2pt; letter-spacing: .2pt; }
        .sub { color: #374151; margin: 0 0 1pt; }
        .contact { color: #4b5563; font-size: 9pt; margin: 0 0 10pt; }
        h2 { font-size: 10.5pt; text-transform: uppercase; letter-spacing: 1pt; color: #92400e; border-bottom: 1pt solid #d1d5db; padding-bottom: 2pt; margin: 14pt 0 6pt; }
        h3 { font-size: 10pt; margin: 8pt 0 2pt; }
        p { margin: 0 0 8pt; }
        ul { margin: 2pt 0 4pt 14pt; padding: 0; }
        li { margin: 0 0 2pt; }
        .letter p { margin: 0 0 9pt; }
        .bar { font: 13px sans-serif; color: #555; margin-bottom: 1rem; }
        @media print { .wrap { margin: 0; max-width: none; padding: 0; } .bar { display: none; } }
    </style>
</head>
<body>
<div class="wrap">
@if ($print)
    <p class="bar">Use your browser's Print dialog and choose “Save as PDF”, or use the PDF download. <button onclick="window.print()">Print</button></p>
@endif

@if ($doc['kind'] === 'resume')
    @if (! empty($doc['header']))
        <h1>{{ $doc['header'][0] }}</h1>
        @foreach (array_slice($doc['header'], 1, 1) as $l)<p class="sub">{{ $l }}</p>@endforeach
        @foreach (array_slice($doc['header'], 2) as $l)<p class="contact">{{ $l }}</p>@endforeach
    @endif
    @foreach ($doc['sections'] as $section)
        <h2>{{ $section['title'] }}</h2>
        @foreach ($section['blocks'] as $block)
            @if ($block['type'] === 'heading')<h3>{{ $block['lines'][0] }}</h3>
            @elseif ($block['type'] === 'bullets')<ul>@foreach ($block['lines'] as $l)<li>{{ $l }}</li>@endforeach</ul>
            @else<p>{{ implode(' ', $block['lines']) }}</p>@endif
        @endforeach
    @endforeach
@else
    <div class="letter">
        @foreach ($doc['sections'][0]['blocks'] as $block)
            <p>@foreach ($block['lines'] as $i => $l){{ $l }}@if (! $loop->last)<br>@endif @endforeach</p>
        @endforeach
    </div>
@endif
</div>
@if ($print)<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>@endif
</body>
</html>
