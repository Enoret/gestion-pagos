<?php
/**
 * Servidor MCP de Gestión de Pagos — Streamable HTTP en /api/mcp (sin estado, respuestas JSON).
 *
 * Autenticación: cabecera X-API-Key: gp_… (o Authorization: Bearer gp_…).
 * Las keys se crean y revocan en el panel de administración (sección "Agentes IA (MCP)").
 * Las herramientas están en mcp_tools.php; la documentación en /api/mcp-docs.
 */

define('GP_THROW_ERRORS', true);
require_once __DIR__ . '/config.php';   // zona horaria, helpers
require_once __DIR__ . '/apikeys.php';
require_once __DIR__ . '/mcp_tools.php';

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

header_remove('Access-Control-Allow-Origin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mcp_send(int $status, $body = null): void {
    http_response_code($status);
    if ($body !== null) echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_error($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

// ── Método HTTP ──────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') mcp_send(204);
if ($method !== 'POST') {
    // Sin SSE ni sesiones: solo POST (permitido por la especificación Streamable HTTP)
    header('Allow: POST');
    mcp_send(405, mcp_error(null, -32000, 'Método no permitido: usa POST (Streamable HTTP, sin estado)'));
}

// ── Autenticación ────────────────────────────────────────────────────────────
$key = apikeys_verify(apikeys_from_request());
if (!$key) {
    mcp_send(401, mcp_error(null, -32001, 'API key no válida o revocada (cabecera X-API-Key: gp_…)'));
}

// ── Mensajes ─────────────────────────────────────────────────────────────────
$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) mcp_send(400, mcp_error(null, -32700, 'JSON no válido'));

$isBatch = array_is_list($payload) && $payload !== [];
$messages = $isBatch ? $payload : [$payload];

function mcp_handle(array $msg, array $key): ?array {
    $id = $msg['id'] ?? null;
    $isNotification = !array_key_exists('id', $msg);
    $m = $msg['method'] ?? null;

    if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($m)) {
        // Respuestas del cliente (no las usamos) o mensajes mal formados
        return $isNotification ? null : mcp_error($id, -32600, 'Petición JSON-RPC no válida');
    }
    if ($isNotification) return null; // notifications/initialized, cancelled…

    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($m) {
        case 'initialize': {
            $requested = $params['protocolVersion'] ?? MCP_PROTOCOL_VERSIONS[0];
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($requested, MCP_PROTOCOL_VERSIONS, true) ? $requested : MCP_PROTOCOL_VERSIONS[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'gestion-pagos', 'title' => 'Gestión de Pagos', 'version' => APP_RELEASE],
                'instructions'    => MCP_INSTRUCTIONS,
            ]];
        }

        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];

        case 'tools/list': {
            // Solo las herramientas que esta key puede usar
            $tools = [];
            foreach (mcp_tool_definitions() as $t) {
                if ($t['scope'] === 'write' && $key['scope'] !== 'write') continue;
                unset($t['scope']);
                $tools[] = $t;
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]];
        }

        case 'tools/call': {
            $name = $params['name'] ?? '';
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $data = mcp_call_tool((string) $name, $args, $key);
                $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'structuredContent' => $data,
                    'isError' => false,
                ]];
            } catch (McpToolError | RuntimeException | InvalidArgumentException $e) {
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                    'isError' => true,
                ]];
            } catch (Throwable $e) {
                error_log('MCP ' . $name . ': ' . $e->getMessage());
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'Error interno al ejecutar la herramienta']],
                    'isError' => true,
                ]];
            }
        }

        case 'resources/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
        case 'prompts/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];

        default:
            return mcp_error($id, -32601, "Método no soportado: $m");
    }
}

$responses = [];
foreach ($messages as $msg) {
    $r = is_array($msg) ? mcp_handle($msg, $key) : mcp_error(null, -32600, 'Petición JSON-RPC no válida');
    if ($r !== null) $responses[] = $r;
}

if (!$responses) mcp_send(202);                 // solo notificaciones
mcp_send(200, $isBatch ? $responses : $responses[0]);
