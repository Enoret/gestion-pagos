<?php
/**
 * Configuración de la aplicación de Gestión de Pagos
 */

// Configuración de base de datos
define('DB_PATH', __DIR__ . '/../data/pagos.db');
define('BACKUP_DIR', __DIR__ . '/../data/backups/');

// Configuración de la aplicación
define('APP_NAME', 'Gestión de Pagos');
define('APP_VERSION', '1.0.0');

// Configuración de backups
define('BACKUP_ENABLED', true);
define('BACKUP_FREQUENCY', 'weekly'); // daily, weekly, monthly

// Tipos de servicios disponibles
define('SERVICES', ['Limpieza', 'Jardinero']);

// Configuración de seguridad
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Timezone
date_default_timezone_set('Europe/Madrid');

/**
 * Función auxiliar para responder JSON
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Función auxiliar para errores
 */
function errorResponse($message, $statusCode = 400) {
    jsonResponse([
        'success' => false,
        'error' => $message
    ], $statusCode);
}

/**
 * Validar entrada
 */
function validateInput($data, $required = []) {
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            errorResponse("El campo '$field' es obligatorio");
        }
    }
    return true;
}
