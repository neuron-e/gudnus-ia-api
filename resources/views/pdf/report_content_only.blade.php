<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm 20mm 15mm 20mm;
            size: A4;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .header {
            position: fixed;
            top: -12mm;
            left: 0;
            right: 0;
            height: 12mm;
            background-color: #ff6b35;
            color: white;
            padding: 3mm 5mm;
            font-weight: bold;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
        }

        .header-logo {
            font-size: 16px;
            font-weight: bold;
            color: white;
        }

        .header-title {
            flex-grow: 1;
            text-align: center;
            font-size: 10px;
            margin: 0;
        }

        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 2mm;
            height: 8mm;
        }

        .content {
            margin-top: 8mm;
            margin-bottom: 10mm;
        }

        /* Módulos optimizados para no chocar con headers */
        .module-section {
            page-break-inside: avoid;
            margin-bottom: 25px;
            border: 1px solid #eee;
            padding: 12px;
            background: white;
            position: relative;
            min-height: 400px; /* ✅ Altura mínima para control */
        }

        .module-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            background-color: #f0f0f0;
            padding: 8px 12px;
            border-left: 4px solid #ff6b35;
            position: relative;
            z-index: 2;
        }

        /* ✅ IMAGEN OPTIMIZADA - Sin conflictos con headers */
        .analyzed-image {
            width: 100%;
            max-width: 480px; /* ✅ Reducido ligeramente */
            height: auto;
            max-height: 320px; /* ✅ Altura máxima controlada */
            border: 1px solid #ddd;
            margin: 12px 0;
            object-fit: contain;
            display: block;
            position: relative;
            z-index: 1;
        }

        .image-container {
            text-align: center;
            margin: 15px 0;
            page-break-inside: avoid;
            position: relative;
        }

        .image-label {
            font-weight: bold;
            margin-bottom: 8px;
            color: #ff6b35;
            font-size: 11px;
            text-align: left;
        }

        /* ✅ LISTA DE ERRORES OPTIMIZADA */
        .error-list {
            margin-top: 12px;
            padding: 10px 12px;
            background-color: #f8f9fa;
            border-left: 3px solid #ff6b35;
            border-radius: 3px;
            page-break-inside: avoid;
            position: relative;
            z-index: 2;
        }

        .error-list ul {
            margin: 6px 0;
            padding-left: 16px;
        }

        .error-list li {
            margin: 4px 0;
            font-size: 10px;
            line-height: 1.3;
        }

        .status-ok {
            background-color: #e8f5e8;
            border: 1px solid #4caf50;
            padding: 10px 12px;
            margin: 12px 0;
            color: #2e7d32;
            border-radius: 3px;
            page-break-inside: avoid;
            position: relative;
            z-index: 2;
        }

        .status-ok p {
            margin: 0;
            font-size: 11px;
        }

        /* ✅ CONTROL DE SALTOS DE PÁGINA */
        .module-break {
            page-break-after: always;
        }

        .avoid-break {
            page-break-inside: avoid;
        }

        /* ✅ PARTE INDICATOR */
        .part-header {
            text-align: center;
            font-size: 14px;
            color: #ff6b35;
            font-weight: bold;
            margin-bottom: 20px;
            padding: 10px;
            border: 2px solid #ff6b35;
            background-color: #fff8f5;
            page-break-after: avoid;
        }

        /* ✅ Ajustes para imágenes sin errores */
        .no-image-available {
            background-color: #f5f5f5;
            border: 2px dashed #ccc;
            padding: 30px;
            text-align: center;
            color: #666;
            font-style: italic;
            margin: 15px 0;
        }

        /* ✅ Espaciado mejorado */
        .content-spacing {
            margin-top: 15mm; /* ✅ Más espacio desde el header */
            margin-bottom: 15mm;
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-logo">Gudnus</div>
    <div class="header-title">
        INFORME DE ELECTROLUMINISCENCIA - {{ strtoupper($project->name) }}
        @if($partNumber && $totalParts)
            | PARTE {{ $partNumber }} DE {{ $totalParts }}
        @endif
    </div>
</div>

<!-- FOOTER -->
<div class="footer">
    Informe generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }}
    @if($partNumber && $totalParts)
        | Parte {{ $partNumber }}/{{ $totalParts }}
    @endif
</div>

<div class="content content-spacing">
    @if($partNumber && $totalParts)
        <div class="part-header">
            PARTE {{ $partNumber }} DE {{ $totalParts }} - ANÁLISIS DE MÓDULOS
        </div>
    @endif

    @foreach($images as $i => $img)
        @if($img->processedImage)
            <div class="module-section avoid-break">
                <div class="module-title">
                    {{ $img->filename ?? basename($img->original_path) }}
                    @if($img->folder_path)
                        <small style="color: #666; font-weight: normal; font-size: 10px;">
                            | Ubicación: {{ $img->folder_path }}
                        </small>
                    @endif
                </div>

                @php
                    // ✅ Verificar si hay imagen analizada
                    $hasAnalyzedImage = isset($analyzedImages[$img->id]);
                    $imageHtml = '';

                    if($hasAnalyzedImage) {
                        $analyzedPath = $analyzedImages[$img->id];
                        if(file_exists($analyzedPath)) {
                            $imageData = file_get_contents($analyzedPath);
                            $base64 = base64_encode($imageData);
                            $imageHtml = 'data:image/jpeg;base64,' . $base64;
                        }
                    }

                    // ✅ Procesar análisis de errores
                    $hasAnalysis = $img->processedImage->ai_response_json;
                    $errors = [];
                    $errorCount = 0;

                    if($hasAnalysis) {
                        $json = $img->processedImage->error_edits_json ?? $img->processedImage->ai_response_json;
                        $analysis = json_decode($json, true);
                        $predictions = $analysis['predictions'] ?? [];

                        // Filtrar por probabilidad mínima
                        $minProb = $img->processedImage->min_probability ?? 0.3;
                        foreach($predictions as $prediction) {
                            $prob = $prediction['probability'] ?? 0;
                            if($prob >= $minProb) {
                                $tagName = $prediction['tagName'] ?? 'Defecto desconocido';
                                $errors[$tagName] = ($errors[$tagName] ?? 0) + 1;
                                $errorCount++;
                            }
                        }
                    }
                @endphp

                <div class="image-container">
                    @if($hasAnalyzedImage && $imageHtml)
                        <div class="image-label">Imagen con análisis de errores</div>
                        <img src="{{ $imageHtml }}" class="analyzed-image" alt="Imagen analizada del módulo">
                    @else
                        <div class="no-image-available">
                            <p><strong>Imagen analizada no disponible</strong></p>
                            <p>El módulo ha sido procesado pero la imagen con anotaciones no está disponible en este momento.</p>
                        </div>
                    @endif
                </div>

                @if($errorCount > 0)
                    <div class="error-list">
                        <strong>Errores detectados ({{ $errorCount }} total):</strong>
                        <ul>
                            @foreach($errors as $errorType => $count)
                                <li>
                                    <strong>{{ $errorType }}:</strong>
                                    {{ $count }} {{ $count == 1 ? 'error' : 'errores' }}
                                    @if($errorType == 'cell_crack')
                                        - Grietas en celdas que pueden afectar el rendimiento
                                    @elseif($errorType == 'soldering_issue')
                                        - Problemas en soldaduras que requieren atención
                                    @elseif($errorType == 'inactive_cell')
                                        - Celdas inactivas que reducen la producción
                                    @elseif($errorType == 'corrosion')
                                        - Signos de corrosión que pueden empeorar con el tiempo
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="status-ok">
                        <p>
                            ✓ <strong>Sin errores detectados</strong> - El módulo presenta un estado óptimo sin defectos significativos
                        </p>
                    </div>
                @endif
            </div>

            @php
                // ✅ Control inteligente de saltos de página
                $isLastItem = ($i == count($images) - 1);
                $shouldBreak = !$isLastItem && (($i + 1) % 3 == 0); // Cada 3 módulos
            @endphp

            @if($shouldBreak)
                <div class="module-break"></div>
            @endif
        @endif
    @endforeach
</div>

</body>
</html>
