<?php
require_once 'config.php';

// Manejar peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = isset($_GET['action']) ? $_GET['action'] : ($input['action'] ?? null);

// Archivo de configuración de servicios
$servicesFile = __DIR__ . '/../data/services.json';

// Inicializar archivo de servicios si no existe
if (!file_exists($servicesFile)) {
    $defaultServices = [
        ['name' => 'Limpieza', 'rate' => 12],
        ['name' => 'Jardinero', 'rate' => 15]
    ];
    file_put_contents($servicesFile, json_encode($defaultServices, JSON_PRETTY_PRINT));
}

/**
 * Cargar servicios
 */
function loadServices() {
    global $servicesFile;
    if (file_exists($servicesFile)) {
        return json_decode(file_get_contents($servicesFile), true) ?: [];
    }
    return [];
}

/**
 * Guardar servicios
 */
function saveServices($services) {
    global $servicesFile;
    return file_put_contents($servicesFile, json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Router
 */
switch ($method) {
    case 'GET':
        handleGet($action);
        break;
    
    case 'POST':
        handlePost($action, $input);
        break;
    
    default:
        errorResponse('Método no permitido', 405);
}

/**
 * Manejar peticiones GET
 */
function handleGet($action) {
    switch ($action) {
        case 'getServices':
            $services = loadServices();
            jsonResponse(['success' => true, 'data' => $services]);
            break;
        
        case 'getStats':
            $stats = getSystemStats();
            jsonResponse(['success' => true, 'data' => $stats]);
            break;
        
        default:
            errorResponse('Acción no encontrada', 404);
    }
}

/**
 * Manejar peticiones POST
 */
function handlePost($action, $input) {
    switch ($action) {
        case 'addService':
            addService($input);
            break;
        
        case 'updateService':
            updateService($input);
            break;
        
        case 'deleteService':
            deleteService($input);
            break;
        
        case 'optimizeDB':
            optimizeDatabase();
            break;
        
        case 'cleanBackups':
            cleanBackups();
            break;
        
        case 'saveTheme':
            saveTheme($input);
            break;
        
        case 'deleteTheme':
            deleteTheme();
            break;

        case 'deleteAllRecords':
            deleteAllRecords();
            break;

        default:
            errorResponse('Acción no encontrada', 404);
    }
}

/**
 * Añadir servicio
 */
function addService($input) {
    if (!isset($input['name']) || empty(trim($input['name']))) {
        errorResponse('El nombre del servicio es obligatorio');
    }
    
    $services = loadServices();
    $name = trim($input['name']);
    $rate = isset($input['rate']) ? floatval($input['rate']) : null;
    
    // Verificar que no exista ya
    foreach ($services as $service) {
        if (strcasecmp($service['name'], $name) === 0) {
            errorResponse('El servicio ya existe');
        }
    }
    
    $services[] = [
        'name' => $name,
        'rate' => $rate
    ];
    
    if (saveServices($services)) {
        jsonResponse(['success' => true, 'message' => 'Servicio añadido correctamente']);
    } else {
        errorResponse('Error al guardar el servicio', 500);
    }
}

/**
 * Actualizar servicio
 */
function updateService($input) {
    if (!isset($input['name'])) {
        errorResponse('El nombre del servicio es obligatorio');
    }
    
    $services = loadServices();
    $name = $input['name'];
    $rate = isset($input['rate']) ? floatval($input['rate']) : null;
    $found = false;
    
    foreach ($services as &$service) {
        if ($service['name'] === $name) {
            $service['rate'] = $rate;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        errorResponse('Servicio no encontrado', 404);
    }
    
    if (saveServices($services)) {
        jsonResponse(['success' => true, 'message' => 'Servicio actualizado']);
    } else {
        errorResponse('Error al actualizar el servicio', 500);
    }
}

/**
 * Eliminar servicio
 */
function deleteService($input) {
    if (!isset($input['name'])) {
        errorResponse('El nombre del servicio es obligatorio');
    }
    
    $services = loadServices();
    $name = $input['name'];
    $newServices = [];
    $found = false;
    
    foreach ($services as $service) {
        if ($service['name'] !== $name) {
            $newServices[] = $service;
        } else {
            $found = true;
        }
    }
    
    if (!$found) {
        errorResponse('Servicio no encontrado', 404);
    }
    
    if (saveServices($newServices)) {
        jsonResponse(['success' => true, 'message' => 'Servicio eliminado']);
    } else {
        errorResponse('Error al eliminar el servicio', 500);
    }
}

/**
 * Obtener estadísticas del sistema
 */
function getSystemStats() {
    $stats = [];
    
    // Contar registros
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $stmt = $db->query('SELECT COUNT(*) as count FROM records');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['totalRecords'] = $result['count'];
    } catch (Exception $e) {
        $stats['totalRecords'] = 0;
    }
    
    // Tamaño de la base de datos
    if (file_exists(DB_PATH)) {
        $stats['dbSize'] = filesize(DB_PATH);
    } else {
        $stats['dbSize'] = 0;
    }
    
    // Número de backups
    $backupFiles = glob(BACKUP_DIR . 'backup_*.db');
    $stats['totalBackups'] = count($backupFiles);
    
    return $stats;
}

/**
 * Optimizar base de datos
 */
function optimizeDatabase() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->exec('VACUUM');
        $db->exec('ANALYZE');
        jsonResponse(['success' => true, 'message' => 'Base de datos optimizada']);
    } catch (Exception $e) {
        errorResponse('Error al optimizar: ' . $e->getMessage(), 500);
    }
}

/**
 * Limpiar backups antiguos
 */
function cleanBackups() {
    $backupFiles = glob(BACKUP_DIR . 'backup_*.db');
    
    if (count($backupFiles) <= 5) {
        jsonResponse(['success' => true, 'deleted' => 0, 'message' => 'No hay backups para eliminar']);
    }
    
    // Ordenar por fecha (más recientes primero)
    usort($backupFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    // Mantener los últimos 5, eliminar el resto
    $toDelete = array_slice($backupFiles, 5);
    $deleted = 0;
    
    foreach ($toDelete as $file) {
        if (unlink($file)) {
            $deleted++;
        }
    }
    
    jsonResponse(['success' => true, 'deleted' => $deleted, 'message' => "$deleted backups eliminados"]);
}

/**
 * Guardar tema personalizado
 */
function saveTheme($input) {
    if (!isset($input['theme'])) {
        errorResponse('Datos del tema no proporcionados');
    }
    
    $themeFile = __DIR__ . '/../data/theme.json';
    $theme = $input['theme'];
    
    if (file_put_contents($themeFile, json_encode($theme, JSON_PRETTY_PRINT))) {
        jsonResponse(['success' => true, 'message' => 'Tema guardado correctamente']);
    } else {
        errorResponse('Error al guardar el tema', 500);
    }
}

/**
 * Eliminar tema personalizado
 */
function deleteTheme() {
    $themeFile = __DIR__ . '/../data/theme.json';

    if (file_exists($themeFile)) {
        if (unlink($themeFile)) {
            jsonResponse(['success' => true, 'message' => 'Tema eliminado']);
        } else {
            errorResponse('Error al eliminar el tema', 500);
        }
    } else {
        jsonResponse(['success' => true, 'message' => 'No hay tema personalizado']);
    }
}

/**
 * Eliminar todos los registros (trabajos y pagos)
 */
function deleteAllRecords() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->query('SELECT COUNT(*) as count FROM records');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['count'];

        $db->exec('DELETE FROM records');
        $db->exec('VACUUM');

        jsonResponse(['success' => true, 'deleted' => $count, 'message' => "Se eliminaron $count registros"]);
    } catch (Exception $e) {
        errorResponse('Error al eliminar registros: ' . $e->getMessage(), 500);
    }
}

