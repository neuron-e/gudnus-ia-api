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

        /* Índice */
        .index-item {
            border-bottom: 1px dotted #ccc;
            padding: 4px 0;
            position: relative;
            font-size: 11px;
        }

        .index-item.main {
            font-weight: bold;
            margin-top: 12px;
            font-size: 12px;
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

        /* Stats boxes */
        .stats-container {
            width: 100%;
            margin: 15px 0;
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

        .page-break {
            page-break-after: always;
        }

        .summary-highlight {
            background-color: #f8f9fa;
            border-left: 4px solid #ff6b35;
            padding: 15px;
            margin: 15px 0;
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="header-logo">Gudnus</div>
    <div class="header-title">INFORME DE ELECTROLUMINISCENCIA - {{ strtoupper($project->name) }}</div>
</div>

<!-- FOOTER -->
<div class="footer">
    Informe generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }}
</div>

<div class="content">
    <!-- ÍNDICE -->
    <div class="section-title">ÍNDICE</div>

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

    @foreach($sections as $sectionName => $sectionData)
        <div class="index-item sub">
            <span>3.3.- {{ $sectionName }}</span>
            <span class="page-num">{{ 8 + $loop->index }}</span>
        </div>
    @endforeach

    <div class="index-item main">
        <span>4.- CONCLUSIONES</span>
        <span class="page-num">{{ 8 + count($sections) + 1 }}</span>
    </div>

    <div class="page-break"></div>

    <!-- INTRODUCCIÓN -->
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

    <p>Este informe presenta los resultados del análisis realizado sobre una muestra de <strong>{{ $totalImages }}</strong> módulos fotovoltaicos, identificando y clasificando los defectos encontrados.</p>

    <div class="page-break"></div>

    <!-- METODOLOGÍA -->
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
    <p>La inspección se ha realizado sobre un total de <strong>{{ $totalImages }}</strong> módulos distribuidos a lo largo de la instalación, asegurando una muestra representativa del estado general de la planta fotovoltaica.</p>

    <!-- Resumen Ejecutivo -->
    <div class="summary-highlight">
        <div class="subsection-title">RESUMEN EJECUTIVO</div>

        <div class="stats-container">
            <div class="stat-box">
                <span class="stat-number">{{ $stats['total_images'] }}</span>
                <span class="stat-label">MÓDULOS<br>ANALIZADOS</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">{{ $stats['total_errors'] }}</span>
                <span class="stat-label">DEFECTOS<br>DETECTADOS</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">{{ $stats['error_rate'] }}%</span>
                <span class="stat-label">TASA DE<br>ERRORES</span>
            </div>
        </div>

        <div class="subsection-title">Distribución por tipo de error:</div>
        <table>
            <thead>
            <tr>
                <th>Tipo de Error</th>
                <th>Cantidad</th>
                <th>Porcentaje</th>
            </tr>
            </thead>
            <tbody>
            @foreach($stats['errors_by_type'] as $errorType => $count)
                <tr>
                    <td><strong>{{ $errorType }}</strong></td>
                    <td style="text-align: center;">{{ $count }}</td>
                    <td style="text-align: center;">{{ $stats['total_errors'] > 0 ? round(($count / $stats['total_errors']) * 100, 1) : 0 }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="subsection-title">Distribución por sección:</div>
        <table>
            <thead>
            <tr>
                <th>Sección/Carpeta</th>
                <th>Módulos</th>
                <th>Con Errores</th>
                <th>% Afectación</th>
            </tr>
            </thead>
            <tbody>
            @foreach($sections as $sectionName => $sectionData)
                <tr>
                    <td><strong>{{ $sectionName }}</strong></td>
                    <td style="text-align: center;">{{ $sectionData['total_images'] }}</td>
                    <td style="text-align: center;">{{ $sectionData['images_with_errors'] }}</td>
                    <td style="text-align: center;">
                        {{ $sectionData['total_images'] > 0 ? round(($sectionData['images_with_errors'] / $sectionData['total_images']) * 100, 1) : 0 }}%
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
