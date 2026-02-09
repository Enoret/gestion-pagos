#!/bin/bash

# Script de instalación para Gestión de Pagos
# Uso: sudo bash install.sh

echo "================================================"
echo "🚀 Instalación de Gestión de Pagos"
echo "================================================"
echo ""

# Verificar que se ejecuta como root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Por favor ejecuta este script como root (sudo bash install.sh)"
    exit 1
fi

# Directorio de instalación
INSTALL_DIR="/var/www/html/gestion-pagos"

# Verificar que Apache está instalado
if ! command -v apache2 &> /dev/null; then
    echo "⚠️  Apache no está instalado. ¿Deseas instalarlo? (s/n)"
    read -r response
    if [[ "$response" =~ ^([sS][iI]|[sS])$ ]]; then
        apt update
        apt install -y apache2 php libapache2-mod-php php-sqlite3
        systemctl start apache2
        systemctl enable apache2
        echo "✅ Apache instalado correctamente"
    else
        echo "❌ Apache es necesario. Instalación cancelada."
        exit 1
    fi
fi

# Verificar que PHP está instalado
if ! command -v php &> /dev/null; then
    echo "⚠️  PHP no está instalado. Instalando PHP..."
    apt install -y php libapache2-mod-php php-sqlite3
    echo "✅ PHP instalado correctamente"
fi

# Verificar extensión SQLite
if ! php -m | grep -q sqlite3; then
    echo "⚠️  Extensión SQLite3 no encontrada. Instalando..."
    apt install -y php-sqlite3
    systemctl restart apache2
    echo "✅ SQLite3 instalado correctamente"
fi

echo ""
echo "📁 Copiando archivos al servidor..."

# Crear directorio de instalación
mkdir -p "$INSTALL_DIR"

# Copiar archivos (asumiendo que el script está en el directorio del proyecto)
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cp -r "$SCRIPT_DIR"/* "$INSTALL_DIR/"

echo "✅ Archivos copiados"

echo ""
echo "🔒 Configurando permisos..."

# Crear directorio de datos si no existe
mkdir -p "$INSTALL_DIR/data/backups"

# Establecer permisos
chmod 755 "$INSTALL_DIR"
chmod 755 "$INSTALL_DIR/data"
chmod 755 "$INSTALL_DIR/data/backups"
chown -R www-data:www-data "$INSTALL_DIR/data"

echo "✅ Permisos configurados"

echo ""
echo "🔧 Configurando Apache..."

# Verificar que mod_rewrite está habilitado
if ! apache2ctl -M | grep -q rewrite; then
    a2enmod rewrite
    systemctl restart apache2
    echo "✅ mod_rewrite habilitado"
fi

# Verificar que mod_headers está habilitado
if ! apache2ctl -M | grep -q headers; then
    a2enmod headers
    systemctl restart apache2
    echo "✅ mod_headers habilitado"
fi

echo ""
echo "🧪 Verificando instalación..."

# Verificar que se puede acceder
if [ -f "$INSTALL_DIR/index.html" ]; then
    echo "✅ Archivos principales encontrados"
else
    echo "❌ Error: No se encontraron los archivos principales"
    exit 1
fi

# Obtener IP del servidor
SERVER_IP=$(hostname -I | awk '{print $1}')

echo ""
echo "================================================"
echo "✅ ¡Instalación completada!"
echo "================================================"
echo ""
echo "📍 Accede a la aplicación en:"
echo "   Local: http://localhost/gestion-pagos/"
echo "   Red:   http://$SERVER_IP/gestion-pagos/"
echo ""
echo "📋 Próximos pasos:"
echo "   1. Importa tus datos: http://$SERVER_IP/gestion-pagos/api/import.php"
echo "   2. (Opcional) Configura autenticación editando .htaccess"
echo "   3. (Opcional) Configura acceso solo desde red local"
echo ""
echo "📖 Para más información, consulta README.md"
echo ""
echo "================================================"
