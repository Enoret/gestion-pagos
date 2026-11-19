FROM php:8.2.35-apache

# Instalar SQLite y cron (para notificaciones Telegram automáticas)
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Extensiones PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Apache: mod_rewrite + AllowOverride
RUN a2enmod rewrite && \
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Entrypoint que arranca cron + Apache
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Cron job para notificaciones Telegram (cada minuto; sale rápido si no hay config)
RUN printf 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n* * * * * www-data /usr/local/bin/php /var/www/html/api/telegram_notify.php >> /var/www/html/data/telegram_cron.log 2>&1\n' \
    > /etc/cron.d/gestion-pagos && \
    chmod 0644 /etc/cron.d/gestion-pagos

# Copiar la aplicación (contexto = /opt/gestion-pagos/)
COPY --chown=www-data:www-data . /var/www/html/

# Limpiar lo que no debe ir en la imagen y partir de un data/ vacío
# (la exclusión real la hace .dockerignore; esto es una segunda barrera en la imagen final)
RUN rm -rf /var/www/html/docker /var/www/html/install.sh /var/www/html/screenshots \
           /var/www/html/share /var/www/html/.git* /var/www/html/data && \
    find /var/www/html -name .DS_Store -delete

# Estructura de datos inicial
RUN mkdir -p /var/www/html/data/backups && \
    test -f /var/www/html/data/services.json || echo '[]' > /var/www/html/data/services.json && \
    chown -R www-data:www-data /var/www/html/ && \
    chmod -R 755 /var/www/html/data/

EXPOSE 80
CMD ["/entrypoint.sh"]
