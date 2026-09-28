#!/usr/bin/env bash
# ==============================================================================
# Helper Script: Poblar Datos de Demostración en CRM Viajes SaaS
# ==============================================================================
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "🚀 Iniciando asistente de poblado de datos de prueba..."

# Verificar si Docker está ejecutando el contenedor crm_app
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^crm_app$"; then
    echo "📦 Contenedor 'crm_app' detectado en ejecución. Ejecutando dentro del contenedor..."
    docker exec -it crm_app php custom/Espo/Custom/Scripts/SeedDemoData.php
elif command -v php >/dev/null 2>&1 && [ -f "$SCRIPT_DIR/bootstrap.php" ]; then
    echo "💻 Ejecutando directamente en entorno PHP local..."
    php "$SCRIPT_DIR/custom/Espo/Custom/Scripts/SeedDemoData.php"
else
    echo "⚠️  El contenedor 'crm_app' no está corriendo en Docker actualmente."
    echo ""
    echo "Para iniciar los servicios y cargar los datos de prueba, ejecute:"
    echo "  1. docker compose up -d"
    echo "  2. ./seed-demo.sh"
    echo ""
    echo "O directamente vía Docker:"
    echo "  docker exec -it crm_app php custom/Espo/Custom/Scripts/SeedDemoData.php"
    exit 1
fi
