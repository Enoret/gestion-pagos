<?php
require_once 'config.php';
require_once 'database.php';

// Manejar peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = new Database();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Obtener parámetros de la URL
$path = isset($_GET['path']) ? $_GET['path'] : '';

/**
 * Router de la API
 */
switch ($method) {
    case 'GET':
        handleGet($db, $path);
        break;
    
    case 'POST':
        handlePost($db, $path, $input);
        break;
    
    case 'PUT':
        handlePut($db, $path, $input);
        break;
    
    case 'DELETE':
        handleDelete($db, $path);
        break;
    
    default:
        errorResponse('Método no permitido', 405);
}

/**
 * Manejar peticiones GET
 */
function handleGet($db, $path) {
    switch ($path) {
        case 'records':
            $service = $_GET['service'] ?? null;
            $type = $_GET['type'] ?? null;
            $records = $db->getAllRecords($service, $type);
            jsonResponse(['success' => true, 'data' => $records]);
            break;
        
        case 'stats':
            $service = $_GET['service'] ?? null;
            $stats = $db->getStats($service);
            jsonResponse(['success' => true, 'data' => $stats]);
            break;
        
        case 'backup':
            $result = $db->createBackup();
            jsonResponse($result);
            break;
        
        default:
            // Obtener registro por ID
            if (preg_match('/^record\/(\d+)$/', $path, $matches)) {
                $record = $db->getRecord($matches[1]);
                if ($record) {
                    jsonResponse(['success' => true, 'data' => $record]);
                } else {
                    errorResponse('Registro no encontrado', 404);
                }
            } else {
                errorResponse('Endpoint no encontrado', 404);
            }
    }
}

/**
 * Manejar peticiones POST
 */
function handlePost($db, $path, $input) {
    switch ($path) {
        case 'work':
            $record = $db->createWorkRecord($input);
            jsonResponse(['success' => true, 'data' => $record], 201);
            break;
        
        case 'payment':
            $record = $db->createPaymentRecord($input);
            jsonResponse(['success' => true, 'data' => $record], 201);
            break;
        
        case 'import':
            if (!isset($input['records']) || !is_array($input['records'])) {
                errorResponse('Se requiere un array de registros');
            }
            $result = $db->importData($input['records']);
            jsonResponse($result);
            break;
        
        default:
            errorResponse('Endpoint no encontrado', 404);
    }
}

/**
 * Manejar peticiones PUT
 */
function handlePut($db, $path, $input) {
    if (preg_match('/^record\/(\d+)$/', $path, $matches)) {
        $record = $db->updateRecord($matches[1], $input);
        jsonResponse(['success' => true, 'data' => $record]);
    } else {
        errorResponse('Endpoint no encontrado', 404);
    }
}

/**
 * Manejar peticiones DELETE
 */
function handleDelete($db, $path) {
    if (preg_match('/^record\/(\d+)$/', $path, $matches)) {
        $result = $db->deleteRecord($matches[1]);
        jsonResponse($result);
    } else {
        errorResponse('Endpoint no encontrado', 404);
    }
}
