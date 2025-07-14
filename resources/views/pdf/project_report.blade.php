<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm 20mm 15mm 20mm;
            size: A4;
        }

        @page:first {
            margin: 0;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 0;
            color: #333;
            counter-reset: page;
        }

        /* Header para todas las páginas excepto portada */
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
            order: 2;
        }

        .header-title {
            flex-grow: 1;
            text-align: center;
            font-size: 10px;
            margin: 0;
            order: 1;
        }

        /* Footer */
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

        .footer:before {
            counter-increment: page;
            content: "Informe generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} | Página " counter(page);
        }

        /* Portada */
        .cover-page {
            background-color: #ff6b35;
            height: 100vh;
            width: 100%;
            position: relative;
            margin: -25mm -20mm 0 -20mm;
            padding: 50mm 40mm;
            box-sizing: border-box;
            color: white;
            page-break-after: always;
        }

        .cover-title {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 15mm;
            line-height: 1.2;
            text-align: left;
        }

        .cover-subtitle {
            font-size: 20px;
            font-weight: normal;
            margin-bottom: 25mm;
            line-height: 1.3;
        }

        .cover-project {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 30mm;
        }

        .cover-date {
            font-size: 16px;
            margin-bottom: 15mm;
        }

        .cover-logo {
            position: absolute;
            bottom: 30mm;
            right: 30mm;
            font-size: 120px;
            font-weight: bold;
            opacity: 0.6;
            color: rgba(255, 255, 255, 0.8);
            font-family: Arial Black, sans-serif;
            z-index: 1;
        }

        /* Páginas de contenido */
        .content-page {
            margin-top: 8mm;
            margin-bottom: 10mm;
        }

        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #ff6b35;
            margin-bottom: 20px;
            border-bottom: 2px solid #ff6b35;
            padding-bottom: 8px;
        }

        .subsection-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin: 20px 0 10px 0;
        }

        /* Tablas */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10px;
        }

        th {
            background-color: #ff6b35;
            color: white;
            padding: 8px 6px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #ff6b35;
        }

        td {
            padding: 6px;
            border: 1px solid #ddd;
            vertical-align: top;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* Stats boxes */
        .stats-container {
            width: 100%;
            margin: 15px 0;
        }

        .stats-row {
            width: 100%;
        }

        .stat-box {
            display: inline-block;
            width: 30%;
            text-align: center;
            background-color: #ff6b35;
            color: white;
            padding: 12px 8px;
            margin: 0 1.5%;
            vertical-align: top;
            border-radius: 3px;
        }

        .stat-number {
            font-size: 18px;
            font-weight: bold;
            display: block;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 8px;
            line-height: 1.1;
        }

        /* Imágenes analizadas */
        .module-section {
            page-break-inside: avoid;
            margin-bottom: 30px;
            border: 1px solid #eee;
            padding: 15px;
            min-height: 450px;
        }

        .module-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #333;
            background-color: #f0f0f0;
            padding: 10px;
            border-left: 4px solid #ff6b35;
        }

        .analyzed-image {
            width: 100%;
            max-width: 500px;
            border: 1px solid #ddd;
            margin: 15px 0;
            object-fit: contain;
        }

        .image-label {
            font-weight: bold;
            margin-bottom: 12px;
            color: #ff6b35;
            font-size: 12px;
        }

        .error-list {
            margin-top: 15px;
            padding: 12px;
            background-color: #f8f9fa;
            border-left: 3px solid #ff6b35;
            border-radius: 3px;
        }

        .error-list ul {
            margin: 8px 0;
            padding-left: 18px;
        }

        .error-list li {
            margin: 5px 0;
            font-size: 11px;
            line-height: 1.4;
        }

        .status-ok {
            background-color: #e8f5e8;
            border: 1px solid #4caf50;
            padding: 12px;
            margin: 15px 0;
            color: #2e7d32;
            border-radius: 3px;
        }

        /* Metodología */
        .methodology-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #ff6b35;
            margin: 15px 0;
        }

        .equipment-list {
            list-style: none;
            padding: 0;
            margin: 10px 0;
        }

        .equipment-list li {
            padding: 4px 0;
            padding-left: 15px;
            position: relative;
        }

        .equipment-list li:before {
            content: "▶";
            color: #ff6b35;
            position: absolute;
            left: 0;
        }

        /* Conclusiones */
        .conclusion-highlight {
            background-color: #ff6b35;
            color: white;
            padding: 15px;
            margin: 15px 0;
            text-align: center;
            border-radius: 3px;
        }

        .conclusion-highlight .big-number {
            font-size: 28px;
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }

        .recommendations {
            background-color: #f8f9fa;
            padding: 12px;
            border-left: 4px solid #ff6b35;
            margin: 15px 0;
        }

        /* Índice */
        .index-title {
            font-size: 18px;
            font-weight: bold;
            color: #ff6b35;
            margin-bottom: 25px;
            border-bottom: 2px solid #ff6b35;
            padding-bottom: 6px;
        }

        .index-item {
            border-bottom: 1px dotted #ccc;
            padding: 4px 0;
            position: relative;
            font-size: 11px;
        }

        .index-item.main {
            font-weight: bold;
            margin-top: 12px;
            font-size: 11px;
        }

        .index-item.sub {
            margin-left: 15px;
            font-size: 10px;
            color: #666;
        }

        .index-item .page-num {
            position: absolute;
            right: 0;
            font-weight: normal;
        }

        /* Página final empresa */
        .company-page {
            text-align: center;
            padding-top: 60mm;
            page-break-before: always;
        }

        .company-logo {
            font-size: 42px;
            color: #ff6b35;
            font-weight: bold;
            margin-bottom: 15mm;
            letter-spacing: 1px;
        }

        .company-tagline {
            font-size: 16px;
            color: #999;
            margin-bottom: 25mm;
            font-style: italic;
        }

        .company-contact {
            font-size: 11px;
            color: #333;
            line-height: 1.8;
        }

        .company-contact p {
            margin: 3mm 0;
        }

        .company-contact strong {
            color: #ff6b35;
            font-size: 12px;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>

<!-- HEADER (aparece en todas las páginas excepto portada) -->
<div class="header">
    <div class="header-logo">Gudnus</div>
    <div class="header-title">INFORME DE ELECTROLUMINISCENCIA - {{ strtoupper($project->name) }}</div>
</div>

<!-- FOOTER -->
<div class="footer"></div>

<!-- PORTADA -->
<div class="cover-page">
    <div class="cover-title">INFORME FINAL</div>
    <div class="cover-subtitle">INSPECCIÓN DE<br>ELECTROLUMINISCENCIA</div>
    <div class="cover-project">{{ strtoupper($project->name) }}</div>
    <div class="cover-date">{{ now()->format('d/m/Y') }}</div>
    <div class="cover-logo">G</div>
</div>

<!-- ÍNDICE -->
<div class="content-page">
    <div class="index-title">ÍNDICE</div>

    <div class="index-item main">
        <span>1.- INTRODUCCIÓN</span>
        <span class="page-num">3</span>
    </div>

    <div class="index-item main">
        <span>2.- METODOLOGÍA DEL ESTUDIO</span>
        <span class="page-num">4</span>
    </div>

    <div class="index-item sub">
        <span>2.1.- Equipamiento necesario</span>
        <span class="page-num">4</span>
    </div>

    <div class="index-item sub">
        <span>2.2.- Procedimiento de toma de datos</span>
        <span class="page-num">4</span>
    </div>

    <div class="index-item sub">
        <span>2.3.- Situación de la muestra seleccionada</span>
        <span class="page-num">5</span>
    </div>

    <div class="index-item main">
        <span>3.- RESULTADO DEL ESTUDIO</span>
        <span class="page-num">6</span>
    </div>

    <div class="index-item sub">
        <span>3.1.- Tipología de problemas</span>
        <span class="page-num">6</span>
    </div>

    <div class="index-item sub">
        <span>3.2.- Tabla de localización de problemas por gravedad</span>
        <span class="page-num">7</span>
    </div>

    <div class="index-item sub">
        <span>3.3.- Visualización de errores detectados</span>
        <span class="page-num">8</span>
    </div>

    <div class="index-item main">
        <span>4.- CONCLUSIONES</span>
        <span class="page-num">{{ $images->count() + 10 }}</span>
    </div>
</div>

<div class="page-break"></div>

<!-- INTRODUCCIÓN -->
<div class="content-page">
    <div class="section-title">1.- INTRODUCCIÓN</div>

    <p>El presente estudio de inspección de electroluminiscencia realizado en el proyecto <strong>{{ $project->name }}</strong> tiene por objetivo evaluar mediante esta técnica cualitativa la posible presencia de degradaciones en los módulos fotovoltaicos.</p>

    <p>La electroluminiscencia permite detectar defectos que son más fácilmente observables por esta técnica que por otros tipos de estudios, tales como:</p>

    <ul class="equipment-list">
        <li>Degradación PID (Potential Induced Degradation)</li>
        <li>Degradaciones en las capas de cobertura</li>
        <li>Microfisuras en las células</li>
        <li>Defectos de soldadura de strings</li>
        <li>Células inactivas</li>
        <li>Problemas de conexionado</li>
    </ul>

    <p>Este informe presenta los resultados del análisis realizado sobre una muestra de <strong>{{ $images->count() }}</strong> módulos fotovoltaicos, identificando y clasificando los defectos encontrados.</p>
</div>

<div class="page-break"></div>

<!-- METODOLOGÍA -->
<div class="content-page">
    <div class="section-title">2.- METODOLOGÍA DEL ESTUDIO</div>

    <div class="subsection-title">2.1.- Equipamiento necesario</div>
    <div class="methodology-section">
        <p>Para la realización de este estudio se ha empleado el siguiente equipamiento:</p>
        <ul class="equipment-list">
            <li>Cámara de electroluminiscencia especializada</li>
            <li>Sistema de inyección de corriente</li>
            <li>Software de análisis de imágenes con IA</li>
            <li>Equipos de medición y calibración</li>
        </ul>
    </div>

    <div class="subsection-title">2.2.- Procedimiento de toma de datos</div>
    <p>El procedimiento seguido para la inspección ha sido el siguiente:</p>
    <ol>
        <li><strong>Preparación:</strong> Identificación y acceso a los módulos a inspeccionar</li>
        <li><strong>Inyección de corriente:</strong> Aplicación de corriente inversa controlada</li>
        <li><strong>Captura de imágenes:</strong> Toma de fotografías en condiciones controladas</li>
        <li><strong>Análisis automático:</strong> Procesamiento mediante inteligencia artificial</li>
        <li><strong>Verificación:</strong> Revisión y validación de resultados</li>
    </ol>

    <div class="subsection-title">2.3.- Situación de la muestra seleccionada</div>
    <p>La inspección se ha realizado sobre un total de <strong>{{ $images->count() }}</strong> módulos distribuidos a lo largo de la instalación, asegurando una muestra representativa del estado general de la planta fotovoltaica.</p>

    <!-- Stats boxes -->
    <div class="stats-container">
        <div class="stats-row">
            <div class="stat-box">
                <span class="stat-number">{{ $images->count() }}</span>
                <span class="stat-label">MÓDULOS<br>ANALIZADOS</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">{{ $images->filter(fn($img) => $img->processedImage && $img->processedImage->ai_response_json)->count() }}</span>
                <span class="stat-label">CON ANÁLISIS<br>IA COMPLETADO</span>
            </div>
            <div class="stat-box">
                @php
                    $totalErrores = 0;
                    foreach($images as $img) {
                        if($img->processedImage && $img->processedImage->ai_response_json) {
                            $analysis = json_decode($img->processedImage->ai_response_json, true);
                            $totalErrores += count($analysis['predictions'] ?? []);
                        }
                    }
                @endphp
                <span class="stat-number">{{ $totalErrores }}</span>
                <span class="stat-label">DEFECTOS<br>DETECTADOS</span>
            </div>
        </div>
    </div>
</div>

<div class="page-break"></div>

<!-- RESULTADO DEL ESTUDIO -->
<div class="content-page">
    <div class="section-title">3.- RESULTADO DEL ESTUDIO</div>

    <div class="subsection-title">3.1.- Tipología de problemas</div>
    <p>Los defectos detectados se han clasificado en las siguientes categorías:</p>

    @php
        $tipoErrores = [];
        foreach($images as $img) {
            if($img->processedImage && $img->processedImage->ai_response_json) {
                $analysis = json_decode($img->processedImage->ai_response_json, true);
                $predictions = $analysis['predictions'] ?? [];
                foreach($predictions as $prediction) {
                    $tipo = $prediction['tagName'] ?? 'Defecto';
                    $tipoErrores[$tipo] = ($tipoErrores[$tipo] ?? 0) + 1;
                }
            }
        }
    @endphp

    <table>
        <thead>
        <tr>
            <th>Tipo de Defecto</th>
            <th>Cantidad</th>
            <th>Descripción</th>
        </tr>
        </thead>
        <tbody>
        @foreach($tipoErrores as $tipo => $count)
            <tr>
                <td><strong>{{ $tipo }}</strong></td>
                <td style="text-align: center;">{{ $count }}</td>
                <td>
                    @switch($tipo)
                        @case('Intensidad')
                            Variaciones en la intensidad lumínica que pueden indicar problemas de rendimiento
                            @break
                        @case('Fingers')
                            Defectos en las conexiones internas de las células (fingers)
                            @break
                        @case('Black Edges')
                            Bordes oscuros que indican degradación en los extremos de las células
                            @break
                        @case('Microgrietas')
                            Fisuras microscópicas en el material semiconductor
                            @break
                        @default
                            Otros defectos detectados en el análisis
                    @endswitch
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="subsection-title">3.2.- Tabla de localización de problemas por gravedad</div>

    <table>
        <thead>
        <tr>
            <th>Posición/Módulo</th>
            <th>Problemas graves con afectación de la producción del módulo</th>
            <th>Problemas a evaluar en cuanto a afectación a la producción del módulo</th>
        </tr>
        </thead>
        <tbody>
        @php $gravedadGrave = 0; $gravedadEvaluar = 0; @endphp
        @foreach($images as $img)
            @php
                $filename = $img->filename ?? basename($img->original_path);
                $problemasGraves = 0;
                $problemasEvaluar = 0;

                if($img->processedImage && $img->processedImage->ai_response_json) {
                    $analysis = json_decode($img->processedImage->ai_response_json, true);
                    $predictions = $analysis['predictions'] ?? [];

                    foreach($predictions as $prediction) {
                        $prob = $prediction['probability'] ?? 0;
                        if($prob >= 0.8) {
                            $problemasGraves++;
                        } elseif($prob >= 0.6) {
                            $problemasEvaluar++;
                        }
                    }
                }

                $gravedadGrave += $problemasGraves;
                $gravedadEvaluar += $problemasEvaluar;
            @endphp
            <tr>
                <td><strong>{{ $filename }}</strong></td>
                <td style="text-align: center;">{{ $problemasGraves > 0 ? $problemasGraves : '' }}</td>
                <td style="text-align: center;">{{ $problemasEvaluar > 0 ? $problemasEvaluar : '' }}</td>
            </tr>
        @endforeach
        <tr style="background-color: #f0f0f0; font-weight: bold;">
            <td><strong>Total</strong></td>
            <td style="text-align: center;">{{ $gravedadGrave }}</td>
            <td style="text-align: center;">{{ $gravedadEvaluar }}</td>
        </tr>
        <tr style="background-color: #f8f8f8;">
            <td><strong>% sobre la muestra</strong></td>
            <td style="text-align: center;">{{ $images->count() > 0 ? round(($gravedadGrave / $images->count()) * 100, 2) : 0 }}%</td>
            <td style="text-align: center;">{{ $images->count() > 0 ? round(($gravedadEvaluar / $images->count()) * 100, 2) : 0 }}%</td>
        </tr>
        </tbody>
    </table>
</div>

<div class="page-break"></div>

<!-- VISUALIZACIÓN DE ERRORES -->
<div class="content-page">
    <div class="section-title">3.3.- Visualización de errores detectados</div>

    @foreach($images as $i => $img)
        @if($img->processedImage && $img->processedImage->ai_response_json)
            <div class="module-section">
                <div class="module-title">{{ $img->filename ?? basename($img->original_path) }}</div>

                @php
                    // ✅ CORREGIDO: Usar imagen analizada si está disponible
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
                @endphp

                @if($hasAnalyzedImage && $imageHtml)
                    <div class="image-label">Imagen con errores detectados</div>
                    <img src="{{ $imageHtml }}" class="analyzed-image" alt="Imagen analizada con anotaciones">

                    @php
                        $json = $img->processedImage->error_edits_json ?? $img->processedImage->ai_response_json;
                        $prob = $img->processedImage->min_probability ?? 0.5;
                        $preds = collect(json_decode($json, true)['predictions'] ?? []);

                        // Filtrar por probabilidad mínima
                        $filteredPreds = $preds->filter(function($p) use ($prob) {
                            return !isset($p['probability']) || $p['probability'] >= $prob;
                        });
                    @endphp

                    @if($filteredPreds->count() > 0)
                        <div class="error-list">
                            <strong>Errores encontrados:</strong>
                            <ul>
                                @foreach($filteredPreds->groupBy('tagName') as $type => $errors)
                                    <li><strong>{{ $type }}:</strong> {{ $errors->count() }} {{ $errors->count() == 1 ? 'error' : 'errores' }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="status-ok">
                            <p style="margin: 0; font-weight: bold;">
                                ✓ <strong>Sin errores encontrados</strong> - El módulo presenta un estado óptimo
                            </p>
                        </div>
                    @endif
                @else
                    <p><em>Imagen analizada no disponible para este módulo.</em></p>
                @endif
            </div>

            @if(($i + 1) % 2 == 0 && $i < count($images) - 1)
                <div class="page-break"></div>
                <div class="content-page">
                    @endif
                    @endif
                    @endforeach
                </div>

                <div class="page-break"></div>

                <!-- CONCLUSIONES -->
                <div class="content-page">
                    <div class="section-title">4.- CONCLUSIONES</div>

                    <div class="conclusion-highlight">
                        <span class="big-number">{{ $totalErrores }}</span>
                        errores detectados en {{ $images->count() }} módulos analizados
                    </div>

                    <div class="subsection-title">Distribución de errores por tipo:</div>

                    <table style="margin-bottom: 25px;">
                        <thead>
                        <tr>
                            <th>Tipo de Error</th>
                            <th>Cantidad</th>
                            <th>Porcentaje</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($tipoErrores as $tipo => $count)
                            <tr>
                                <td><strong>{{ $tipo }}</strong></td>
                                <td style="text-align: center;">{{ $count }}</td>
                                <td style="text-align: center;">{{ $totalErrores > 0 ? round(($count / $totalErrores) * 100, 1) : 0 }}%</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <div class="subsection-title">Evaluación general:</div>

                    @if($totalErrores == 0)
                        <div class="status-ok">
                            <p><strong>Estado excelente:</strong> No se han detectado defectos significativos en los módulos analizados. La instalación presenta un estado óptimo.</p>
                        </div>
                    @elseif($gravedadGrave == 0)
                        <div class="recommendations">
                            <p><strong>Estado bueno:</strong> Se han detectado algunos defectos menores que requieren seguimiento pero no afectan significativamente al rendimiento actual.</p>
                        </div>
                    @elseif($gravedadGrave <= 3)
                        <div class="recommendations">
                            <p><strong>Estado aceptable:</strong> Se han detectado algunos defectos que requieren atención. Se recomienda evaluación y posible intervención.</p>
                        </div>
                    @else
                        <div class="recommendations">
                            <p><strong>Requiere atención:</strong> Se han detectado múltiples defectos que pueden afectar al rendimiento. Se recomienda intervención prioritaria.</p>
                        </div>
                    @endif

                    <div class="subsection-title">Recomendaciones:</div>

                    <div class="recommendations">
                        <p><strong>1. Evaluación prioritaria:</strong> Se recomienda evaluar en detalle los módulos con mayor número de errores detectados para determinar si requieren reemplazo inmediato.</p>

                        <p><strong>2. Monitoreo continuo:</strong> Implementar un programa de seguimiento para los módulos con errores menores para evaluar su evolución temporal.</p>

                        <p><strong>3. Análisis de causas:</strong> Investigar las posibles causas de los defectos encontrados para prevenir su aparición en futuras instalaciones.</p>

                        <p><strong>4. Mantenimiento preventivo:</strong> Establecer un programa de inspecciones regulares utilizando técnicas de electroluminiscencia.</p>
                    </div>
                </div>

                <!-- PÁGINA FINAL EMPRESA -->
                <div class="company-page">
                    <div class="company-logo">Gudnus</div>
                    <div class="company-tagline">Efficient Engineering Solutions</div>
                    <div class="company-contact">
                        <p><strong>Contacto</strong></p>
                        <p>🏢 C/ Arte, 21, 28033 Madrid</p>
                        <p>📞 917 67 19 82</p>
                        <p>📧 info@gudnus.com</p>
                        <p>Especialistas en inspección y análisis de instalaciones fotovoltaicas</p>
                    </div>
                </div>
</div>

</body>
</html>
