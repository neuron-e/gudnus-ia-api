<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 6px; }
        .meta { font-size: 10px; color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 6px; vertical-align: middle; }
        th { background: #f5f5f5; font-weight: 600; }
        .small { font-size: 9px; word-break: break-all; }
        .thumb { width: 64px; height: 48px; object-fit: cover; }
    </style>
</head>
<body>
<h1>Informe compacto – {{ $project->name }}</h1>
<div class="meta">
    Generado: {{ $generated_at->format('Y-m-d H:i') }} |
    Total imágenes: {{ count($rows) }}
</div>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Thumb</th>
        <th>Ruta</th>
        <th>Integridad</th>
        <th>Luminosidad</th>
        <th>Uniformidad</th>
        <th>Errores</th>
        <th>URL pública</th>
    </tr>
    </thead>
    <tbody>
    @foreach($rows as $i => $r)
        <tr>
            <td>{{ $i+1 }}</td>
            <td>@if($r['thumb']) <img class="thumb" src="{{ $r['thumb'] }}" alt="thumb"> @endif</td>
            <td class="small">{{ $r['folder_path'] }}</td>
            <td>{{ $r['integrity'] }}</td>
            <td>{{ $r['luminosity'] }}</td>
            <td>{{ $r['uniformity'] }}</td>
            <td>{{ $r['errors_count'] }}</td>
            <td class="small">{{ $r['public_url'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<p class="meta" style="margin-top:10px">
    * Cada URL abre la imagen en modo lectura (sin edición), con opciones de exportación.
</p>
</body>
</html>
