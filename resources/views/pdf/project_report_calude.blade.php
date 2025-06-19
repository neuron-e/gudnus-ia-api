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
            content: "Informe generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} | Página {PAGE_NUM}"
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
            font-size: 9px;
        }

        th {
            background-color: #ff6b35;
            color: white;
            padding: 8px 6px;
            text-align: left;
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

        /* Imágenes */
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
            height: 500px;
            max-width: 500px;
            border: 1px solid #ddd;
            margin: 15px 0;
            object-fit: fill;
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
            margin-bottom: 4px;
        }

        .recommendations {
            background-color: #f8f9fa;
            padding: 12px;
            border-left: 4px solid #ff6b35;
            margin: 15px 0;
        }

        .status-ok {
            background-color: #e8f5e8;
            border: 1px solid #4caf50;
            padding: 12px;
            margin: 15px 0;
            color: #2e7d32;
            border-radius: 3px;
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

    <div class="index-item main">
        <span>3.- RESULTADO DEL ESTUDIO</span>
        <span class="page-num">5</span>
    </div>

    <div class="index-item sub">
        <span>3.1.- Resumen de módulos analizados</span>
        <span class="page-num">5</span>
    </div>

    <div class="index-item sub">
        <span>3.2.- Visualización de errores detectados</span>
        <span class="page-num">6</span>
    </div>

    <div class="index-item main">
        <span>4.- CONCLUSIONES</span>
        <span class="page-num">{{ count($images) + 8 }}</span>
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
        <li>Daños en las células solares</li>
        <li>Microgrietas y roturas</li>
        <li>Defectos de soldadura</li>
    </ul>

    <div class="methodology-section">
        <p><strong>Información del proyecto:</strong></p>
        <p><strong>Proyecto:</strong> {{ $project->name }}</p>
        <p><strong>Marca/Modelo:</strong> {{ $project->panel_brand }} / {{ $project->panel_model }}</p>
        <p><strong>Inspector:</strong> {{ $project->inspector_name }}</p>
        <p><strong>Fecha de análisis:</strong> {{ now()->format('d/m/Y') }}</p>
    </div>
</div>

<div class="page-break"></div>

<!-- METODOLOGÍA -->
<div class="content-page">
    <div class="section-title">2.- METODOLOGÍA DEL ESTUDIO</div>

    <div class="subsection-title">2.1.- Equipamiento necesario</div>
    <ul class="equipment-list">
        <li>Generador portátil de alimentación</li>
        <li>Fuente regulable de alimentación en continua</li>
        <li>Cámara de electroluminiscencia especializada</li>
        <li>Trípode profesional para estabilización</li>
        <li>Cables y conectores específicos</li>
        <li>Software de análisis de imágenes con IA</li>
    </ul>

    <div class="subsection-title">2.2.- Procedimiento de toma de datos</div>
    <p>El análisis se realizó siguiendo un protocolo estricto:</p>

    <ol>
        <li><strong>Preparación:</strong> Identificación y catalogación de cada módulo mediante códigos de barras</li>
        <li><strong>Conexión:</strong> Conexión del módulo a la fuente de alimentación regulable</li>
        <li><strong>Calibración:</strong> Ajuste de la intensidad cercana a la de cortocircuito del módulo</li>
        <li><strong>Captura:</strong> Realización de fotografías electroluminiscentes con cámara especializada</li>
        <li><strong>Análisis:</strong> Procesamiento mediante algoritmos de inteligencia artificial</li>
        <li><strong>Clasificación:</strong> Categorización de defectos según severidad y tipo</li>
    </ol>
</div>

<div class="page-break"></div>

<!-- RESULTADOS -->
<div class="content-page">
    <div class="section-title">3.- RESULTADO DEL ESTUDIO</div>

    <div class="subsection-title">3.1.- Resumen de módulos analizados</div>

    @php
        $totalErrores = 0;
        $tipoErrores = [];

        foreach ($images as $img) {
            $json = $img->processedImage->error_edits_json ?? $img->processedImage->ai_response_json;
            $prob = $img->processedImage->min_probability ?? 0.5;
            $preds = collect(json_decode($json, true)['predictions'] ?? []);

            // Filtrar por probabilidad mínima (igual que en las imágenes)
            $filteredPreds = $preds->filter(function($p) use ($prob) {
                return !isset($p['probability']) || $p['probability'] >= $prob;
            });

            $totalErrores += $filteredPreds->count();
            foreach ($filteredPreds as $p) {
                $tipoErrores[$p['tagName']] = ($tipoErrores[$p['tagName']] ?? 0) + 1;
            }
        }
    @endphp

    <div class="stats-container">
        <div class="stats-row">
            <div class="stat-box">
                <span class="stat-number">{{ count($images) }}</span>
                <span class="stat-label">MÓDULOS<br>ANALIZADOS</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">{{ $totalErrores }}</span>
                <span class="stat-label">ERRORES<br>DETECTADOS</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">{{ count($tipoErrores) }}</span>
                <span class="stat-label">TIPOS DE<br>DEFECTOS</span>
            </div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width: 50%">Módulo</th>
            <th style="width: 20%">Errores detectados</th>
            <th style="width: 30%">Tipos de error</th>
        </tr>
        </thead>
        <tbody>
        @foreach($images as $img)
            @php
                $json = $img->processedImage->error_edits_json ?? $img->processedImage->ai_response_json;
                $prob = $img->processedImage->min_probability ?? 0.5;
                $preds = collect(json_decode($json, true)['predictions'] ?? []);

                // Filtrar por probabilidad mínima
                $filteredPreds = $preds->filter(function($p) use ($prob) {
                    return !isset($p['probability']) || $p['probability'] >= $prob;
                });

                $tags = $filteredPreds->pluck('tagName')->unique()->implode(', ');
            @endphp
            <tr>
                <td><strong>{{ $img->filename }}</strong></td>
                <td style="text-align: center;">{{ $filteredPreds->count() }}</td>
                <td>{{ $tags }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="page-break"></div>

<!-- VISUALIZACIÓN DE ERRORES -->
<div class="content-page">
    <div class="section-title">3.2.- Visualización de errores detectados</div>

    @foreach($images as $i => $img)
        <div class="module-section">
            <div class="module-title">Módulo: {{ $img->filename }}</div>

            @if(isset($analyzedPaths[$i]))
                <div class="image-label">Imagen con errores detectados</div>
                <img src="{{ $analyzedPaths[$i] }}" class="analyzed-image">

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
            @endif
        </div>

        @if(($i + 1) % 2 == 0 && $i < count($images) - 1)
            <div class="page-break"></div>
            <div class="content-page">
                @endif
                @endforeach
            </div>

            <div class="page-break"></div>

            <!-- CONCLUSIONES -->
            <div class="content-page">
                <div class="section-title">4.- CONCLUSIONES</div>

                <div class="conclusion-highlight">
                    <span class="big-number">{{ $totalErrores }}</span>
                    errores detectados en {{ count($images) }} módulos analizados
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
                            <td style="text-align: center;">{{ round(($count / $totalErrores) * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div class="subsection-title">Recomendaciones:</div>

                <div class="recommendations">
                    <p><strong>1. Evaluación prioritaria:</strong> Se recomienda evaluar en detalle los módulos con mayor número de errores detectados para determinar si requieren reemplazo inmediato.</p>

                    <p><strong>2. Monitoreo continuo:</strong> Implementar un programa de seguimiento para los módulos con errores menores para evaluar su evolución temporal.</p>

                    <p><strong>3. Análisis de causas:</strong> Investigar las posibles causas de los defectos encontrados para prevenir su aparición en futuras instalaciones.</p>

                    <p><strong>4. Verificación de garantías:</strong> Contactar con el fabricante para verificar la cobertura de garantía de los módulos afectados.</p>
                </div>

                <div class="status-ok">
                    <p style="margin: 0; font-weight: bold;">
                        ✓ <strong>Estado general:</strong> El análisis muestra un porcentaje de errores dentro de rangos aceptables para instalaciones fotovoltaicas comerciales.
                    </p>
                </div>
            </div>

            <!-- PÁGINA FINAL EMPRESA -->
            <div class="company-page">
                <div class="company-logo">Gudnus</div>
                <div class="company-tagline">Efficient Engineering Solutions</div>

                <div class="company-contact">
                    <p><strong>Contacto</strong></p>
                    <p>📍 C/ Arte, 21, 28033 Madrid</p>
                    <p>📞 917 67 19 82</p>
                    <p>✉️ info@gudnus.com</p>
                    <br>
                    <p style="font-style: italic; color: #666;">Especialistas en inspección y análisis<br>de instalaciones fotovoltaicas</p>
                </div>
            </div>
</div>

</body>
</html>
