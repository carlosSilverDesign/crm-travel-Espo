#!/usr/bin/env bash
# ==============================================================================
# Helper Script: Limpiar / Purgar Datos de Demostración en CRM Viajes SaaS
# ==============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "🧹 Iniciando purga de datos de prueba..."

# Verificar si Docker está ejecutando el contenedor crm_app
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^crm_app$"; then
    echo "📦 Contenedor 'crm_app' detectado en ejecución. Ejecutando limpieza..."
    docker exec -it crm_app php custom/Espo/Custom/Scripts/CleanDemoData.php
elif command -v php >/dev/null 2>&1 && [ -f "$SCRIPT_DIR/bootstrap.php" ]; then
    echo "💻 Ejecutando limpieza directamente en entorno PHP local..."
    php "$SCRIPT_DIR/custom/Espo/Custom/Scripts/CleanDemoData.php"
else
    echo "⚠️  El contenedor 'crm_app' no está corriendo en Docker actualmente."
    echo ""
    echo "Para purgar los datos de prueba una vez iniciado Docker, ejecute:"
    echo "  1. docker compose up -d"
    echo "  2. ./clean-demo.sh"
    echo ""
    echo "O directamente vía Docker:"
    echo "  docker exec -it crm_app php custom/Espo/Custom/Scripts/CleanDemoData.php"
    exit 1
fi
