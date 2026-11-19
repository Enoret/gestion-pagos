<?php
/**
 * Documentación del servidor MCP, generada a partir de las herramientas reales.
 * Pública (sin datos): solo nombres, descripciones y parámetros.
 *   /api/mcp-docs                 → JSON (para la app)
 *   /api/mcp-docs?format=markdown → Markdown (para pasársela a un agente)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mcp_tools.php';

function mcp_docs_type(array $p): string {
    if (isset($p['enum'])) return implode(' | ', array_map(fn($v) => "\"$v\"", $p['enum']));
    return $p['type'] ?? 'any';
}

function mcp_docs_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

$base = mcp_docs_base_url();
$tools = [];
foreach (mcp_tool_definitions() as $t) {
    $props = (array) ($t['inputSchema']['properties'] ?? []);
    $required = $t['inputSchema']['required'] ?? [];
    $params = [];
    foreach ($props as $name => $p) {
        $params[] = [
            'name' => $name,
            'type' => mcp_docs_type($p),
            'required' => in_array($name, $required, true),
            'default' => $p['default'] ?? null,
            'description' => $p['description'] ?? '',
        ];
    }
    $tools[] = [
        'name' => $t['name'],
        'permission' => $t['scope'] === 'write' ? 'lectura y escritura' : 'lectura',
        'description' => $t['description'],
        'parameters' => $params,
    ];
}

$docs = [
    'endpoint'     => "$base/api/mcp",
    'transport'    => 'streamable-http (sin estado, respuestas JSON)',
    'auth'         => 'Cabecera X-API-Key: gp_… (o Authorization: Bearer gp_…)',
    'version'      => APP_RELEASE,
    'instructions' => MCP_INSTRUCTIONS,
    'config'       => ['mcpServers' => ['gestion-pagos' => [
        'type' => 'http', 'url' => "$base/api/mcp", 'headers' => ['X-API-Key' => 'gp_…'],
    ]]],
    'tools'        => $tools,
];

if (($_GET['format'] ?? 'json') !== 'markdown') {
    jsonResponse($docs);
}

header('Content-Type: text/markdown; charset=utf-8');
$l = [
    '# Gestión de Pagos — servidor MCP',
    '',
    "- Endpoint: `{$docs['endpoint']}` ({$docs['transport']})",
    "- Autenticación: {$docs['auth']}",
    '- Las API keys se crean en el panel de administración → *Agentes IA (MCP)*. Permisos: *lectura* o *lectura y escritura*.',
    '',
    '## Configuración del cliente',
    '',
    '```json',
    json_encode($docs['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    '```',
    '',
    '## Instrucciones para el agente',
    '',
    $docs['instructions'],
    '',
    '## Herramientas',
    '',
];
foreach ($tools as $t) {
    $l[] = "### `{$t['name']}` · {$t['permission']}";
    $l[] = $t['description'];
    $l[] = '';
    if (!$t['parameters']) $l[] = '_Sin parámetros._';
    foreach ($t['parameters'] as $p) {
        $req = $p['required'] ? 'obligatorio' : ('opcional' . ($p['default'] !== null ? ' (por defecto: ' . json_encode($p['default']) . ')' : ''));
        $l[] = "- `{$p['name']}`: {$p['type']} — $req. {$p['description']}";
    }
    $l[] = '';
}
echo implode("\n", $l);
