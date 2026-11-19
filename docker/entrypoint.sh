#!/bin/bash
set -e

CRON_FILE="/etc/cron.d/gestion-pagos"
# Siempre se reescribe: cron no hereda el PATH de Docker, así que php debe ir con ruta absoluta
# (antes fallaba con "php: not found" y las notificaciones nunca se enviaban).
cat > "$CRON_FILE" <<'CRON'
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data /usr/local/bin/php /var/www/html/api/telegram_notify.php >> /var/www/html/data/telegram_cron.log 2>&1
CRON
chmod 0644 "$CRON_FILE"

# Arrancar el demonio cron en segundo plano
service cron start

# Arrancar Apache en primer plano (mantiene el contenedor vivo)
exec apache2-foreground
