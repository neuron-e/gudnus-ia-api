@php
    use Carbon\Carbon;
@endphp
    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Informe Final - {{ $project->name }}</title>
    <style>
        @page {
            margin: 2.5cm 2cm 2.5cm 2cm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            color: #000;
        }
        header {
            position: fixed;
            top: -2.2cm;
            left: 0;
            right: 0;
            height: 2cm;
            font-size: 10pt;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header .left {
            font-weight: bold;
        }
        header .right img {
            height: 40px;
        }
        footer {
            position: fixed;
            bottom: -1.5cm;
            left: 0;
            right: 0;
            height: 1cm;
            text-align: right;
            font-size: 9pt;
        }
        .page-break {
            page-break-after: always;
        }
        .cover {
            height: 100vh;
            background: #f26a21 url('{{ public_path('images/cover-g.png') }}') no-repeat center center;
            background-size: cover;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 80px 60px;
        }
        .cover h1 {
            font-size: 36pt;
            margin: 0;
        }
        .cover h2 {
            font-size: 22pt;
            margin-top: 20px;
        }
        .cover .footer {
            font-size: 12pt;
        }
        .section-title {
            font-size: 16pt;
            font-weight: bold;
            margin: 30px 0 15px;
        }
        .subsection-title {
            font-size: 13pt;
            font-weight: bold;
            margin: 20px 0 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 6px;
        }
        th {
            background-color: #f2f2f2;
        }
        .image-block {
            margin-bottom: 25px;
        }
        .image-block img {
            width: 100%;
            display: block;
            margin-bottom: 5px;
        }
        .image-title {
            font-weight: bold;
            margin-top: 10px;
        }
        .footer-contacto {
            font-size: 10pt;
            line-height: 1.6;
            margin-top: 40px;
        }
    </style>
</head>
<body>

<!-- PORTADA -->
<div class="cover">
    <div>
        <h1>INFORME FINAL</h1>
        <h2>MUESTREO DE ELECTROLUMINISCENCIA</h2>
        <h2 style="margin-top: 60px;">{{ strtoupper($project->name) }}</h2>
    </div>
    <div class="footer">
        {{ Carbon::now()->format('d/m/Y') }}
    </div>
</div>
<div class="page-break"></div>

<!-- HEADER Y FOOTER -->
<header>
    <div class="left">INFORME DE ELECTROLUMINISCENCIA - {{ strtoupper($project->name) }}</div>
    <div class="right"><img src="{{ public_path('images/logo-gudnus.png') }}" alt="Logo Gudnus"></div>
</header>

<footer>
    Informe generado el {{ Carbon::now()->format('d/m/Y \a \l\a\s H:i') }} | Página {PAGE_NUM}
</footer>

<!-- SECCIONES -->
@foreach ($sections as $index => $section)
    <div class="section-title">{{ $section['title'] }}</div>

    @if (!empty($section['content']))
        {!! $section['content'] !!}
    @endif

    @if (!empty($section['table']))
        <table>
            <thead>
            <tr>
                @foreach ($section['table']['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($section['table']['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{!! $cell !!}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($section['images']))
        @foreach ($section['images'] as $block)
            <div class="image-block">
                <div class="image-title">Módulo: {{ basename($block['field']) }}</div>
                <img src="{{ storage_path('app/public/' . $block['field']) }}" alt="Imagen módulo">
                @if (!empty($block['notes']))
                    <p>{{ $block['notes'] }}</p>
                @endif
            </div>
        @endforeach
    @endif

    @if (!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach

<!-- PÁGINA FINAL -->
<div class="page-break"></div>
<div class="section-title">Gudnus</div>
<p class="footer-contacto">
    Efficient Engineering Solutions<br>
    <strong>Contacto</strong><br>
    🏢 C/ Arte, 21, 28033 Madrid<br>
    📞 917 67 19 82<br>
    📧 info@gudnus.com<br>
    Especialistas en inspección y análisis de instalaciones fotovoltaicas
</p>

</body>
</html>
