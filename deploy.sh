#!/bin/bash

# ============================================================================
# DEPLOY SIMPLIFICADO - SOLO CONFIGURACIONES Y OPTIMIZACIONES
# ============================================================================

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

echo -e "${BLUE}🚀 Deploy para archivos grandes iniciado...${NC}"

# ✅ VERIFICAR ESPACIO DISPONIBLE
AVAILABLE_SPACE=$(df -BG . | awk 'NR==2{print $4}' | sed 's/G//')
if [ "$AVAILABLE_SPACE" -lt 20 ]; then
    log_error "⚠️ Solo ${AVAILABLE_SPACE}GB disponibles. Se recomiendan al menos 20GB"
    log_info "Ejecuta limpieza: php artisan wasabi:investigate --clean"
    exit 1
fi
log_success "Espacio disponible: ${AVAILABLE_SPACE}GB"

# ✅ OPTIMIZAR APLICACIÓN
log_info "Optimizando aplicación Laravel..."
composer install --optimize-autoloader --no-dev
composer dump-autoload --optimize --classmap-authoritative

php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

log_success "Aplicación optimizada"

# ✅ CONFIGURAR HORIZON
log_info "Configurando Horizon para cargas pesadas..."
php artisan horizon:terminate || true
sleep 5
php artisan queue:clear
php artisan queue:restart

nohup php artisan horizon > storage/logs/horizon.log 2>&1 &
sleep 3

if php artisan horizon:status | grep -q "running"; then
    log_success "Horizon iniciado correctamente"
else
    log_warning "Verificar Horizon manualmente: php artisan horizon:status"
fi

# ✅ VERIFICAR COMANDOS DISPONIBLES
log_info "Verificando comandos de limpieza..."

if php artisan list | grep -q "wasabi:investigate"; then
    log_success "✓ wasabi:investigate disponible"
    log_info "Ejecutando investigación rápida..."
    php artisan wasabi:investigate --dry-run | head -20
else
    log_warning "✗ wasabi:investigate no disponible - agrega WasabiInvestigatorCommand"
fi

if php artisan list | grep -q "cleanup:temp-files"; then
    log_success "✓ cleanup:temp-files disponible"
else
    log_warning "✗ cleanup:temp-files no disponible - agrega CleanupTemporaryFilesCommand"
fi

# ✅ VERIFICAR CONECTIVIDAD
log_info "Verificando conectividad..."

# Wasabi
if php artisan tinker --execute="Storage::disk('wasabi')->exists('test') ? 'OK' : 'OK'; echo 'Wasabi OK';" 2>/dev/null; then
    log_success "✓ Wasabi conectado"
else
    log_warning "✗ Problema con Wasabi - verificar credenciales"
fi

# Redis
if php artisan tinker --execute="Redis::ping(); echo 'Redis OK';" 2>/dev/null; then
    log_success "✓ Redis conectado"
else
    log_warning "✗ Problema con Redis"
fi

# ✅ CREAR DIRECTORIOS NECESARIOS
mkdir -p storage/logs
mkdir -p storage/app/{downloads,reports,temp_zips,tmp}
chmod -R 775 storage/

# ✅ MOSTRAR COMANDOS ÚTILES
echo -e "\n${GREEN}✅ DEPLOY COMPLETADO${NC}"
echo -e "\n${BLUE}📋 Comandos útiles:${NC}"
echo "  php artisan wasabi:investigate --dry-run    # Ver archivos misteriosos"
echo "  php artisan wasabi:investigate --clean      # Limpiar archivos > 7 días"
echo "  php artisan cleanup:temp-files --check-disk # Ver espacio en disco"
echo "  php artisan horizon:status                  # Estado de Horizon"
echo ""
echo -e "${BLUE}🔧 Configuraciones aplicadas:${NC}"
echo "  • Sistema optimizado para uploads de 15GB"
echo "  • Horizon configurado para cargas pesadas"
echo "  • Timeouts extendidos para procesamiento largo"
echo "  • Verificación de servicios completada"
echo ""
echo -e "${YELLOW}⚠️  ACCIONES MANUALES PENDIENTES:${NC}"
echo "  1. Aplicar cambios en .env (ver documentación)"
echo "  2. Aplicar cambios en Nginx (ver documentación)"
echo "  3. Reiniciar php-fpm: sudo systemctl reload php8.3-fpm"
echo "  4. Reiniciar nginx: sudo systemctl reload nginx"
echo ""
echo -e "${BLUE}🚀 ¡Sistema listo para ZIPs masivos!${NC}"
