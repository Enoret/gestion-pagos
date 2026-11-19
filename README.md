# Gestión de Pagos

Aplicación web ligera para gestionar pagos de servicios del hogar. Registra horas trabajadas, pagos y saldo pendiente para servicios como limpieza, jardinería y más.

[![Docker Hub](https://img.shields.io/docker/pulls/mbraut/gestion-pagos?label=Docker%20Hub&logo=docker)](https://hub.docker.com/r/mbraut/gestion-pagos)
[![Version](https://img.shields.io/badge/version-2.1.0-blue)](https://hub.docker.com/r/mbraut/gestion-pagos/tags)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## Características

- 📊 **Dashboard** con saldo actual, total trabajado y total pagado
- ⏱ **Registro de trabajos** — horas, tarifa, notas
- 💶 **Registro de pagos** — importe y notas
- 📈 **Gráfico de evolución** del saldo (Chart.js)
- 📋 **Historial completo** con filtros por servicio y tipo
- 🔔 **Notificaciones Telegram** automáticas vía cron integrado en Docker
- 🛠 **Panel de administración** — servicios, backups, tema, mantenimiento
- 💾 **Backups automáticos** con restauración desde el panel
- 📤 **Exportación JSON y CSV** / Importación JSON
- 🎨 **Temas claro, oscuro y personalizable** desde el admin
- 🌐 **Multi-idioma** (ES / EN) con detección automática del navegador
- 🖼 **Iconos personalizados** por servicio (Iconify MDI)
- 📱 **Diseño responsive** (móvil, tablet y escritorio)
- 🗄 **SQLite** — sin dependencias externas, los datos viven en un volumen

---

## Inicio rápido con Docker

```bash
docker run -d \
  -p 8080:80 \
  -v gestion-pagos-data:/var/www/html/data \
  --name gestion-pagos \
  --restart unless-stopped \
  mbraut/gestion-pagos:latest
```

Abre `http://localhost:8080` en tu navegador.

### Docker Compose / Portainer Stack

```yaml
services:
  gestion-pagos:
    image: mbraut/gestion-pagos:2.1.0   # o :latest para actualizaciones automáticas
    container_name: gestion-pagos
    ports:
      - "8080:80"
    volumes:
      - gestion-pagos-data:/var/www/html/data
    restart: unless-stopped

volumes:
  gestion-pagos-data:
```

```bash
docker compose up -d
```

> **Nota:** El volumen `gestion-pagos-data` persiste todos los datos (base de datos, configuración, backups, Telegram) entre reinicios y actualizaciones.

---

## Notificaciones Telegram

La imagen incluye **cron integrado** que ejecuta las notificaciones automáticamente sin configuración adicional.

1. Crea un bot en Telegram con [@BotFather](https://t.me/BotFather) y copia el token
2. Obtén tu `chat_id` escribiendo al bot y consultando `https://api.telegram.org/bot<TOKEN>/getUpdates`
3. En el panel admin → **Notificaciones Telegram**, introduce el token y chat_id
4. Añade recordatorios con el día de la semana y la hora que quieras
5. El cron los enviará automáticamente cada minuto dentro del contenedor

---

## Actualizar a una versión nueva

Los datos del volumen **no se tocan** al actualizar la imagen:

```bash
docker pull mbraut/gestion-pagos:latest
docker stop gestion-pagos
docker rm gestion-pagos
docker run -d \
  -p 8080:80 \
  -v gestion-pagos-data:/var/www/html/data \
  --name gestion-pagos \
  --restart unless-stopped \
  mbraut/gestion-pagos:latest
```

Con Docker Compose:
```bash
docker compose pull
docker compose up -d
```

---

## Construir la imagen

```bash
docker build -f docker/Dockerfile -t mbraut/gestion-pagos:latest .
```

El `.dockerignore` excluye `data/` y cualquier dato personal: la imagen siempre arranca con un `data/` vacío.

---

## Instalación manual (sin Docker)

### Requisitos

- PHP 8.0+ con extensiones PDO y SQLite3
- Apache con mod_rewrite activado
- Navegador moderno

### Pasos

```bash
# 1. Copiar archivos al directorio web
sudo cp -r gestion-pagos/* /var/www/html/

# 2. Permisos
sudo chown -R www-data:www-data /var/www/html/

# 3. Activar mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Abre `http://tu-servidor/` en el navegador.

---

## Estructura del proyecto

```
gestion-pagos/
├── index.html              # Página principal (dashboard, formularios, historial)
├── admin.html              # Panel de administración
├── .htaccess               # Config Apache (seguridad, caché, rutas)
├── .dockerignore           # Excluye data/ y archivos locales del build
├── api/
│   ├── config.php          # Configuración general y funciones helper
│   ├── database.php        # Clase Database (SQLite CRUD + estadísticas)
│   ├── records.php         # API de registros (trabajos y pagos)
│   ├── admin.php           # API admin (servicios, tema, backups, Telegram)
│   ├── import.php          # Importación de datos
│   └── telegram_notify.php # Script cron para notificaciones Telegram
├── assets/
│   ├── css/
│   │   └── common.css      # Estilos compartidos (variables, dark mode, componentes)
│   ├── js/
│   │   └── app.js          # Módulo JS compartido (i18n, tema, iconos)
│   └── lang/
│       ├── es.json         # Traducciones español
│       └── en.json         # Traducciones inglés
├── docker/
│   ├── Dockerfile          # Imagen Docker (php:8.2-apache + cron)
│   ├── entrypoint.sh       # Arranca cron + Apache
│   └── docker-compose-portainer.yml
└── data/                   # Generado en tiempo de ejecución (no en la imagen)
    ├── pagos.db            # Base de datos SQLite
    ├── services.json       # Configuración de servicios
    ├── theme.json          # Colores del tema personalizado
    ├── telegram.json       # Config y notificaciones Telegram
    ├── telegram_sent.json  # Control de envíos duplicados
    └── backups/            # Backups de la base de datos
```

---

## API Reference

### Registros (`api/records.php`)

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `?path=records` | Obtener todos los registros |
| GET | `?path=records&service=X` | Filtrar por servicio |
| GET | `?path=stats` | Estadísticas generales |
| GET | `?path=backup` | Crear backup de la BD |
| POST | `?path=work` | Registrar trabajo |
| POST | `?path=payment` | Registrar pago |
| PUT | `?path=record/{id}` | Actualizar registro |
| DELETE | `?path=record/{id}` | Eliminar registro |

### Admin (`api/admin.php`)

| Método | Acción | Descripción |
|--------|--------|-------------|
| GET | `getServices` | Listar servicios |
| GET | `getStats` | Estadísticas del sistema |
| GET | `getBackups` | Listar backups |
| GET | `checkIntegrity` | Verificar integridad de la BD |
| GET | `diagnoseData` | Diagnóstico de datos |
| GET | `exportJSON` | Exportar todo en JSON |
| GET | `getTelegramConfig` | Obtener config Telegram |
| POST | `addService` | Añadir servicio |
| POST | `updateService` | Actualizar servicio |
| POST | `deleteService` | Eliminar servicio |
| POST | `optimizeDB` | Optimizar BD (VACUUM) |
| POST | `cleanBackups` | Limpiar backups antiguos |
| POST | `restoreBackup` | Restaurar backup |
| POST | `saveTheme` | Guardar tema personalizado |
| POST | `deleteTheme` | Restablecer tema |
| POST | `deleteAllRecords` | Eliminar todos los registros |
| POST | `recalculateTotals` | Recalcular total = horas × tarifa |
| POST | `saveTelegramConfig` | Guardar config Telegram |
| POST | `testTelegram` | Enviar mensaje de prueba |
| POST | `addTelegramNotification` | Añadir notificación |
| POST | `updateTelegramNotification` | Actualizar notificación |
| POST | `deleteTelegramNotification` | Eliminar notificación |
| POST | `importJSON` | Importar datos desde JSON |

---

## Stack tecnológico

| Componente | Tecnología |
|------------|------------|
| Backend | PHP 8.2 + SQLite3 |
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Gráficos | Chart.js 4 |
| Iconos | Iconify MDI |
| Fuentes | DM Sans + Fraunces (Google Fonts) |
| Contenedor | Docker (php:8.2-apache + cron) |
| Notificaciones | Telegram Bot API |

---

## Changelog

### v2.1.0 — Noviembre 2026
- ✨ Historial compacto: una línea por movimiento con todos los datos, filtros rápidos (1 / 3 / 6 meses, 1 año), calendario para rangos personalizados y "Ver todos" (por defecto: último mes)
- ✨ Gráfico de evolución rediseñado: barras Trabajado vs Pagado con la diferencia marcada, línea de horas acumuladas como tendencia, agrupación automática por día / semana / mes y periodo "Este mes" por defecto
- ✨ Resumen mensual con filtros por servicio, rápidos (3 / 6 meses, 1 año), calendario y "Ver todos" (por defecto: último año)
- 🐛 Fix: las notificaciones de Telegram nunca se enviaban en Docker (cron no encontraba `php`)
- 🐛 Fix: el saldo del historial y del gráfico se reiniciaba a 0 al filtrar por fechas
- 🔒 Seguridad: `data/` (BD, config de Telegram con el token del bot, logs, backups) ya no es accesible por HTTP; el tema se sirve vía API
- 🔒 La imagen Docker y el repositorio excluyen explícitamente los datos personales

### v2.0.0 — Mayo 2026
- ✨ Notificaciones automáticas por Telegram con cron integrado en Docker
- ✨ Iconos personalizados por servicio (picker de iconos MDI)
- ✨ Soporte multi-idioma: español e inglés
- ✨ Exportación CSV completa
- ✨ Importación/exportación JSON con todos los datos
- ✨ Restauración de backups desde el panel de administración
- ✨ Diagnóstico de datos e integridad de la BD
- ✨ Recálculo de totales para reparar datos históricos desincronizados
- ✨ Tema personalizable con selector de colores desde el admin
- 🐛 Fix: descuadre de balance por `parseFloat(null)` → `NaN` acumulado
- 🐛 Fix: redondeo a 2 decimales en todos los cálculos de saldo
- 🐛 Fix: `COALESCE` en queries SQL para evitar NULLs en sumas

### v1.0.0 — Febrero 2024
- Versión inicial: dashboard, formularios, historial, backups básicos

---

## Licencia

MIT — uso libre y personal. Siéntete libre de adaptarlo a tus necesidades.
