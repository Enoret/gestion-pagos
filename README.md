# 💼 Gestión de Pagos - Sistema de Control de Servicios

Sistema completo de gestión de pagos para servicios del hogar (limpieza, jardinero, etc.) con seguimiento de saldo acumulado.

## 🎯 Características

- ✅ Registro de trabajos realizados (horas y tarifas)
- ✅ Registro de pagos efectuados
- ✅ Control automático de saldo acumulado
- ✅ Gráficos de evolución del saldo
- ✅ Historial completo de movimientos
- ✅ Filtros por servicio y tipo
- ✅ Backups automáticos
- ✅ Interfaz responsive (móvil, tablet, PC)
- ✅ Base de datos SQLite (ligera y sin configuración)

## 📋 Requisitos

- Servidor web Apache
- PHP 7.4 o superior con extensiones:
  - PDO
  - SQLite3
- Navegador web moderno

## 🚀 Instalación

### 1. Copiar archivos al servidor

```bash
# Copiar todo el directorio al servidor Apache
sudo cp -r gestion-pagos /var/www/html/

# Dar permisos al directorio de datos
sudo chmod 755 /var/www/html/gestion-pagos/data
sudo chmod 755 /var/www/html/gestion-pagos/data/backups
sudo chown www-data:www-data /var/www/html/gestion-pagos/data
sudo chown www-data:www-data /var/www/html/gestion-pagos/data/backups
```

### 2. Verificar permisos

```bash
# Verificar que Apache pueda escribir en el directorio de datos
ls -la /var/www/html/gestion-pagos/data/
```

### 3. Acceder a la aplicación

Abre tu navegador y ve a:
```
http://localhost/gestion-pagos/
# O desde otro dispositivo en tu red:
http://IP_DE_TU_SERVIDOR/gestion-pagos/
```

## 📥 Importar datos existentes

Si ya tienes datos en un Excel:

1. Ve a: `http://localhost/gestion-pagos/api/import.php`
2. Exporta tu Google Sheet como CSV
3. El script te guiará en el proceso de importación

O ejecuta directamente desde línea de comandos:
```bash
php /var/www/html/gestion-pagos/api/import.php
```

## 🔧 Configuración

Edita `api/config.php` para personalizar:

```php
// Tipos de servicios disponibles
define('SERVICES', ['Limpieza', 'Jardinero', 'Piscina']);

// Configuración de backups
define('BACKUP_ENABLED', true);
define('BACKUP_FREQUENCY', 'weekly'); // daily, weekly, monthly
```

## 📁 Estructura de archivos

```
gestion-pagos/
├── index.html              # Pagina principal (dashboard, formularios, historial)
├── admin.html              # Panel de administracion
├── .htaccess               # Configuracion Apache (seguridad, cache, rutas)
├── api/
│   ├── config.php          # Configuracion general y funciones auxiliares
│   ├── database.php        # Clase Database (CRUD SQLite)
│   ├── records.php         # API de registros (trabajos y pagos)
│   ├── admin.php           # API de administracion (servicios, tema, backups)
│   └── import.php          # Importacion de datos
├── assets/
│   ├── css/
│   │   └── common.css      # Estilos compartidos (variables, dark mode, componentes)
│   ├── js/
│   │   └── app.js          # Modulo JS compartido (i18n, tema, iconos)
│   └── lang/
│       ├── es.json         # Traducciones en espanol
│       └── en.json         # Traducciones en ingles
├── data/
│   ├── pagos.db            # Base de datos SQLite
│   ├── services.json       # Configuracion de servicios
│   ├── theme.json          # Tema personalizado
│   └── backups/            # Backups automaticos de la BD
├── docker/
│   ├── Dockerfile          # Imagen Docker (php:8.2-apache)
│   ├── docker-compose.yml  # Orquestacion de contenedores
│   └── .dockerignore       # Exclusiones para build
├── share/
│   └── favicon/
│       └── favicon.ico
└── README.md
```

## 🔄 API Endpoints

### GET - Obtener registros
```bash
GET /api/records.php?path=records
GET /api/records.php?path=records&service=Limpieza
GET /api/records.php?path=stats
GET /api/records.php?path=record/123
```

### POST - Crear registros
```bash
POST /api/records.php?path=work
{
  "service": "Limpieza",
  "date": "2026-02-04",
  "hours": 6,
  "rate": 12,
  "notes": "Limpieza profunda"
}

POST /api/records.php?path=payment
{
  "service": "Limpieza",
  "date": "2026-02-04",
  "amount": 72,
  "notes": "Pago completo"
}
```

### PUT - Actualizar registro
```bash
PUT /api/records.php?path=record/123
{
  "hours": 7,
  "notes": "Actualización"
}
```

### DELETE - Eliminar registro
```bash
DELETE /api/records.php?path=record/123
```

## 💾 Backups

Los backups se crean automáticamente y se almacenan en `data/backups/`

Manual:
```bash
# Desde la interfaz web
Clic en "Crear Backup" en el header

# O vía API
GET /api/records.php?path=backup
```

Restaurar un backup:
```bash
cp data/backups/backup_2026-02-04_123456.db data/pagos.db
```

## 🔒 Seguridad (Recomendaciones)

### 1. Añadir autenticación básica

Crea archivo `.htaccess` en el directorio:
```apache
AuthType Basic
AuthName "Gestión de Pagos"
AuthUserFile /var/www/html/gestion-pagos/.htpasswd
Require valid-user
```

Crear archivo de contraseñas:
```bash
sudo htpasswd -c /var/www/html/gestion-pagos/.htpasswd usuario
```

### 2. HTTPS (opcional)

Si tienes certificado SSL, configura Apache para usar HTTPS.

### 3. Acceso solo desde red local

En tu configuración de Apache:
```apache
<Directory /var/www/html/gestion-pagos>
    Require ip 192.168.0.0/24
</Directory>
```

## 🌐 Acceso desde dispositivos móviles

Para acceder desde tu móvil o tablet en la misma red:

1. Encuentra la IP de tu servidor:
```bash
ip addr show
```

2. Accede desde el móvil:
```
http://192.168.X.X/gestion-pagos/
```

3. (Opcional) Añade a la pantalla de inicio para acceso rápido

## 🐛 Solución de problemas

### La base de datos no se crea
```bash
# Verificar permisos
ls -la /var/www/html/gestion-pagos/data/
sudo chown -R www-data:www-data /var/www/html/gestion-pagos/data/
```

### Errores de PHP
```bash
# Ver logs de Apache
sudo tail -f /var/log/apache2/error.log
```

### No aparecen los datos
```bash
# Verificar que la base de datos existe
ls -la /var/www/html/gestion-pagos/data/pagos.db

# Ver contenido de la base de datos
sqlite3 /var/www/html/gestion-pagos/data/pagos.db "SELECT * FROM records;"
```

## 🔄 Actualización

Para actualizar a una nueva versión:

1. Hacer backup de la base de datos
```bash
cp /var/www/html/gestion-pagos/data/pagos.db ~/backup_pagos.db
```

2. Actualizar archivos
```bash
# Reemplazar archivos excepto el directorio data/
```

3. Verificar que todo funciona

## 📊 Exportar datos

Para exportar todos los datos a CSV:

```bash
sqlite3 -header -csv /var/www/html/gestion-pagos/data/pagos.db "SELECT * FROM records;" > export.csv
```

## 🎨 Personalización

### Cambiar colores

Edita las variables CSS en `index.html`:
```css
:root {
    --primary: #2D5F5D;
    --secondary: #E8B86D;
    --accent: #C97064;
}
```

### Añadir nuevos servicios

Edita `api/config.php`:
```php
define('SERVICES', ['Limpieza', 'Jardinero', 'Piscina', 'Electricista']);
```

## 📞 Soporte

Para reportar problemas o sugerencias, contacta al administrador del sistema.

## 📝 Changelog

### v1.0.0 (2026-02-04)
- ✨ Versión inicial
- ✅ Registro de trabajos y pagos
- ✅ Control de saldo acumulado
- ✅ Gráficos interactivos
- ✅ Sistema de backups
- ✅ API REST completa

## 📄 Licencia

Uso privado - Sistema desarrollado para gestión personal del hogar.

---

**Desarrollado con ❤️ para hacer la gestión del hogar más fácil**
