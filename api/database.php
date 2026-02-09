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
        } catch (PDOException $e) {
            errorResponse('Error al crear tablas: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Obtener todos los registros
     */
    public function getAllRecords($service = null, $type = null) {
        try {
            $sql = "SELECT * FROM records WHERE 1=1";
            $params = [];
            
            if ($service) {
                $sql .= " AND service = :service";
                $params[':service'] = $service;
            }
            
            if ($type) {
                $sql .= " AND type = :type";
                $params[':type'] = $type;
            }
            
            $sql .= " ORDER BY date DESC, created_at DESC";
            
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
            $stmt = $this->db->prepare("SELECT * FROM records WHERE id = :id");
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
        validateInput($data, ['service', 'date', 'hours', 'rate']);
        
        try {
            $total = $data['hours'] * $data['rate'];
            
            $stmt = $this->db->prepare("
                INSERT INTO records (type, service, date, hours, rate, total, notes)
                VALUES ('work', :service, :date, :hours, :rate, :total, :notes)
            ");
            
            $stmt->execute([
                ':service' => $data['service'],
                ':date' => $data['date'],
                ':hours' => $data['hours'],
                ':rate' => $data['rate'],
                ':total' => $total,
                ':notes' => $data['notes'] ?? null
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
        validateInput($data, ['service', 'date', 'amount']);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO records (type, service, date, amount, notes)
                VALUES ('payment', :service, :date, :amount, :notes)
            ");
            
            $stmt->execute([
                ':service' => $data['service'],
                ':date' => $data['date'],
                ':amount' => $data['amount'],
                ':notes' => $data['notes'] ?? null
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
            
            // Construir SQL dinámicamente
            $fields = [];
            $params = [':id' => $id];
            
            foreach ($data as $key => $value) {
                if (in_array($key, ['service', 'date', 'hours', 'rate', 'amount', 'notes'])) {
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
    public function getStats($service = null) {
        try {
            $sql = "SELECT 
                SUM(CASE WHEN type = 'work' THEN total ELSE 0 END) as total_worked,
                SUM(CASE WHEN type = 'work' THEN hours ELSE 0 END) as total_hours,
                SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) as total_paid,
                (SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) - 
                 SUM(CASE WHEN type = 'work' THEN total ELSE 0 END)) as balance
                FROM records WHERE 1=1";
            
            $params = [];
            if ($service) {
                $sql .= " AND service = :service";
                $params[':service'] = $service;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            errorResponse('Error al calcular estadísticas: ' . $e->getMessage(), 500);
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
