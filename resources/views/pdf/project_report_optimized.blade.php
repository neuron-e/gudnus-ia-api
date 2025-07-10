<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Informe de Electroluminiscencia - {{ $project->name }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            margin: 15px;
            color: #333;
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
            color: #2c3e50;
        }

        .header h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            color: #34495e;
        }

        .project-info {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid #3498db;
        }

        .project-info h3 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 14px;
        }

        .project-info p {
            margin: 5px 0;
            font-size: 11px;
        }

        .summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
        }

        .summary-item {
            text-align: center;
            flex: 1;
            border: 1px solid #bdc3c7;
            padding: 12px;
            margin: 0 3px;
            background-color: #ecf0f1;
            border-radius: 3px;
        }

        .summary-item strong {
            display: block;
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .image-section h3 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 5px;
            margin-bottom: 20px;
        }

        .image-item {
            border: 1px solid #bdc3c7;
            padding: 12px;
            margin-bottom: 20px;
            page-break-inside: avoid;
            background-color: #ffffff;
            border-radius: 3px;
        }

        .image-header {
            background-color: #3498db;
            color: white;
            padding: 8px 12px;
            margin: -12px -12px 12px -12px;
            font-weight: bold;
            font-size: 12px;
            border-radius: 3px 3px 0 0;
        }

        .image-content {
            text-align: center;
            margin-bottom: 10px;
        }

        .image-content img {
            max-width: 100%;
            max-height: 200px;
            border: 1px solid #bdc3c7;
            border-radius: 3px;
        }

        .image-path {
            font-size: 9px;
            color: #7f8c8d;
            margin-top: 8px;
            word-wrap: break-word;
        }

        .image-analysis {
            margin-top: 10px;
            padding: 8px;
            background-color: #f8f9fa;
            border-left: 3px solid #e74c3c;
            font-size: 10px;
        }

        .analysis-good {
            border-left-color: #27ae60;
        }

        .analysis-warning {
            border-left-color: #f39c12;
        }

        .analysis-error {
            border-left-color: #e74c3c;
        }

        .error-not-available {
            color: #95a5a6;
            font-style: italic;
            padding: 15px;
            text-align: center;
            background-color: #f8f9fa;
            border-radius: 3px;
        }

        .footer {
            position: fixed;
            bottom: 15px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #7f8c8d;
            border-top: 1px solid #bdc3c7;
            padding-top: 5px;
        }

        .page-break {
            page-break-before: always;
        }

        .part-info {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            border-radius: 3px;
        }
    </style>
</head>
<body>
<!-- Header -->
<div class="header">
    <h1>Informe de Electroluminiscencia</h1>
    <h2>{{ $project->name }}</h2>
    @if(isset($partNumber) && isset($totalParts))
        <div class="part-info">
            Parte {{ $partNumber }} de {{ $totalParts }}
        </div>
    @endif
</div>

<!-- Información del proyecto -->
<div class="project-info">
    <h3>Información del Proyecto</h3>
    <p><strong>Nombre:</strong> {{ $project->name }}</p>
    @if($project->description)
        <p><strong>Descripción:</strong> {{ $project->description }}</p>
    @endif
    @if($project->installation_name)
        <p><strong>Instalación:</strong> {{ $project->installation_name }}</p>
    @endif
    @if($project->inspector_name)
        <p><strong>Inspector:</strong> {{ $project->inspector_name }}</p>
    @endif
    @if($project->panel_brand)
        <p><strong>Marca de paneles:</strong> {{ $project->panel_brand }}</p>
    @endif
    @if($project->panel_model)
        <p><strong>Modelo de paneles:</strong> {{ $project->panel_model }}</p>
    @endif
    <p><strong>Fecha de generación:</strong> {{ now()->format('d/m/Y H:i') }}</p>
</div>

<!-- Resumen -->
<div class="summary">
    <div class="summary-item">
        <strong>{{ $images->count() }}</strong>
        Total Imágenes
    </div>
    <div class="summary-item">
        <strong>{{ $images->filter(fn($img) => $img->processedImage)->count() }}</strong>
        Imágenes Procesadas
    </div>
    <div class="summary-item">
        <strong>{{ $images->filter(fn($img) => $img->processedImage && $img->processedImage->ai_response_json)->count() }}</strong>
        Con Análisis IA
    </div>
</div>

<!-- Imágenes -->
<div class="image-section">
    <h3>Análisis de Imágenes</h3>

    @foreach($images->chunk(4) as $chunkIndex => $imageChunk)
        @if($chunkIndex > 0)
            <div class="page-break"></div>
        @endif

        @foreach($imageChunk as $image)
            @if($image->processedImage)
                <div class="image-item">
                    <div class="image-header">
                        {{ $image->filename ?? basename($image->original_path) }}
                    </div>

                    <div class="image-content">
                        @php
                            $hasImage = false;
                            $imageHtml = '';

                            if($image->processedImage->corrected_path) {
                                try {
                                    $imagePath = $image->processedImage->corrected_path;
                                    if(\Storage::disk('wasabi')->exists($imagePath)) {
                                        $imageData = \Storage::disk('wasabi')->get($imagePath);
                                        $base64 = base64_encode($imageData);
                                        $mimeType = 'image/jpeg';
                                        $imageHtml = '<img src="data:' . $mimeType . ';base64,' . $base64 . '" alt="Imagen procesada">';
                                        $hasImage = true;
                                    } else {
                                        $imageHtml = '<div class="error-not-available">Imagen no disponible en storage</div>';
                                    }
                                } catch (\Exception $e) {
                                    $imageHtml = '<div class="error-not-available">Error cargando imagen: ' . $e->getMessage() . '</div>';
                                }
                            } else {
                                $imageHtml = '<div class="error-not-available">Ruta de imagen no disponible</div>';
                            }
                        @endphp

                        {!! $imageHtml !!}

                        <div class="image-path">
                            {{ $image->full_path ?? $image->original_path }}
                        </div>
                    </div>

                    <!-- Análisis IA -->
                    @if($image->processedImage->ai_response_json)
                        @php
                            $analysis = json_decode($image->processedImage->ai_response_json, true);
                            $predictions = $analysis['predictions'] ?? [];
                            $errorCount = count($predictions);
                        @endphp

                        <div class="image-analysis {{ $errorCount == 0 ? 'analysis-good' : ($errorCount > 5 ? 'analysis-error' : 'analysis-warning') }}">
                            <strong>Análisis IA:</strong>
                            @if($errorCount > 0)
                                {{ $errorCount }} problema(s) detectado(s)
                                @if($errorCount <= 3)
                                    <br>
                                    @foreach(array_slice($predictions, 0, 3) as $prediction)
                                        <span style="font-size: 9px;">
                                            • {{ $prediction['tagName'] ?? 'Defecto' }}
                                            @if(isset($prediction['probability']))
                                                ({{ round($prediction['probability'] * 100, 1) }}%)
                                            @endif
                                        </span><br>
                                    @endforeach
                                @endif
                            @else
                                Sin problemas detectados
                            @endif
                        </div>
                    @else
                        <div class="image-analysis analysis-warning">
                            <strong>Análisis IA:</strong> Pendiente de análisis
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    @endforeach
</div>

<!-- Footer -->
<div class="footer">
    Informe generado automáticamente el {{ now()->format('d/m/Y H:i:s') }}
    @if(isset($partNumber) && isset($totalParts))
        | Parte {{ $partNumber }} de {{ $totalParts }}
    @endif
    | Proyecto: {{ $project->name }}
</div>
</body>
</html>
