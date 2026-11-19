<?php
require_once 'config.php';
require_once 'database.php';

/**
 * Script de importación de datos desde Excel
 * 
 * Este script convierte los datos del Excel existente al formato de la base de datos
 */

// Datos de ejemplo del Excel proporcionado
// En producción, estos datos vendrían de un archivo CSV exportado o de la API de Google Sheets

$excelData = [
    [
        'Tipo' => 'Limpieza',
        'Fecha' => '2026-01-29',
        'Horas' => 6.0,
        'Importe por hora' => 12.0,
        'Total a pagar' => 72.0,
        'Pagado' => 78.0,
        'Saldo acumulado' => 6.0,
        'Notas' => '23/01/2026'
    ]
];

/**
 * Convertir datos del Excel al formato de la base de datos
 */
function convertExcelData($excelData) {
    $records = [];
    
    foreach ($excelData as $row) {
        // Crear registro de trabajo
        if (isset($row['Horas']) && $row['Horas'] > 0) {
            $records[] = [
                'type' => 'work',
                'service' => $row['Tipo'],
                'date' => $row['Fecha'],
                'hours' => floatval($row['Horas']),
                'rate' => floatval($row['Importe por hora']),
                'total' => floatval($row['Total a pagar']),
                'notes' => $row['Notas'] ?? null
            ];
        }
        
        // Crear registro de pago si hay cantidad pagada
        if (isset($row['Pagado']) && $row['Pagado'] > 0) {
            $records[] = [
                'type' => 'payment',
                'service' => $row['Tipo'],
                'date' => $row['Fecha'],
                'amount' => floatval($row['Pagado']),
                'notes' => 'Importado desde Excel'
            ];
        }
    }
    
    return $records;
}

// Ejecutar importación si se llama directamente
if (php_sapi_name() === 'cli' || isset($_GET['import'])) {
    try {
        $db = new Database();
        
        // Convertir datos
        $records = convertExcelData($excelData);
        
        // Importar
        $result = $db->importData($records);
        
        if (php_sapi_name() === 'cli') {
            echo "✅ Importación completada: {$result['imported']} registros importados\n";
        } else {
            jsonResponse($result);
        }
    } catch (Exception $e) {
        if (php_sapi_name() === 'cli') {
            echo "❌ Error: " . $e->getMessage() . "\n";
        } else {
            errorResponse($e->getMessage(), 500);
        }
    }
} else {
    // Mostrar formulario de importación
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Importar Datos - Gestión de Pagos</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 800px;
                margin: 50px auto;
                padding: 20px;
                background: #f5f5f5;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #2D5F5D;
            }
            .info {
                background: #e3f2fd;
                padding: 15px;
                border-left: 4px solid #2196F3;
                margin: 20px 0;
            }
            .btn {
                background: #2D5F5D;
                color: white;
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
                text-decoration: none;
                display: inline-block;
            }
            .btn:hover {
                background: #3A7876;
            }
            pre {
                background: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                overflow-x: auto;
            }
            .warning {
                background: #fff3e0;
                border-left: 4px solid #ff9800;
                padding: 15px;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>📥 Importar Datos desde Excel</h1>
            
            <div class="info">
                <strong>ℹ️ Información:</strong><br>
                Este script importará los datos de tu Excel existente a la base de datos.
            </div>
            
            <h2>Opción 1: Importar datos de ejemplo</h2>
            <p>Haz clic aquí para importar los datos que ya tienes en el Excel:</p>
            <a href="?import=1" class="btn">Importar Ahora</a>
            
            <h2 style="margin-top: 40px;">Opción 2: Importar desde CSV</h2>
            <div class="warning">
                <strong>⚠️ Próximamente:</strong><br>
                Para importar más datos:
                <ol>
                    <li>Exporta tu Google Sheet como CSV</li>
                    <li>Sube el archivo CSV aquí</li>
                    <li>Los datos se importarán automáticamente</li>
                </ol>
            </div>
            
            <h2 style="margin-top: 40px;">Formato esperado del CSV:</h2>
            <pre>Tipo,Fecha,Horas,Importe por hora,Total a pagar,Pagado,Notas
Limpieza,2026-01-29,6,12,72,78,Limpieza profunda</pre>
            
            <div style="margin-top: 30px;">
                <a href="../index.html" class="btn">← Volver a la aplicación</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
