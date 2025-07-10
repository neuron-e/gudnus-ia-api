<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Informe Listo</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f8f9fa; }
        .download-section { background: white; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .download-button {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
        }
        .footer { font-size: 12px; color: #7f8c8d; text-align: center; margin-top: 20px; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎉 Tu informe está listo</h1>
    </div>

    <div class="content">
        <h2>Hola,</h2>

        <p>Tu informe de electroluminiscencia para el proyecto <strong>{{ $projectName }}</strong> ha sido generado exitosamente.</p>

        <p><strong>Detalles del informe:</strong></p>
        <ul>
            <li>Total de imágenes analizadas: <strong>{{ $totalImages }}</strong></li>
            <li>Fecha de generación: <strong>{{ now()->format('d/m/Y H:i') }}</strong></li>
            <li>Válido hasta: <strong>{{ $expiresAt->format('d/m/Y H:i') }}</strong></li>
        </ul>

        <div class="download-section">
            <h3>📥 Descargar informe</h3>
            @if(count($downloadUrls) === 1)
                <a href="{{ $downloadUrls[0] }}" class="download-button">Descargar PDF</a>
            @else
                <p>Tu informe se ha dividido en {{ count($downloadUrls) }} partes:</p>
                @foreach($downloadUrls as $index => $url)
                    <a href="{{ $url }}" class="download-button">Parte {{ $index + 1 }}</a>
                @endforeach
            @endif
        </div>

        <div class="warning">
            <strong>⚠️ Importante:</strong> Este informe estará disponible hasta el {{ $expiresAt->format('d/m/Y H:i') }}.
            Después de esa fecha será eliminado automáticamente.
        </div>

        <p>Si tienes algún problema para descargar el informe, contáctanos.</p>
    </div>

    <div class="footer">
        <p>Sistema de Análisis de Electroluminiscencia</p>
        <p>Este es un email automático, no responder.</p>
    </div>
</div>
</body>
</html>
