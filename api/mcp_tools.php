<?php
/**
 * Herramientas del servidor MCP de Gestión de Pagos.
 *
 * Fuente única: el servidor (api/mcp.php) y la documentación (api/mcp_docs.php)
 * se generan a partir de mcp_tool_definitions(). Cada herramienta reutiliza la
 * clase Database, así que aplica las mismas reglas que la web.
 */

require_once __DIR__ . '/database.php';

const MCP_INSTRUCTIONS = <<<TXT
Gestión de Pagos: horas trabajadas y pagos de servicios del hogar (p. ej. la limpieza).
- Cada servicio (p. ej. "Limpieza") tiene personas (p. ej. "Isis"), cada una con su tarifa y su propio saldo.
  Usa list_workers para verlas. Si el usuario nombra a la persona, pásala en worker.
- Registra horas con log_work y pagos con log_payment. El saldo = pagado − trabajado:
  negativo = pendiente de pagar; positivo = pagado por adelantado (a favor).
- Si no se indica la persona, se usa la única persona activa del servicio; si hay varias, pregunta cuál.
- El servicio se indica por nombre ("Limpieza"); si solo hay uno, se puede omitir.
- Fechas en formato AAAA-MM-DD (hora de Madrid); si no se indica, es hoy. No se aceptan fechas futuras.
- Si no se indica la tarifa, se usa la de la persona (o la del servicio).
- get_balance sin filtros da el saldo global y el desglose por persona.
- Si un registro parece duplicado (mismo servicio, fecha e importe/horas) se rechaza:
  pregunta al usuario y, si lo confirma, repite con allow_duplicate=true.
- Para corregir o eliminar, busca antes el id con list_records.
Responde al usuario confirmando lo que has hecho con los datos devueltos (incluido el saldo).
TXT;

class McpToolError extends RuntimeException {}

// ── Definiciones ──────────────────────────────────────────────────────────────
function mcp_tool_definitions(): array {
    $service = ['type' => 'string', 'description' => 'Nombre del servicio (p. ej. "Limpieza"). Opcional si solo hay uno.'];
    $worker  = ['type' => 'string', 'description' => 'Nombre de la persona (p. ej. "Isis"). Por defecto: la única persona activa del servicio.'];
    $workerF = ['type' => 'string', 'description' => 'Filtrar por persona (p. ej. "Isis").'];
    $date    = ['type' => 'string', 'description' => 'Fecha AAAA-MM-DD. Por defecto: hoy.'];
    $notes   = ['type' => 'string', 'description' => 'Nota opcional (máx. 500 caracteres).'];
    $dup     = ['type' => 'boolean', 'default' => false, 'description' => 'true para registrar aunque parezca duplicado (solo si el usuario lo confirma).'];

    return [
        [
            'name' => 'list_services',
            'scope' => 'read',
            'description' => 'Lista los servicios configurados con su tarifa por hora.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'annotations' => ['title' => 'Servicios', 'readOnlyHint' => true],
        ],
        [
            'name' => 'list_workers',
            'scope' => 'read',
            'description' => 'Lista las personas (p. ej. la chica de la limpieza) con su servicio, tarifa, si está activa o de baja, y su saldo individual.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'service' => ['type' => 'string', 'description' => 'Filtrar por servicio.'],
                'include_inactive' => ['type' => 'boolean', 'default' => true, 'description' => 'Incluir personas dadas de baja.'],
            ]],
            'annotations' => ['title' => 'Personas', 'readOnlyHint' => true],
        ],
        [
            'name' => 'get_balance',
            'scope' => 'read',
            'description' => 'Saldo actual (pagado − trabajado), totales de horas, trabajado y pagado, y último trabajo y pago. Por persona, por servicio o global (sin filtros, con desglose por persona).',
            'inputSchema' => ['type' => 'object', 'properties' => ['worker' => $workerF, 'service' => $service]],
            'annotations' => ['title' => 'Saldo', 'readOnlyHint' => true],
        ],
        [
            'name' => 'list_records',
            'scope' => 'read',
            'description' => 'Movimientos (trabajos y pagos), del más reciente al más antiguo, con el saldo tras cada uno. Úsalo para obtener el id antes de corregir o eliminar.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'worker'    => $workerF,
                'service'   => $service,
                'type'      => ['type' => 'string', 'enum' => ['work', 'payment'], 'description' => 'Solo trabajos (work) o solo pagos (payment).'],
                'date_from' => ['type' => 'string', 'description' => 'Desde (AAAA-MM-DD, incluido).'],
                'date_to'   => ['type' => 'string', 'description' => 'Hasta (AAAA-MM-DD, incluido).'],
                'limit'     => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200, 'description' => 'Máximo de movimientos (1-200).'],
            ]],
            'annotations' => ['title' => 'Movimientos', 'readOnlyHint' => true],
        ],
        [
            'name' => 'monthly_summary',
            'scope' => 'read',
            'description' => 'Resumen por mes: horas, trabajado, pagado y balance del mes, de los últimos N meses (incluido el actual).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'worker'  => $workerF,
                'service' => $service,
                'months'  => ['type' => 'integer', 'default' => 12, 'minimum' => 1, 'maximum' => 60, 'description' => 'Número de meses (1-60).'],
            ]],
            'annotations' => ['title' => 'Resumen mensual', 'readOnlyHint' => true],
        ],
        [
            'name' => 'log_work',
            'scope' => 'write',
            'description' => 'Registra horas trabajadas. Coste = horas × tarifa. Devuelve el registro y el saldo resultante.',
            'inputSchema' => ['type' => 'object', 'required' => ['hours'], 'properties' => [
                'hours'   => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24, 'description' => 'Horas trabajadas (p. ej. 3 o 2.5).'],
                'worker'  => $worker,
                'date'    => $date,
                'service' => $service,
                'rate'    => ['type' => 'number', 'minimum' => 0, 'description' => 'Tarifa €/hora. Por defecto: la de la persona.'],
                'notes'   => $notes,
                'allow_duplicate' => $dup,
            ]],
            'annotations' => ['title' => 'Registrar horas', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ],
        [
            'name' => 'log_payment',
            'scope' => 'write',
            'description' => 'Registra un pago realizado. Devuelve el registro y el saldo resultante.',
            'inputSchema' => ['type' => 'object', 'required' => ['amount'], 'properties' => [
                'amount'  => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100000, 'description' => 'Importe pagado en euros.'],
                'worker'  => $worker,
                'date'    => $date,
                'service' => $service,
                'notes'   => $notes,
                'allow_duplicate' => $dup,
            ]],
            'annotations' => ['title' => 'Registrar pago', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
        ],
        [
            'name' => 'update_record',
            'scope' => 'write',
            'description' => 'Corrige un movimiento existente por id. Solo cambia los campos indicados (en trabajos se recalcula el coste).',
            'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                'id'      => ['type' => 'integer', 'description' => 'Id del movimiento (ver list_records).'],
                'date'    => ['type' => 'string', 'description' => 'Nueva fecha AAAA-MM-DD.'],
                'worker'  => ['type' => 'string', 'description' => 'Asignar a otra persona (cambia también el servicio).'],
                'service' => ['type' => 'string', 'description' => 'Nuevo servicio.'],
                'hours'   => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24, 'description' => 'Nuevas horas (solo trabajos).'],
                'rate'    => ['type' => 'number', 'minimum' => 0, 'description' => 'Nueva tarifa €/h (solo trabajos).'],
                'amount'  => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Nuevo importe (solo pagos).'],
                'notes'   => ['type' => 'string', 'description' => 'Nueva nota ("" para borrarla).'],
            ]],
            'annotations' => ['title' => 'Corregir movimiento', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        ],
        [
            'name' => 'delete_record',
            'scope' => 'write',
            'description' => 'Elimina un movimiento por id. Confirma antes con el usuario.',
            'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id del movimiento (ver list_records).'],
            ]],
            'annotations' => ['title' => 'Eliminar movimiento', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
        ],
    ];
}

// ── Utilidades ───────────────────────────────────────────────────────────────
function mcp_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return $t !== false ? preg_replace('/[^a-z0-9 ]/', '', $t) : $s;
}

function mcp_services(): array {
    $f = __DIR__ . '/../data/services.json';
    $s = file_exists($f) ? json_decode((string) file_get_contents($f), true) : [];
    return is_array($s) ? array_values(array_filter($s, fn($x) => !empty($x['name']))) : [];
}

/** Resuelve un servicio por nombre (sin mayúsculas/acentos). Devuelve el array del servicio. */
function mcp_service(?string $name, bool $required = true): ?array {
    $services = mcp_services();
    $names = implode(', ', array_column($services, 'name')) ?: '(ninguno)';
    if ($name === null || trim($name) === '') {
        if (!$required) return null;
        if (count($services) === 1) return $services[0];
        throw new McpToolError("Indica el servicio. Disponibles: $names");
    }
    foreach ($services as $s) if (mcp_norm($s['name']) === mcp_norm($name)) return $s;
    foreach ($services as $s) if (str_contains(mcp_norm($s['name']), mcp_norm($name))) return $s;
    throw new McpToolError("Servicio \"$name\" no encontrado. Disponibles: $names");
}

/** Resuelve una persona por nombre (sin mayúsculas/acentos). */
function mcp_worker_by_name(Database $db, string $name): array {
    $all = $db->getWorkers();
    foreach ($all as $w) if (mcp_norm($w['name']) === mcp_norm($name)) return $w;
    foreach ($all as $w) if (str_contains(mcp_norm($w['name']), mcp_norm($name))) return $w;
    $names = implode(', ', array_map(fn($w) => $w['name'] . ' (' . $w['service'] . ($w['active'] ? '' : ', de baja') . ')', $all)) ?: '(ninguna)';
    throw new McpToolError("Persona \"$name\" no encontrada. Personas: $names");
}

/** Persona para registrar: la indicada, o la única activa del servicio. */
function mcp_worker_for_log(Database $db, array $args, bool $allowInactive): array {
    if (!empty($args['worker'])) {
        $w = mcp_worker_by_name($db, (string) $args['worker']);
        if (!$w['active'] && !$allowInactive) {
            throw new McpToolError("{$w['name']} está dada de baja: no se le pueden registrar horas nuevas (sí pagos, para saldar la deuda).");
        }
        if (!empty($args['service'])) {
            $svc = mcp_service($args['service']);
            if ($svc['name'] !== $w['service']) throw new McpToolError("{$w['name']} es de {$w['service']}, no de {$svc['name']}");
        }
        return $w;
    }
    $svc = mcp_service($args['service'] ?? null);
    $active = $db->getActiveWorkers($svc['name']);
    if (count($active) === 1) {
        foreach ($db->getWorkers() as $w) if ((int) $w['id'] === (int) $active[0]['id']) return $w;
    }
    if (!$active) throw new McpToolError("{$svc['name']} no tiene ninguna persona activa. Añádela en Admin → Personas.");
    throw new McpToolError("{$svc['name']} tiene varias personas activas: pregunta al usuario cuál (" . implode(', ', array_column($active, 'name')) . ") y pásala en worker.");
}

function mcp_worker_out(array $w): array {
    return [
        'name' => $w['name'], 'service' => $w['service'], 'rate' => $w['rate'] !== null ? mcp_round($w['rate']) : null,
        'active' => (bool) $w['active'], 'balance' => mcp_round($w['balance'] ?? 0),
        'status' => ($w['balance'] ?? 0) < 0 ? 'pendiente de pagar' : (($w['balance'] ?? 0) > 0 ? 'a favor' : 'al día'),
        'total_hours' => mcp_round($w['total_hours'] ?? 0), 'total_worked' => mcp_round($w['total_worked'] ?? 0),
        'total_paid' => mcp_round($w['total_paid'] ?? 0), 'last_date' => $w['last_date'] ?? null,
    ];
}

function mcp_date(?string $value, string $field = 'date', bool $defaultToday = true): ?string {
    if ($value === null || trim($value) === '') return $defaultToday ? date('Y-m-d') : null;
    $d = DateTime::createFromFormat('!Y-m-d', trim($value));
    if (!$d || $d->format('Y-m-d') !== trim($value)) throw new McpToolError("$field debe tener formato AAAA-MM-DD");
    if ($d->format('Y-m-d') > date('Y-m-d')) throw new McpToolError("$field no puede ser una fecha futura");
    return $d->format('Y-m-d');
}

function mcp_num($v, string $field, float $min, float $max, bool $exclusiveMin = false): float {
    if (!is_numeric($v)) throw new McpToolError("$field debe ser un número");
    $n = (float) $v;
    if (($exclusiveMin ? $n <= $min : $n < $min) || $n > $max) {
        throw new McpToolError("$field fuera de rango (" . ($exclusiveMin ? '>' : '≥') . " $min y ≤ $max)");
    }
    return round($n, 2);
}

function mcp_notes($v): ?string {
    if ($v === null) return null;
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, 500);
}

function mcp_round($v): float { return round((float) $v, 2); }

/** Saldo con texto legible. */
function mcp_balance_info(Database $db, ?string $service, ?array $worker = null): array {
    $s = $db->getStats($worker ? null : $service, $worker['id'] ?? null);
    $bal = mcp_round($s['balance'] ?? 0);
    return [
        'worker'       => $worker['name'] ?? null,
        'service'      => $worker['service'] ?? ($service ?? 'todos'),
        'balance'      => $bal,
        'status'       => $bal < 0 ? 'pendiente de pagar' : ($bal > 0 ? 'pagado por adelantado (a favor)' : 'al día'),
        'pending'      => $bal < 0 ? abs($bal) : 0,
        'total_hours'  => mcp_round($s['total_hours'] ?? 0),
        'total_worked' => mcp_round($s['total_worked'] ?? 0),
        'total_paid'   => mcp_round($s['total_paid'] ?? 0),
    ];
}

function mcp_record_out(array $r): array {
    $out = [
        'id' => (int) $r['id'], 'type' => $r['type'], 'service' => $r['service'],
        'worker' => $r['worker_name'] ?? null, 'date' => $r['date'],
    ];
    if ($r['type'] === 'work') {
        $out += ['hours' => mcp_round($r['hours']), 'rate' => mcp_round($r['rate']), 'total' => mcp_round($r['total'])];
    } else {
        $out += ['amount' => mcp_round($r['amount'])];
    }
    $out['notes'] = $r['notes'] ?: null;
    $out['created_by'] = $r['created_by'] ?? null;
    return $out;
}

function mcp_find_duplicate(Database $db, string $type, int $workerId, string $date, float $value): ?array {
    foreach ($db->getAllRecords(null, $type, $workerId) as $r) {
        if ($r['date'] !== $date) continue;
        $v = $type === 'work' ? (float) $r['hours'] : (float) $r['amount'];
        if (abs($v - $value) < 0.005) return $r;
    }
    return null;
}

function mcp_eur(float $v): string { return number_format($v, 2, ',', '.') . ' €'; }

function mcp_balance_sentence(array $b): string {
    $who = !empty($b['worker']) ? " con {$b['worker']}" : '';
    return $b['balance'] < 0
        ? "Saldo$who: " . mcp_eur(abs($b['balance'])) . ' pendiente de pagar.'
        : ($b['balance'] > 0 ? "Saldo$who: " . mcp_eur($b['balance']) . ' a favor.' : "Saldo$who: al día.");
}

// ── Ejecución ────────────────────────────────────────────────────────────────
/**
 * Ejecuta una herramienta. $key = datos públicos de la API key (name, scope).
 * Devuelve un array serializable; lanza McpToolError con mensaje para el agente.
 */
function mcp_call_tool(string $name, array $args, array $key): array {
    $defs = array_column(mcp_tool_definitions(), null, 'name');
    if (!isset($defs[$name])) throw new McpToolError("Herramienta desconocida: $name");
    if ($defs[$name]['scope'] === 'write' && $key['scope'] !== 'write') {
        throw new McpToolError('Esta API key es de solo lectura; no puede registrar ni modificar datos.');
    }

    $db = new Database();
    $author = 'agente: ' . $key['name'];

    switch ($name) {
        case 'list_services':
            return ['services' => array_map(fn($s) => ['name' => $s['name'], 'rate' => mcp_round($s['rate'] ?? 0)], mcp_services())];

        case 'list_workers': {
            $svc = mcp_service($args['service'] ?? null, false);
            $incl = !isset($args['include_inactive']) || $args['include_inactive'];
            $ws = array_filter($db->getWorkers(), fn($w) => (!$svc || $w['service'] === $svc['name']) && ($incl || $w['active']));
            return ['workers' => array_values(array_map('mcp_worker_out', $ws))];
        }

        case 'get_balance': {
            $wk  = !empty($args['worker']) ? mcp_worker_by_name($db, (string) $args['worker']) : null;
            $svc = $wk ? null : mcp_service($args['service'] ?? null, false);
            $out = mcp_balance_info($db, $svc['name'] ?? null, $wk);
            $all = $db->getAllRecords($wk ? null : ($svc['name'] ?? null), null, $wk['id'] ?? null);
            foreach ($all as $r) {
                if ($r['type'] === 'work' && !isset($out['last_work'])) $out['last_work'] = mcp_record_out($r);
                if ($r['type'] === 'payment' && !isset($out['last_payment'])) $out['last_payment'] = mcp_record_out($r);
            }
            if (!$wk) {
                $out['by_worker'] = array_values(array_map('mcp_worker_out', array_filter(
                    $db->getWorkers(), fn($w) => (!$svc || $w['service'] === $svc['name']) && ($w['active'] || (float) $w['balance'] != 0.0)
                )));
            }
            $out['message'] = mcp_balance_sentence($out);
            return $out;
        }

        case 'list_records': {
            $wk   = !empty($args['worker']) ? mcp_worker_by_name($db, (string) $args['worker']) : null;
            $svc  = $wk ? null : mcp_service($args['service'] ?? null, false);
            $type = $args['type'] ?? null;
            if ($type !== null && !in_array($type, ['work', 'payment'], true)) throw new McpToolError('type debe ser "work" o "payment"');
            $from  = mcp_date($args['date_from'] ?? null, 'date_from', false);
            $to    = isset($args['date_to']) && $args['date_to'] !== '' ? (DateTime::createFromFormat('!Y-m-d', $args['date_to']) ?: throw new McpToolError('date_to debe tener formato AAAA-MM-DD'))->format('Y-m-d') : null;
            $limit = (int) min(200, max(1, (int) ($args['limit'] ?? 20)));

            // Saldo acumulado calculado sobre todo el historial del servicio
            $all = array_reverse($db->getAllRecords($svc['name'] ?? null, null, $wk['id'] ?? null)); // más antiguo primero
            $running = 0.0; $rows = [];
            foreach ($all as $r) {
                $running = round($running + ($r['type'] === 'work' ? -(float) $r['total'] : (float) $r['amount']), 2);
                if ($type && $r['type'] !== $type) continue;
                if ($from && $r['date'] < $from) continue;
                if ($to && $r['date'] > $to) continue;
                $rows[] = mcp_record_out($r) + ['balance_after' => $running];
            }
            $total = count($rows);
            $rows = array_slice(array_reverse($rows), 0, $limit);
            return ['worker' => $wk['name'] ?? null, 'count' => count($rows), 'total_matching' => $total, 'records' => $rows];
        }

        case 'monthly_summary': {
            $wk  = !empty($args['worker']) ? mcp_worker_by_name($db, (string) $args['worker']) : null;
            $svc = $wk ? null : mcp_service($args['service'] ?? null, false);
            $months = (int) min(60, max(1, (int) ($args['months'] ?? 12)));
            $start = (new DateTime('first day of this month'))->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
            $agg = [];
            foreach ($db->getAllRecords($svc['name'] ?? null, null, $wk['id'] ?? null) as $r) {
                if ($r['date'] < $start) continue;
                $k = substr($r['date'], 0, 7);
                $agg[$k] ??= ['month' => $k, 'hours' => 0, 'worked' => 0, 'paid' => 0];
                if ($r['type'] === 'work') { $agg[$k]['hours'] += (float) $r['hours']; $agg[$k]['worked'] += (float) $r['total']; }
                else { $agg[$k]['paid'] += (float) $r['amount']; }
            }
            krsort($agg);
            $rows = array_map(fn($m) => [
                'month' => $m['month'], 'hours' => mcp_round($m['hours']), 'worked' => mcp_round($m['worked']),
                'paid' => mcp_round($m['paid']), 'balance' => mcp_round($m['paid'] - $m['worked']),
            ], array_values($agg));
            return ['worker' => $wk['name'] ?? null, 'service' => $wk['service'] ?? ($svc['name'] ?? 'todos'), 'from' => $start, 'months' => $rows];
        }

        case 'log_work': {
            if (!isset($args['hours'])) throw new McpToolError('hours es obligatorio');
            $hours = mcp_num($args['hours'], 'hours', 0, 24, true);
            $wk    = mcp_worker_for_log($db, $args, false);
            $svc   = mcp_service($wk['service']);
            $date  = mcp_date($args['date'] ?? null);
            $rate  = isset($args['rate']) ? mcp_num($args['rate'], 'rate', 0, 1000) : mcp_round($wk['rate'] ?? ($svc['rate'] ?? 0));
            if ($rate <= 0) throw new McpToolError("{$wk['name']} no tiene tarifa configurada: indica rate");
            if (empty($args['allow_duplicate']) && ($dup = mcp_find_duplicate($db, 'work', (int) $wk['id'], $date, $hours))) {
                throw new McpToolError("Posible duplicado: ya hay {$hours}h de {$wk['name']} el $date (id {$dup['id']}). Si el usuario confirma que es otro trabajo, repite con allow_duplicate=true.");
            }
            $rec = $db->createWorkRecord([
                'worker_id' => $wk['id'], 'date' => $date, 'hours' => $hours, 'rate' => $rate,
                'notes' => mcp_notes($args['notes'] ?? null), 'created_by' => $author,
            ]);
            $bal = mcp_balance_info($db, null, $wk);
            return [
                'message' => "Registradas {$hours}h de {$wk['name']} ({$svc['name']}) el $date (" . mcp_eur($hours * $rate) . "). " . mcp_balance_sentence($bal),
                'record'  => mcp_record_out($rec),
                'balance' => $bal,
            ];
        }

        case 'log_payment': {
            if (!isset($args['amount'])) throw new McpToolError('amount es obligatorio');
            $amount = mcp_num($args['amount'], 'amount', 0, 100000, true);
            $wk     = mcp_worker_for_log($db, $args, true);
            $date   = mcp_date($args['date'] ?? null);
            if (empty($args['allow_duplicate']) && ($dup = mcp_find_duplicate($db, 'payment', (int) $wk['id'], $date, $amount))) {
                throw new McpToolError("Posible duplicado: ya hay un pago de " . mcp_eur($amount) . " a {$wk['name']} el $date (id {$dup['id']}). Si el usuario confirma que es otro pago, repite con allow_duplicate=true.");
            }
            $rec = $db->createPaymentRecord([
                'worker_id' => $wk['id'], 'date' => $date, 'amount' => $amount,
                'notes' => mcp_notes($args['notes'] ?? null), 'created_by' => $author,
            ]);
            $bal = mcp_balance_info($db, null, $wk);
            return [
                'message' => 'Registrado pago de ' . mcp_eur($amount) . " a {$wk['name']} ({$wk['service']}) el $date. " . mcp_balance_sentence($bal),
                'record'  => mcp_record_out($rec),
                'balance' => $bal,
            ];
        }

        case 'update_record': {
            $id = (int) ($args['id'] ?? 0);
            $rec = $id > 0 ? $db->getRecord($id) : null;
            if (!$rec) throw new McpToolError("No existe el movimiento con id $id");
            $isWork = $rec['type'] === 'work';
            $data = [];
            if (isset($args['date']))    $data['date'] = mcp_date($args['date']);
            if (!empty($args['worker'])) {
                $data['worker_id'] = (int) mcp_worker_by_name($db, (string) $args['worker'])['id'];
            } elseif (isset($args['service'])) {
                $data['service'] = mcp_service($args['service'])['name'];
            }
            if (array_key_exists('notes', $args)) $data['notes'] = mcp_notes($args['notes']);
            if (isset($args['hours']) || isset($args['rate'])) {
                if (!$isWork) throw new McpToolError('hours/rate solo se pueden cambiar en trabajos (este movimiento es un pago)');
                if (isset($args['hours'])) $data['hours'] = mcp_num($args['hours'], 'hours', 0, 24, true);
                if (isset($args['rate']))  $data['rate']  = mcp_num($args['rate'], 'rate', 0, 1000);
            }
            if (isset($args['amount'])) {
                if ($isWork) throw new McpToolError('amount solo se puede cambiar en pagos (este movimiento es un trabajo)');
                $data['amount'] = mcp_num($args['amount'], 'amount', 0, 100000, true);
            }
            if (!$data) throw new McpToolError('No se ha indicado ningún campo a cambiar');
            $before = mcp_record_out($rec);
            $updated = $db->updateRecord($id, $data);
            $after  = mcp_record_out($updated);
            $wkAfter = $updated['worker_id'] ? $db->getWorker($updated['worker_id']) : null;
            $bal = mcp_balance_info($db, $after['service'], $wkAfter);
            return ['message' => "Movimiento $id corregido. " . mcp_balance_sentence($bal), 'before' => $before, 'after' => $after, 'balance' => $bal];
        }

        case 'delete_record': {
            $id = (int) ($args['id'] ?? 0);
            $rec = $id > 0 ? $db->getRecord($id) : null;
            if (!$rec) throw new McpToolError("No existe el movimiento con id $id");
            $db->deleteRecord($id);
            $bal = mcp_balance_info($db, $rec['service'], $rec['worker_id'] ? $db->getWorker($rec['worker_id']) : null);
            return ['message' => "Movimiento $id eliminado. " . mcp_balance_sentence($bal), 'deleted' => mcp_record_out($rec), 'balance' => $bal];
        }
    }
    throw new McpToolError("Herramienta no implementada: $name");
}
