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

        /* Conclusiones destacadas */
        .conclusion-highlight {
            background-color: #ff6b35;
            color: white;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
            border-radius: 5px;
        }

        .conclusion-highlight .big-number {
            font-size: 36px;
            font-weight: bold;
            display: block;
            margin-bottom: 8px;
        }

        .conclusion-highlight .description {
            font-size: 14px;
            line-height: 1.4;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background-color: #f8f9fa;
            border-left: 4px solid #ff6b35;
            padding: 15px;
            border-radius: 3px;
        }

        .stat-card .number {
            font-size: 24px;
            font-weight: bold;
            color: #ff6b35;
            display: block;
            margin-bottom: 5px;
        }

        .stat-card .label {
            font-size: 11px;
            color: #666;
            font-weight: bold;
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

        /* Recomendaciones */
        .recommendations {
            background-color: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #ff6b35;
            margin: 15px 0;
            border-radius: 3px;
        }

        .recommendation-item {
            margin: 12px 0;
            padding-left: 20px;
            position: relative;
        }

        .recommendation-item:before {
            content: "▶";
            color: #ff6b35;
            font-weight: bold;
            position: absolute;
            left: 0;
        }

        /* Status indicators */
        .status-excellent {
            background-color: #e8f5e8;
            border: 2px solid #4caf50;
            color: #2e7d32;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            text-align: center;
        }

        .status-good {
            background-color: #e3f2fd;
            border: 2px solid #2196f3;
            color: #1565c0;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            text-align: center;
        }

        .status-warning {
            background-color: #fff3e0;
            border: 2px solid #ff9800;
            color: #e65100;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            text-align: center;
        }

        .status-critical {
            background-color: #ffebee;
            border: 2px solid #f44336;
            color: #c62828;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            text-align: center;
        }

        .page-break {
            page-break-after: always;
        }

        /* Normativa section */
        .normativa-section {
            background-color: #f0f8ff;
            border: 1px solid #4a90e2;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .normativa-table {
            background-color: white;
            margin: 10px 0;
        }

        /* Company final page */
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
    <!-- CONCLUSIONES -->
    <div class="section-title">4.- CONCLUSIONES</div>

    <div class="conclusion-highlight">
        <span class="big-number">{{ $stats['total_errors'] }}</span>
        <div class="description">
            defectos detectados en {{ $stats['total_images'] }} módulos analizados<br>
            <strong>Tasa de errores: {{ $stats['error_rate'] }}%</strong>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <span class="number">{{ $stats['images_clean'] }}</span>
            <span class="label">MÓDULOS SIN DEFECTOS</span>
        </div>
        <div class="stat-card">
            <span class="number">{{ $stats['images_with_errors'] }}</span>
            <span class="label">MÓDULOS CON DEFECTOS</span>
        </div>
    </div>

    <div class="subsection-title">Distribución de errores por tipo:</div>

    <table style="margin-bottom: 25px;">
        <thead>
        <tr>
            <th>Tipo de Error</th>
            <th>Cantidad</th>
            <th>Porcentaje</th>
            <th>Gravedad</th>
        </tr>
        </thead>
        <tbody>
        @foreach($stats['errors_by_type'] as $tipo => $count)
            <tr>
                <td><strong>{{ $tipo }}</strong></td>
                <td style="text-align: center;">{{ $count }}</td>
                <td style="text-align: center;">{{ $stats['total_errors'] > 0 ? round(($count / $stats['total_errors']) * 100, 1) : 0 }}%</td>
                <td style="text-align: center;">
                    @if(in_array($tipo, ['cell_crack', 'cell_burning', 'inactive_cell']))
                        <span style="color: #d32f2f; font-weight: bold;">ALTA</span>
                    @elseif(in_array($tipo, ['soldering_issue', 'corrosion']))
                        <span style="color: #f57c00; font-weight: bold;">MEDIA</span>
                    @else
                        <span style="color: #388e3c; font-weight: bold;">BAJA</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="subsection-title">Evaluación general del estado:</div>

    @php
        $criticalErrors = 0;
        $highErrors = 0;

        foreach($stats['errors_by_type'] as $tipo => $count) {
            if(in_array($tipo, ['cell_crack', 'cell_burning', 'inactive_cell', 'short_circuit'])) {
                $criticalErrors += $count;
            } elseif(in_array($tipo, ['soldering_issue', 'corrosion', 'diode_failure'])) {
                $highErrors += $count;
            }
        }

        $overallStatus = 'excellent';
        if($criticalErrors > 0) {
            $overallStatus = 'critical';
        } elseif($highErrors > 5) {
            $overallStatus = 'warning';
        } elseif($stats['error_rate'] > 10) {
            $overallStatus = 'good';
        }
    @endphp

    @if($overallStatus == 'excellent')
        <div class="status-excellent">
            <h3 style="margin: 0 0 10px 0;">✓ ESTADO EXCELENTE</h3>
            <p style="margin: 0;">No se han detectado defectos críticos. La instalación presenta un estado óptimo y un rendimiento esperado normal.</p>
        </div>
    @elseif($overallStatus == 'good')
        <div class="status-good">
            <h3 style="margin: 0 0 10px 0;">ℹ ESTADO BUENO</h3>
            <p style="margin: 0;">Se han detectado algunos defectos menores que requieren seguimiento pero no afectan significativamente al rendimiento actual.</p>
        </div>
    @elseif($overallStatus == 'warning')
        <div class="status-warning">
            <h3 style="margin: 0 0 10px 0;">⚠ REQUIERE ATENCIÓN</h3>
            <p style="margin: 0;">Se han detectado defectos que pueden afectar al rendimiento. Se recomienda evaluación y posible intervención programada.</p>
        </div>
    @else
        <div class="status-critical">
            <h3 style="margin: 0 0 10px 0;">🚨 ACCIÓN INMEDIATA REQUERIDA</h3>
            <p style="margin: 0;">Se han detectado defectos críticos que afectan significativamente al rendimiento. Se requiere intervención prioritaria.</p>
        </div>
    @endif

    <div class="subsection-title">Análisis comparativo con normativa:</div>

    <div class="normativa-section">
        <p><strong>Según la norma UNE 66020</strong> de inspecciones por lotes, nivel de inspección normal II, los valores de aceptación para diferentes niveles de calidad son:</p>

        <table class="normativa-table">
            <thead>
            <tr>
                <th>NCA (Nivel de Calidad Aceptable)</th>
                <th>Aceptación</th>
                <th>Rechazo</th>
                <th>Estado del Lote</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><strong>1.0</strong></td>
                <td>10</td>
                <td>11</td>
                <td>{{ $stats['total_errors'] <= 10 ? '✓ APROBADO' : '✗ RECHAZADO' }}</td>
            </tr>
            <tr>
                <td><strong>1.5</strong></td>
                <td>14</td>
                <td>15</td>
                <td>{{ $stats['total_errors'] <= 14 ? '✓ APROBADO' : '✗ RECHAZADO' }}</td>
            </tr>
            <tr style="background-color: #fff3cd;">
                <td><strong>2.5</strong></td>
                <td>21</td>
                <td>22</td>
                <td style="font-weight: bold;">{{ $stats['total_errors'] <= 21 ? '✓ APROBADO' : '✗ RECHAZADO' }}</td>
            </tr>
            </tbody>
        </table>

        <p><strong>Resultado:</strong> Para un nivel de calidad aceptable de 2.5 (utilizado normalmente en inspección de paneles fotovoltaicos), el lote debe ser
            <strong>{{ $stats['total_errors'] <= 21 ? 'APROBADO' : 'RECHAZADO' }}</strong>
            según la normativa.</p>
    </div>

    <div class="page-break"></div>

    <div class="subsection-title">Recomendaciones específicas:</div>

    <div class="recommendations">
        @if(count($recommendations) > 0)
            @foreach($recommendations as $recommendation)
                <div class="recommendation-item">{{ $recommendation }}</div>
            @endforeach
        @else
            <div class="recommendation-item">Continuar con el programa de mantenimiento preventivo actual.</div>
            <div class="recommendation-item">Realizar inspecciones de seguimiento cada 6-12 meses.</div>
            <div class="recommendation-item">Monitorear el rendimiento energético para detectar posibles degradaciones futuras.</div>
        @endif

        <div class="recommendation-item">Documentar todos los hallazgos para futuras referencias y comparaciones.</div>
        <div class="recommendation-item">Establecer un protocolo de inspección periódica utilizando técnicas de electroluminiscencia.</div>
    </div>

    <div class="subsection-title">Plan de acción recomendado:</div>

    @if($overallStatus == 'critical')
        <div class="recommendations">
            <div class="recommendation-item"><strong>INMEDIATO (0-30 días):</strong> Inspección detallada de módulos con errores críticos y evaluación de reemplazo.</div>
            <div class="recommendation-item"><strong>CORTO PLAZO (1-3 meses):</strong> Implementar reparaciones necesarias y mejorar protocolos de mantenimiento.</div>
            <div class="recommendation-item"><strong>MEDIO PLAZO (3-6 meses):</strong> Seguimiento intensivo del rendimiento y nueva inspección parcial.</div>
        </div>
    @elseif($overallStatus == 'warning')
        <div class="recommendations">
            <div class="recommendation-item"><strong>CORTO PLAZO (1-2 meses):</strong> Evaluación detallada de módulos con mayor número de defectos.</div>
            <div class="recommendation-item"><strong>MEDIO PLAZO (3-6 meses):</strong> Planificar mantenimiento correctivo para defectos identificados.</div>
            <div class="recommendation-item"><strong>LARGO PLAZO (6-12 meses):</strong> Nueva inspección completa para evaluar evolución.</div>
        </div>
    @else
        <div class="recommendations">
            <div class="recommendation-item"><strong>CORTO PLAZO (3-6 meses):</strong> Monitoreo del rendimiento energético para validar resultados.</div>
            <div class="recommendation-item"><strong>LARGO PLAZO (12-24 meses):</strong> Nueva inspección preventiva de electroluminiscencia.</div>
            <div class="recommendation-item"><strong>CONTINUO:</strong> Mantenimiento preventivo según recomendaciones del fabricante.</div>
        </div>
    @endif

    <div class="subsection-title">Metodología de seguimiento:</div>

    <div class="recommendations">
        <div class="recommendation-item">Implementar sistema de monitoreo de rendimiento energético por strings.</div>
        <div class="recommendation-item">Registrar datos de producción para detectar degradaciones anómalas.</div>
        <div class="recommendation-item">Programar inspecciones visuales trimestrales de los módulos identificados con defectos.</div>
        <div class="recommendation-item">Mantener registro fotográfico de la evolución de los defectos detectados.</div>
    </div>

    <div style="margin-top: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px;">
        <p style="margin: 0; text-align: center; font-size: 11px; color: #666;">
            <strong>Informe técnico generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }}</strong><br>
            Análisis realizado mediante técnicas de electroluminiscencia e inteligencia artificial<br>
            Proyecto: {{ $project->name }} | Total de módulos: {{ $stats['total_images'] }}
        </p>
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
        <p style="margin-top: 15px;">Especialistas en inspección y análisis de instalaciones fotovoltaicas</p>
        <p>Certificados en técnicas avanzadas de electroluminiscencia</p>
    </div>
</div>

</body>
</html>
