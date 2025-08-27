<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 0;
            size: A4;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            color: white;
        }

        .cover-page {
            background-color: #ff6b35;
            height: 100vh;
            width: 100%;
            position: relative;
            padding: 50mm 40mm;
            box-sizing: border-box;
            color: white;
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
            margin-bottom: 20mm;
        }

        .cover-details {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15mm;
        }

        .cover-date {
            font-size: 16px;
            margin-bottom: 15mm;
        }

        .cover-stats {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 15mm;
            border-radius: 5px;
            margin-bottom: 20mm;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-label {
            font-weight: bold;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
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

        .company-info {
            position: absolute;
            bottom: 20mm;
            left: 40mm;
            font-size: 11px;
            line-height: 1.4;
            opacity: 0.9;
        }
    </style>
</head>
<body>

<div class="cover-page">
    <div class="cover-title">INFORME FINAL</div>
    <div class="cover-subtitle">INSPECCIÓN DE<br>ELECTROLUMINISCENCIA</div>
    <div class="cover-project">{{ strtoupper($project->name) }}</div>

    <div class="cover-details">
        @if($project->installation_name)
            <strong>Instalación:</strong> {{ $project->installation_name }}<br>
        @endif
        @if($project->inspector_name)
            <strong>Inspector:</strong> {{ $project->inspector_name }}<br>
        @endif
        @if($project->panel_brand)
            <strong>Marca de paneles:</strong> {{ $project->panel_brand }}<br>
        @endif
        @if($project->panel_model)
            <strong>Modelo:</strong> {{ $project->panel_model }}<br>
        @endif
    </div>

    <div class="cover-stats">
        <div class="stat-row">
            <span class="stat-label">Módulos analizados:</span>
            <span class="stat-value">{{ $totalImages }}</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Errores detectados:</span>
            <span class="stat-value">{{ $stats['total_errors'] }}</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Tasa de errores:</span>
            <span class="stat-value">{{ $stats['error_rate'] }}%</span>
        </div>
        <div class="stat-row">
            <span class="stat-label">Módulos afectados:</span>
            <span class="stat-value">{{ $stats['images_with_errors'] }}</span>
        </div>
    </div>

    <div class="cover-date">{{ $generatedAt->format('d/m/Y') }}</div>

    <div class="cover-logo">G</div>

    <div class="company-info">
        <strong>Gudnus - Efficient Engineering Solutions</strong><br>
        C/ Arte, 21, 28033 Madrid | 917 67 19 82 | info@gudnus.com
    </div>
</div>

</body>
</html>
