<?php
require_once 'config.php';

class Database {
    private $db;
    
    public function __construct() {
        $this->connect();
        $this->createTables();
    }
    
    /**
     * Conectar a la base de datos SQLite
     */
    private function connect() {
        try {
            // Crear directorio si no existe
            $dir = dirname(DB_PATH);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $this->db = new PDO('sqlite:' . DB_PATH);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Habilitar claves foráneas
            $this->db->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            errorResponse('Error de conexión a la base de datos: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Crear tablas si no existen
     */
    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL CHECK(type IN ('work', 'payment')),
            service TEXT NOT NULL,
            date TEXT NOT NULL,
            hours REAL,
            rate REAL,
            total REAL,
            amount REAL,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE INDEX IF NOT EXISTS idx_date ON records(date);
        CREATE INDEX IF NOT EXISTS idx_service ON records(service);
        CREATE INDEX IF NOT EXISTS idx_type ON records(type);
        ";
        
        try {
            $this->db->exec($sql);
            $this->migrate();
        } catch (PDOException $e) {
            errorResponse('Error al crear tablas: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Migraciones idempotentes (solo añaden columnas opcionales; nunca borran datos)
     */
    private function migrate() {
        $cols = array_column($this->db->query("PRAGMA table_info(records)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        // v2.2.0: quién registró el movimiento (NULL = web; "agente: <nombre>" = vía MCP)
        if (!in_array('created_by', $cols, true)) {
            $this->db->exec("ALTER TABLE records ADD COLUMN created_by TEXT");
        }
        // v2.2.0: personas (p. ej. la chica de la limpieza). Cada movimiento pertenece a una persona;
        // cada persona tiene su tarifa y su propio saldo, y puede darse de baja sin perder sus datos.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS workers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                service TEXT NOT NULL,
                rate REAL,
                active INTEGER NOT NULL DEFAULT 1,
                notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        if (!in_array('worker_id', $cols, true)) {
            $this->db->exec("ALTER TABLE records ADD COLUMN worker_id INTEGER");
        }
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_worker ON records(worker_id)");
        $this->assignOrphanRecords();
    }

    /**
     * Asocia a una persona los movimientos que no tienen (datos anteriores a v2.2.0 o importados).
     * - Si el servicio tiene una sola persona → se asignan a ella.
     * - Si no tiene ninguna → se crea una con el nombre del servicio (se puede renombrar en el admin).
     * - Si tiene varias → se dejan sin asignar (ambiguo); el admin los muestra para revisarlos.
     */
    public function assignOrphanRecords(): int {
        $services = $this->db->query("SELECT DISTINCT service FROM records WHERE worker_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $assigned = 0;
        foreach ($services as $service) {
            $workers = $this->db->prepare("SELECT id FROM workers WHERE service = :s");
            $workers->execute([':s' => $service]);
            $ids = $workers->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) > 1) continue;
            if (count($ids) === 0) {
                $rate = $this->db->prepare("SELECT rate FROM records WHERE service = :s AND type = 'work' AND rate IS NOT NULL ORDER BY date DESC, id DESC LIMIT 1");
                $rate->execute([':s' => $service]);
                $ins = $this->db->prepare("INSERT INTO workers (name, service, rate, notes) VALUES (:n, :s, :r, :notes)");
                $ins->execute([':n' => $service, ':s' => $service, ':r' => $rate->fetchColumn() ?: null,
                               ':notes' => 'Creada automáticamente con los movimientos existentes. Cámbiale el nombre en Admin → Personas.']);
                $ids = [$this->db->lastInsertId()];
            }
            $upd = $this->db->prepare("UPDATE records SET worker_id = :w WHERE service = :s AND worker_id IS NULL");
            $upd->execute([':w' => $ids[0], ':s' => $service]);
            $assigned += $upd->rowCount();
        }
        return $assigned;
    }

    // ── Personas ─────────────────────────────────────────────────────────────

    /** Personas con sus totales (horas, trabajado, pagado, saldo, nº de movimientos, último movimiento). */
    public function getWorkers(): array {
        return $this->db->query("
            SELECT w.*,
                   COUNT(r.id) AS records,
                   ROUND(COALESCE(SUM(CASE WHEN r.type = 'work' THEN r.hours END), 0), 2)  AS total_hours,
                   ROUND(COALESCE(SUM(CASE WHEN r.type = 'work' THEN r.total END), 0), 2)  AS total_worked,
                   ROUND(COALESCE(SUM(CASE WHEN r.type = 'payment' THEN r.amount END), 0), 2) AS total_paid,
                   ROUND(COALESCE(SUM(CASE WHEN r.type = 'payment' THEN r.amount END), 0)
                       - COALESCE(SUM(CASE WHEN r.type = 'work' THEN r.total END), 0), 2) AS balance,
                   MIN(r.date) AS first_date,
                   MAX(r.date) AS last_date
            FROM workers w
            LEFT JOIN records r ON r.worker_id = w.id
            GROUP BY w.id
            ORDER BY w.active DESC, w.service, w.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getWorker($id) {
        $stmt = $this->db->prepare("SELECT * FROM workers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Personas activas de un servicio. */
    public function getActiveWorkers(string $service): array {
        $stmt = $this->db->prepare("SELECT * FROM workers WHERE service = :s AND active = 1 ORDER BY id DESC");
        $stmt->execute([':s' => $service]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function workerNameTaken(string $name, ?int $exceptId = null): bool {
        foreach ($this->db->query("SELECT id, name FROM workers")->fetchAll(PDO::FETCH_ASSOC) as $w) {
            if ((int) $w['id'] !== (int) $exceptId && mb_strtolower(trim($w['name'])) === mb_strtolower(trim($name))) return true;
        }
        return false;
    }

    public function createWorker(array $data) {
        $name = trim((string) ($data['name'] ?? ''));
        $service = trim((string) ($data['service'] ?? ''));
        if ($name === '' || $service === '') errorResponse('Nombre y servicio son obligatorios');
        if ($this->workerNameTaken($name)) errorResponse("Ya existe una persona llamada \"$name\"");
        $rate = isset($data['rate']) && $data['rate'] !== '' ? round((float) $data['rate'], 2) : null;
        $stmt = $this->db->prepare("INSERT INTO workers (name, service, rate, notes) VALUES (:n, :s, :r, :notes)");
        $stmt->execute([':n' => mb_substr($name, 0, 60), ':s' => $service, ':r' => $rate, ':notes' => $data['notes'] ?? null]);
        return $this->getWorker($this->db->lastInsertId());
    }

    public function updateWorker($id, array $data) {
        $w = $this->getWorker($id);
        if (!$w) errorResponse('Persona no encontrada', 404);
        $fields = []; $params = [':id' => $id];
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') errorResponse('El nombre es obligatorio');
            if ($this->workerNameTaken($name, (int) $id)) errorResponse("Ya existe una persona llamada \"$name\"");
            $fields[] = 'name = :name'; $params[':name'] = mb_substr($name, 0, 60);
        }
        if (array_key_exists('rate', $data)) {
            $fields[] = 'rate = :rate'; $params[':rate'] = ($data['rate'] === '' || $data['rate'] === null) ? null : round((float) $data['rate'], 2);
        }
        if (isset($data['active'])) { $fields[] = 'active = :active'; $params[':active'] = $data['active'] ? 1 : 0; }
        if (array_key_exists('notes', $data)) { $fields[] = 'notes = :notes'; $params[':notes'] = $data['notes'] !== '' ? $data['notes'] : null; }
        if (!$fields) errorResponse('No hay campos para actualizar');
        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        $this->db->prepare("UPDATE workers SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
        return $this->getWorker($id);
    }

    /** Solo se puede eliminar una persona sin movimientos (si tiene, se da de baja). */
    public function deleteWorker($id) {
        $count = $this->db->prepare("SELECT COUNT(*) FROM records WHERE worker_id = :id");
        $count->execute([':id' => $id]);
        if ((int) $count->fetchColumn() > 0) errorResponse('Esta persona tiene movimientos: dala de baja en lugar de eliminarla');
        $this->db->prepare("DELETE FROM workers WHERE id = :id")->execute([':id' => $id]);
        return ['success' => true];
    }

    /** Renombra un servicio en movimientos y personas (al renombrarlo en el admin). */
    public function renameService(string $old, string $new): void {
        $this->db->prepare("UPDATE records SET service = :n WHERE service = :o")->execute([':n' => $new, ':o' => $old]);
        $this->db->prepare("UPDATE workers SET service = :n WHERE service = :o")->execute([':n' => $new, ':o' => $old]);
    }

    /** Movimientos y personas que dependen de un servicio. */
    public function serviceUsage(string $service): array {
        $r = $this->db->prepare("SELECT COUNT(*) FROM records WHERE service = :s"); $r->execute([':s' => $service]);
        $w = $this->db->prepare("SELECT COUNT(*) FROM workers WHERE service = :s"); $w->execute([':s' => $service]);
        return ['records' => (int) $r->fetchColumn(), 'workers' => (int) $w->fetchColumn()];
    }

    /**
     * Resuelve la persona de un movimiento nuevo o editado.
     * Con worker_id → esa persona (y su servicio). Sin él → la única persona activa del servicio.
     * Devuelve [worker_id, service].
     */
    public function resolveWorker(array $data, ?string $fallbackService = null): array {
        if (!empty($data['worker_id'])) {
            $w = $this->getWorker((int) $data['worker_id']);
            if (!$w) errorResponse('Persona no encontrada', 404);
            return [(int) $w['id'], $w['service']];
        }
        $service = $data['service'] ?? $fallbackService;
        if (!$service) errorResponse("El campo 'service' es obligatorio");
        $active = $this->getActiveWorkers($service);
        if (count($active) === 1) return [(int) $active[0]['id'], $service];
        if (count($active) === 0) errorResponse("El servicio \"$service\" no tiene ninguna persona activa: añádela en Admin → Personas");
        errorResponse("El servicio \"$service\" tiene varias personas activas: indica cuál (" . implode(', ', array_column($active, 'name')) . ")");
    }
    
    /**
     * Obtener todos los registros
     */
    public function getAllRecords($service = null, $type = null, $workerId = null) {
        try {
            $sql = "SELECT r.*, w.name AS worker_name FROM records r LEFT JOIN workers w ON w.id = r.worker_id WHERE 1=1";
            $params = [];
            
            if ($service) {
                $sql .= " AND r.service = :service";
                $params[':service'] = $service;
            }
            
            if ($type) {
                $sql .= " AND r.type = :type";
                $params[':type'] = $type;
            }

            if ($workerId) {
                $sql .= " AND r.worker_id = :worker";
                $params[':worker'] = $workerId;
            }
            
            $sql .= " ORDER BY r.date DESC, r.created_at DESC, r.id DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            errorResponse('Error al obtener registros: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Obtener un registro por ID
     */
    public function getRecord($id) {
        try {
            $stmt = $this->db->prepare("SELECT r.*, w.name AS worker_name FROM records r LEFT JOIN workers w ON w.id = r.worker_id WHERE r.id = :id");
            $stmt->execute([':id' => $id]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            errorResponse('Error al obtener registro: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Crear un registro de trabajo
     */
    public function createWorkRecord($data) {
        [$workerId, $service] = $this->resolveWorker($data);
        if (!isset($data['rate']) || $data['rate'] === '' || $data['rate'] === null) {
            $data['rate'] = $this->getWorker($workerId)['rate'] ?? null;
        }
        $data['service'] = $service;
        validateInput($data, ['service', 'date', 'hours', 'rate']);
        
        try {
            $total = round($data['hours'] * $data['rate'], 2);
            
            $stmt = $this->db->prepare("
                INSERT INTO records (type, service, worker_id, date, hours, rate, total, notes, created_by)
                VALUES ('work', :service, :worker_id, :date, :hours, :rate, :total, :notes, :created_by)
            ");
            
            $stmt->execute([
                ':worker_id' => $workerId,
                ':service' => $data['service'],
                ':date' => $data['date'],
                ':hours' => $data['hours'],
                ':rate' => $data['rate'],
                ':total' => $total,
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ]);
            
            return $this->getRecord($this->db->lastInsertId());
        } catch (PDOException $e) {
            errorResponse('Error al crear registro de trabajo: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Crear un registro de pago
     */
    public function createPaymentRecord($data) {
        [$workerId, $service] = $this->resolveWorker($data);
        $data['service'] = $service;
        validateInput($data, ['service', 'date', 'amount']);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO records (type, service, worker_id, date, amount, notes, created_by)
                VALUES ('payment', :service, :worker_id, :date, :amount, :notes, :created_by)
            ");
            
            $stmt->execute([
                ':worker_id' => $workerId,
                ':service' => $data['service'],
                ':date' => $data['date'],
                ':amount' => $data['amount'],
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ]);
            
            return $this->getRecord($this->db->lastInsertId());
        } catch (PDOException $e) {
            errorResponse('Error al crear registro de pago: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Actualizar un registro
     */
    public function updateRecord($id, $data) {
        try {
            $record = $this->getRecord($id);
            if (!$record) {
                errorResponse('Registro no encontrado', 404);
            }
            
            // Cambio de persona o de servicio: mantener persona y servicio coherentes
            if (!empty($data['worker_id']) || (isset($data['service']) && $data['service'] !== $record['service'])) {
                [$workerId, $service] = $this->resolveWorker($data);
                $data['worker_id'] = $workerId;
                $data['service'] = $service;
            }

            // Construir SQL dinámicamente
            $fields = [];
            $params = [':id' => $id];
            
            foreach ($data as $key => $value) {
                if (in_array($key, ['service', 'worker_id', 'date', 'hours', 'rate', 'amount', 'notes'])) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }
            
            if (empty($fields)) {
                errorResponse('No hay campos para actualizar');
            }
            
            // Recalcular total si es trabajo y se modifican horas o tarifa
            if ($record['type'] === 'work' && (isset($data['hours']) || isset($data['rate']))) {
                $hours = $data['hours'] ?? $record['hours'];
                $rate = $data['rate'] ?? $record['rate'];
                $fields[] = "total = :total";
                $params[':total'] = $hours * $rate;
            }
            
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            
            $sql = "UPDATE records SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $this->getRecord($id);
        } catch (PDOException $e) {
            errorResponse('Error al actualizar registro: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Eliminar un registro
     */
    public function deleteRecord($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM records WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            return ['success' => true, 'message' => 'Registro eliminado'];
        } catch (PDOException $e) {
            errorResponse('Error al eliminar registro: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Calcular estadísticas
     */
    public function getStats($service = null, $workerId = null) {
        try {
            // COALESCE garantiza que NULLs en total/amount/hours no contaminen las sumas
            $sql = "SELECT
                ROUND(SUM(CASE WHEN type = 'work' THEN COALESCE(total, 0) ELSE 0 END), 2) as total_worked,
                ROUND(SUM(CASE WHEN type = 'work' THEN COALESCE(hours, 0) ELSE 0 END), 2) as total_hours,
                ROUND(SUM(CASE WHEN type = 'payment' THEN COALESCE(amount, 0) ELSE 0 END), 2) as total_paid,
                ROUND(SUM(CASE WHEN type = 'payment' THEN COALESCE(amount, 0) ELSE 0 END) -
                 SUM(CASE WHEN type = 'work' THEN COALESCE(total, 0) ELSE 0 END), 2) as balance
                FROM records WHERE 1=1";

            $params = [];
            if ($service) {
                $sql .= " AND service = :service";
                $params[':service'] = $service;
            }
            if ($workerId) {
                $sql .= " AND worker_id = :worker";
                $params[':worker'] = $workerId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            errorResponse('Error al calcular estadísticas: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Recalcular totales de todos los registros de trabajo (hours * rate)
     * Útil para reparar registros con total NULL o desincronizados
     */
    public function recalculateTotals() {
        try {
            // Actualizar registros de trabajo que tienen hours y rate pero total puede estar mal
            $stmt = $this->db->prepare("
                UPDATE records
                SET total = ROUND(hours * rate, 2),
                    updated_at = CURRENT_TIMESTAMP
                WHERE type = 'work'
                  AND hours IS NOT NULL
                  AND rate IS NOT NULL
            ");
            $stmt->execute();
            $fixed = $stmt->rowCount();

            // Detectar registros con datos insuficientes para recalcular
            $broken = $this->db->query("
                SELECT id, service, date FROM records
                WHERE type = 'work' AND (hours IS NULL OR rate IS NULL OR total IS NULL)
            ")->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'fixed'   => $fixed,
                'broken'  => $broken,
                'message' => "$fixed registros recalculados correctamente"
            ];
        } catch (PDOException $e) {
            errorResponse('Error al recalcular totales: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Crear backup de la base de datos
     */
    public function createBackup() {
        try {
            if (!is_dir(BACKUP_DIR)) {
                mkdir(BACKUP_DIR, 0755, true);
            }
            
            $backupFile = BACKUP_DIR . 'backup_' . date('Y-m-d_His') . '.db';
            
            if (copy(DB_PATH, $backupFile)) {
                // Limpiar backups antiguos (mantener últimos 10)
                $this->cleanOldBackups(10);
                return ['success' => true, 'file' => basename($backupFile)];
            }
            
            return ['success' => false, 'message' => 'Error al crear backup'];
        } catch (Exception $e) {
            errorResponse('Error al crear backup: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Limpiar backups antiguos
     */
    private function cleanOldBackups($keep = 10) {
        $files = glob(BACKUP_DIR . 'backup_*.db');
        if (count($files) > $keep) {
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $filesToDelete = array_slice($files, $keep);
            foreach ($filesToDelete as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Importar datos desde array
     */
    public function importData($records) {
        try {
            $this->db->beginTransaction();
            
            $imported = 0;
            foreach ($records as $record) {
                if ($record['type'] === 'work') {
                    $this->createWorkRecord($record);
                } else if ($record['type'] === 'payment') {
                    $this->createPaymentRecord($record);
                }
                $imported++;
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'imported' => $imported,
                'message' => "$imported registros importados correctamente"
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            errorResponse('Error al importar datos: ' . $e->getMessage(), 500);
        }
    }
}
