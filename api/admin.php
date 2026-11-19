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

// Archivo de configuración de Telegram
define('TELEGRAM_FILE', __DIR__ . '/../data/telegram.json');

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
        // uploadBackup uses multipart form data, not JSON
        if ($action === 'uploadBackup') {
            uploadBackup();
            exit;
        }
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

        case 'getBackups':
            $backupFiles = glob(BACKUP_DIR . 'backup_*.db');
            if (!$backupFiles) $backupFiles = [];
            usort($backupFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
            $backups = array_map(function($file) {
                return [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }, $backupFiles);
            jsonResponse(['success' => true, 'data' => $backups]);
            break;

        case 'checkIntegrity':
            checkIntegrity();
            break;

        case 'exportJSON':
            exportJSON();
            break;

        case 'diagnoseData':
            diagnoseData();
            break;

        case 'downloadBackup':
            $name = basename($_GET['name'] ?? '');
            if (!$name || !preg_match('/^backup_[\w\-]+\.db$/', $name)) {
                errorResponse('Nombre de backup no válido', 400);
            }
            $file = BACKUP_DIR . $name;
            if (!file_exists($file)) errorResponse('Backup no encontrado', 404);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
            break;

        case 'getTelegramConfig':
            getTelegramConfig();
            break;

        case 'getTheme':
            $themeFile = __DIR__ . '/../data/theme.json';
            $theme = file_exists($themeFile) ? json_decode(file_get_contents($themeFile), true) : null;
            jsonResponse(['success' => true, 'data' => $theme ?: null]);
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

        case 'deleteBackup':
            $name = basename($input['name'] ?? '');
            if (!$name || !preg_match('/^backup_[\w\-]+\.db$/', $name)) {
                errorResponse('Nombre de backup no válido', 400);
            }
            $file = BACKUP_DIR . $name;
            if (!file_exists($file)) errorResponse('Backup no encontrado', 404);
            if (unlink($file)) {
                jsonResponse(['success' => true, 'message' => 'Backup eliminado']);
            } else {
                errorResponse('Error al eliminar el backup', 500);
            }
            break;

        case 'importJSON':
            importJSON($input);
            break;

        case 'restoreBackup':
            $name = basename($input['name'] ?? '');
            if (!$name || !preg_match('/^backup_[\w\-]+\.db$/', $name)) {
                errorResponse('Nombre de backup no válido', 400);
            }
            $backupFile = BACKUP_DIR . $name;
            if (!file_exists($backupFile)) errorResponse('Backup no encontrado', 404);
            // Safety: create a backup of current DB before restoring
            $safetyName = BACKUP_DIR . 'backup_before_restore_' . date('Y-m-d_H-i-s') . '.db';
            copy(DB_PATH, $safetyName);
            // Restore
            if (copy($backupFile, DB_PATH)) {
                jsonResponse(['success' => true, 'message' => 'Base de datos restaurada correctamente', 'safety_backup' => basename($safetyName)]);
            } else {
                errorResponse('Error al restaurar la base de datos', 500);
            }
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

        case 'saveTelegramConfig':
            saveTelegramConfig($input);
            break;

        case 'testTelegram':
            testTelegram($input);
            break;

        case 'addTelegramNotification':
            addTelegramNotification($input);
            break;

        case 'updateTelegramNotification':
            updateTelegramNotification($input);
            break;

        case 'deleteTelegramNotification':
            deleteTelegramNotification($input);
            break;

        case 'installTelegramCron':
            installTelegramCron();
            break;

        case 'recalculateTotals':
            require_once 'database.php';
            $db = new Database();
            $result = $db->recalculateTotals();
            jsonResponse($result);
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
    
    $icon = isset($input['icon']) && !empty($input['icon']) ? trim($input['icon']) : null;
    $services[] = [
        'name' => $name,
        'rate' => $rate,
        'icon' => $icon
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
    $name    = $input['name'];
    $newName = isset($input['newName']) && !empty(trim($input['newName'])) ? trim($input['newName']) : $name;
    $rate    = isset($input['rate']) ? floatval($input['rate']) : null;
    $icon    = isset($input['icon']) && !empty($input['icon']) ? trim($input['icon']) : null;
    $found   = false;

    foreach ($services as &$service) {
        if ($service['name'] === $name) {
            $service['name'] = $newName;
            $service['rate'] = $rate;
            $service['icon'] = $icon;
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
 * Subir backup
 */
function uploadBackup() {
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'No se recibió ningún archivo válido']);
        return;
    }
    $file = $_FILES['backup'];
    // Validate: must be a SQLite DB (check magic bytes)
    $handle = fopen($file['tmp_name'], 'rb');
    $magic = fread($handle, 16);
    fclose($handle);
    if (strpos($magic, 'SQLite format 3') !== 0) {
        jsonResponse(['success' => false, 'error' => 'El archivo no es una base de datos SQLite válida']);
        return;
    }
    $destName = 'backup_uploaded_' . date('Y-m-d_H-i-s') . '.db';
    $dest = BACKUP_DIR . $destName;
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['success' => true, 'message' => 'Backup subido correctamente', 'name' => $destName]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Error al guardar el archivo']);
    }
}

/**
 * Verificar integridad de la base de datos
 */
function checkIntegrity() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $result = $db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        $foreignKeys = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        $ok = (count($result) === 1 && $result[0] === 'ok');
        jsonResponse([
            'success' => true,
            'ok' => $ok,
            'integrity' => $result,
            'foreign_keys' => $foreignKeys,
            'message' => $ok ? 'La base de datos está en perfecto estado' : 'Se encontraron problemas en la base de datos'
        ]);
    } catch (Exception $e) {
        errorResponse('Error al verificar integridad: ' . $e->getMessage(), 500);
    }
}

/**
 * Exportar todos los datos a JSON
 */
function exportJSON() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $records = $db->query('SELECT * FROM records ORDER BY date ASC, created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
        $services = file_exists(__DIR__ . '/../data/services.json')
            ? json_decode(file_get_contents(__DIR__ . '/../data/services.json'), true)
            : [];
        $theme = file_exists(__DIR__ . '/../data/theme.json')
            ? json_decode(file_get_contents(__DIR__ . '/../data/theme.json'), true)
            : null;

        $export = [
            'version'     => '1.0',
            'app'         => 'Gestion de Pagos',
            'exported_at' => date('Y-m-d H:i:s'),
            'services'    => $services,
            'records'     => $records,
            'theme'       => $theme
        ];

        $filename = 'gestion_pagos_export_' . date('Y-m-d_H-i-s') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        errorResponse('Error al exportar: ' . $e->getMessage(), 500);
    }
}

/**
 * Importar datos desde JSON
 */
function importJSON($input) {
    if (!isset($input['data'])) {
        errorResponse('No se recibieron datos', 400);
    }
    $data = $input['data'];

    // Validar estructura mínima
    if (!isset($data['records']) || !isset($data['services'])) {
        errorResponse('El archivo JSON no tiene el formato correcto', 400);
    }
    if (!isset($data['version']) || $data['app'] !== 'Gestion de Pagos') {
        errorResponse('El archivo no es un export válido de esta aplicación', 400);
    }

    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Safety backup antes de importar
        $safetyName = BACKUP_DIR . 'backup_before_import_' . date('Y-m-d_H-i-s') . '.db';
        copy(DB_PATH, $safetyName);

        $db->beginTransaction();

        // Limpiar registros actuales e insertar los del JSON
        $db->exec('DELETE FROM records');
        $stmt = $db->prepare('
            INSERT INTO records (type, service, date, hours, rate, total, amount, notes, created_at, updated_at)
            VALUES (:type, :service, :date, :hours, :rate, :total, :amount, :notes, :created_at, :updated_at)
        ');

        $imported = 0;
        foreach ($data['records'] as $r) {
            $stmt->execute([
                ':type'       => $r['type'],
                ':service'    => $r['service'],
                ':date'       => $r['date'],
                ':hours'      => $r['hours'] ?? null,
                ':rate'       => $r['rate'] ?? null,
                ':total'      => $r['total'] ?? null,
                ':amount'     => $r['amount'] ?? null,
                ':notes'      => $r['notes'] ?? null,
                ':created_at' => $r['created_at'] ?? date('Y-m-d H:i:s'),
                ':updated_at' => $r['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $imported++;
        }

        $db->commit();

        // Importar servicios
        file_put_contents(
            __DIR__ . '/../data/services.json',
            json_encode($data['services'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // Importar tema si existe
        if (!empty($data['theme'])) {
            file_put_contents(
                __DIR__ . '/../data/theme.json',
                json_encode($data['theme'], JSON_PRETTY_PRINT)
            );
        }

        jsonResponse([
            'success'        => true,
            'imported'       => $imported,
            'safety_backup'  => basename($safetyName),
            'message'        => "$imported registros importados correctamente"
        ]);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        errorResponse('Error al importar: ' . $e->getMessage(), 500);
    }
}

/**
 * Diagnóstico de datos
 */
function diagnoseData() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $services = file_exists(__DIR__ . '/../data/services.json')
            ? array_column(json_decode(file_get_contents(__DIR__ . '/../data/services.json'), true), 'name')
            : [];

        $issues = [];

        // Registros con servicio inexistente
        $records = $db->query('SELECT id, type, service, date FROM records')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($records as $r) {
            if (!in_array($r['service'], $services)) {
                $issues[] = ['type' => 'warning', 'msg' => "Registro #{$r['id']} ({$r['date']}) referencia al servicio \"{$r['service']}\" que ya no existe"];
            }
        }

        // Trabajos con horas o total = 0
        $zero = $db->query("SELECT id, service, date FROM records WHERE type='work' AND (hours IS NULL OR hours = 0 OR total IS NULL OR total = 0)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($zero as $r) {
            $issues[] = ['type' => 'warning', 'msg' => "Trabajo #{$r['id']} ({$r['service']}, {$r['date']}) tiene horas o total en 0"];
        }

        // Pagos con importe = 0
        $zeroPay = $db->query("SELECT id, service, date FROM records WHERE type='payment' AND (amount IS NULL OR amount = 0)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($zeroPay as $r) {
            $issues[] = ['type' => 'warning', 'msg' => "Pago #{$r['id']} ({$r['service']}, {$r['date']}) tiene importe 0"];
        }

        // Registros con fecha futura
        $future = $db->query("SELECT id, type, service, date FROM records WHERE date > date('now')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($future as $r) {
            $issues[] = ['type' => 'info', 'msg' => "Registro #{$r['id']} ({$r['service']}) tiene fecha futura: {$r['date']}"];
        }

        // Posibles duplicados (mismo tipo, servicio y fecha)
        $dups = $db->query("SELECT type, service, date, COUNT(*) as cnt FROM records GROUP BY type, service, date HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dups as $d) {
            $issues[] = ['type' => 'warning', 'msg' => "Posible duplicado: {$d['cnt']} registros de tipo \"{$d['type']}\" para \"{$d['service']}\" en fecha {$d['date']}"];
        }

        jsonResponse([
            'success' => true,
            'issues'  => $issues,
            'total'   => count($issues),
            'message' => count($issues) === 0 ? 'No se encontraron problemas en los datos' : count($issues) . ' incidencia(s) encontrada(s)'
        ]);
    } catch (Exception $e) {
        errorResponse('Error en diagnóstico: ' . $e->getMessage(), 500);
    }
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

// ============================================================
// TELEGRAM NOTIFICATIONS
// ============================================================

/**
 * Load Telegram config from file (returns defaults if missing)
 */
function loadTelegramConfig(): array {
    if (file_exists(TELEGRAM_FILE)) {
        $data = json_decode(file_get_contents(TELEGRAM_FILE), true);
        if (is_array($data)) return $data;
    }
    return ['token' => '', 'chat_id' => '', 'notifications' => []];
}

/**
 * Save Telegram config to file
 */
function saveTelegramConfigFile(array $cfg): bool {
    return (bool) file_put_contents(
        TELEGRAM_FILE,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * GET getTelegramConfig — returns config with masked token
 */
function getTelegramConfig(): void {
    $cfg = loadTelegramConfig();
    // Mask token: show only last 6 chars
    $maskedToken = '';
    if (!empty($cfg['token'])) {
        $len = strlen($cfg['token']);
        $maskedToken = str_repeat('•', max(0, $len - 6)) . substr($cfg['token'], -6);
    }
    jsonResponse([
        'success'       => true,
        'token_masked'  => $maskedToken,
        'has_token'     => !empty($cfg['token']),
        'chat_id'       => $cfg['chat_id'] ?? '',
        'notifications' => $cfg['notifications'] ?? [],
    ]);
}

/**
 * POST saveTelegramConfig — saves token + chat_id
 */
function saveTelegramConfig(array $input): void {
    $cfg = loadTelegramConfig();

    // Only update token if a real value was provided (not placeholder dots)
    if (isset($input['token']) && strpos($input['token'], '•') === false && $input['token'] !== '') {
        $cfg['token'] = trim($input['token']);
    }
    if (isset($input['chat_id'])) {
        $cfg['chat_id'] = trim($input['chat_id']);
    }

    if (saveTelegramConfigFile($cfg)) {
        jsonResponse(['success' => true, 'message' => 'Configuración de Telegram guardada']);
    } else {
        errorResponse('Error al guardar la configuración', 500);
    }
}

/**
 * POST testTelegram — sends a test message
 */
function testTelegram(array $input): void {
    $cfg = loadTelegramConfig();

    // Allow providing token/chat_id directly (if user just typed them)
    $token  = (isset($input['token'])   && strpos($input['token'],   '•') === false && $input['token']   !== '') ? trim($input['token'])   : $cfg['token'];
    $chatId = (isset($input['chat_id']) && $input['chat_id'] !== '')                                              ? trim($input['chat_id']) : $cfg['chat_id'];

    if (empty($token) || empty($chatId)) {
        errorResponse('Configura el Token y el Chat ID antes de enviar una prueba');
    }

    $text   = "🔔 <b>Gestión de Pagos</b> — Notificación de prueba ✅\n\nLa integración con Telegram funciona correctamente.";
    $result = callTelegramApi($token, $chatId, $text);

    if (!empty($result['ok'])) {
        jsonResponse(['success' => true, 'message' => 'Mensaje de prueba enviado correctamente']);
    } else {
        $err = $result['description'] ?? json_encode($result);
        errorResponse('Error de Telegram: ' . $err);
    }
}

/**
 * POST addTelegramNotification
 */
function addTelegramNotification(array $input): void {
    if (empty($input['time']) || empty($input['message']) || empty($input['days'])) {
        errorResponse('Hora, mensaje y días son obligatorios');
    }

    $cfg = loadTelegramConfig();

    $notif = [
        'id'      => bin2hex(random_bytes(8)),
        'days'    => array_values(array_map('intval', (array)$input['days'])),
        'time'    => preg_replace('/[^0-9:]/', '', $input['time']),
        'message' => substr(trim($input['message']), 0, 500),
        'enabled' => true,
    ];

    $cfg['notifications'][] = $notif;

    if (saveTelegramConfigFile($cfg)) {
        jsonResponse(['success' => true, 'message' => 'Notificación añadida', 'notification' => $notif]);
    } else {
        errorResponse('Error al guardar la notificación', 500);
    }
}

/**
 * POST updateTelegramNotification — toggle enabled or update fields
 */
function updateTelegramNotification(array $input): void {
    if (empty($input['id'])) errorResponse('ID de notificación requerido');

    $cfg = loadTelegramConfig();
    $found = false;

    foreach ($cfg['notifications'] as &$notif) {
        if ($notif['id'] === $input['id']) {
            if (isset($input['enabled']))  $notif['enabled'] = (bool)$input['enabled'];
            if (isset($input['days']))     $notif['days']    = array_values(array_map('intval', (array)$input['days']));
            if (isset($input['time']))     $notif['time']    = preg_replace('/[^0-9:]/', '', $input['time']);
            if (isset($input['message']))  $notif['message'] = substr(trim($input['message']), 0, 500);
            $found = true;
            break;
        }
    }
    unset($notif);

    if (!$found) errorResponse('Notificación no encontrada', 404);

    if (saveTelegramConfigFile($cfg)) {
        jsonResponse(['success' => true, 'message' => 'Notificación actualizada']);
    } else {
        errorResponse('Error al guardar', 500);
    }
}

/**
 * POST deleteTelegramNotification
 */
function deleteTelegramNotification(array $input): void {
    if (empty($input['id'])) errorResponse('ID de notificación requerido');

    $cfg = loadTelegramConfig();
    $before = count($cfg['notifications']);
    $cfg['notifications'] = array_values(
        array_filter($cfg['notifications'], fn($n) => $n['id'] !== $input['id'])
    );

    if (count($cfg['notifications']) === $before) errorResponse('Notificación no encontrada', 404);

    if (saveTelegramConfigFile($cfg)) {
        jsonResponse(['success' => true, 'message' => 'Notificación eliminada']);
    } else {
        errorResponse('Error al guardar', 500);
    }
}

/**
 * POST installTelegramCron — verifica o instala el cron de Telegram
 *
 * En Docker el cron ya está integrado en la imagen y arranca con el contenedor.
 * Esta función comprueba el estado e intenta instalarlo si falta.
 */
function installTelegramCron(): void {
    $cronFile = '/etc/cron.d/gestion-pagos';
    $cronLine = "* * * * * www-data php /var/www/html/api/telegram_notify.php >> /var/www/html/data/telegram_cron.log 2>&1\n";

    // ── Comprobar si el archivo cron.d ya existe (imagen Docker actualizada) ─
    if (file_exists($cronFile)) {
        // Comprobar si el demonio cron está corriendo
        $running = false;
        if (function_exists('exec')) {
            exec('pgrep -x cron 2>/dev/null', $out, $rc);
            $running = ($rc === 0 && !empty($out));
        }
        jsonResponse([
            'success' => true,
            'running' => $running,
            'message' => $running
                ? '✓ Cron integrado en Docker — ejecutándose correctamente'
                : 'El archivo cron existe pero el demonio no está activo. Reinicia el contenedor.',
        ]);
        return;
    }

    // ── Intentar escribir /etc/cron.d/ (requiere permisos) ───────────────────
    if (@file_put_contents($cronFile, $cronLine) !== false) {
        @chmod($cronFile, 0644);
        jsonResponse(['success' => true, 'running' => true, 'message' => '✓ Cron instalado en /etc/cron.d/gestion-pagos']);
        return;
    }

    // ── Sin permisos — recomendar actualizar la imagen ────────────────────────
    jsonResponse([
        'success' => false,
        'message' => 'Reconstruye la imagen Docker para incluir el cron integrado. Ver instrucciones en README.',
    ]);
}

/**
 * Call Telegram sendMessage API.
 * Tries cURL first; falls back to file_get_contents if cURL is unavailable.
 */
function callTelegramApi(string $token, string $chatId, string $text): array {
    $url     = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);

    // ── Method 1: cURL ────────────────────────────────────────────────────────
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp    = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'description' => "cURL error: {$curlErr}"];
        }
        return json_decode($resp, true) ?? ['ok' => false, 'description' => 'Respuesta JSON inválida de Telegram'];
    }

    // ── Method 2: file_get_contents fallback ──────────────────────────────────
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            return ['ok' => false, 'description' => 'file_get_contents falló. Comprueba la conectividad del servidor con api.telegram.org'];
        }
        return json_decode($resp, true) ?? ['ok' => false, 'description' => 'Respuesta JSON inválida de Telegram'];
    }

    // ── Neither available ─────────────────────────────────────────────────────
    return [
        'ok'          => false,
        'description' => 'Ni cURL ni allow_url_fopen están disponibles. Instala php-curl: apt-get install php8.2-curl && service apache2 restart',
    ];
}

// ============================================================

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
        $db = null; // Cerrar conexión antes de responder

        jsonResponse(['success' => true, 'deleted' => $count, 'message' => "Se eliminaron $count registros. Usa 'Optimizar Base de Datos' para recuperar espacio."]);
    } catch (Exception $e) {
        errorResponse('Error al eliminar registros: ' . $e->getMessage(), 500);
    }
}

