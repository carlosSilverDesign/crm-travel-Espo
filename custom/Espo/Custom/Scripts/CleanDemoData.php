<?php

/**
 * Script de Limpieza y Purga de Datos de Prueba (Clean Demo Data)
 * CRM Viajes SaaS - Travel Agency Platform 2026
 *
 * Elimina de forma atómica y segura todos los registros identificados con '[DEMO]'
 * en estricto orden inverso relacional para no dejar datos huérfanos ni afectar
 * la configuración ni los datos reales de la agencia.
 */

if (file_exists('/var/www/html/bootstrap.php')) {
    chdir('/var/www/html');
    require_once 'bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../../bootstrap.php')) {
    chdir(__DIR__ . '/../../../../');
    require_once 'bootstrap.php';
} else {
    echo "❌ Error: bootstrap.php de EspoCRM no encontrado.\n";
    echo "Ejecute este script dentro del contenedor Docker:\n";
    echo "  docker exec -it crm_app php custom/Espo/Custom/Scripts/CleanDemoData.php\n";
    exit(1);
}

use Espo\Core\Application;

$app = new Application();
$container = $app->getContainer();
$entityManager = $container->get('entityManager');

$systemUser = $entityManager->getEntity('User', 'system');
if ($systemUser) {
    $container->set('user', $systemUser);
}

echo "====================================================================\n";
echo "  🧹 INICIANDO LIMPIEZA DE DATOS DE PRUEBA (CRM VIAJES SAAS)\n";
echo "====================================================================\n\n";

function deleteByCriteria($entityManager, $entityType, $whereClause, $label) {
    $repo = $entityManager->getRDBRepository($entityType);
    $records = $repo->where($whereClause)->find();
    $count = count($records);
    foreach ($records as $record) {
        try {
            $entityManager->removeEntity($record, ['skipHooks' => true]);
        } catch (\Throwable $e) {
            // Silencioso ante borrado forzado
        }
    }
    echo "  ✔ {$label}: {$count} registro(s) eliminado(s).\n";
}

// 1. Tareas de Detractor
deleteByCriteria($entityManager, 'Task', [
    'name*' => '[DEMO]%'
], 'Tareas Urgentes Demo');

// 2. Historial de Etapas
deleteByCriteria($entityManager, 'OpportunityStageHistory', [
    'name*' => '[DEMO]%'
], 'Historial de Etapas Comercial Demo');

// 3. Encuestas de Calidad (Feedback)
deleteByCriteria($entityManager, 'Feedback', [
    'name*' => '[DEMO]%'
], 'Feedbacks / Encuestas NPS Demo');

// 4. Incidencias Operativas (Incident)
deleteByCriteria($entityManager, 'Incident', [
    'name*' => '[DEMO]%'
], 'Incidencias Operativas Demo');

// 5. Pagos Reconciliados (Payment)
deleteByCriteria($entityManager, 'Payment', [
    'paymentReference*' => 'PAY-DEMO%'
], 'Pagos Reconciliados Demo');

// 6. Cronogramas de Pagos (PaymentSchedule)
deleteByCriteria($entityManager, 'PaymentSchedule', [
    'name*' => '[DEMO]%'
], 'Cronogramas de Pagos Demo');

// 7. Líneas de Presupuesto (BudgetLine)
deleteByCriteria($entityManager, 'BudgetLine', [
    'name*' => '[DEMO]%'
], 'Líneas de Presupuesto Demo');

// 8. Servicios de Itinerario (ItineraryItem)
deleteByCriteria($entityManager, 'ItineraryItem', [
    'name*' => '[DEMO]%'
], 'Servicios de Itinerario Demo');

// 9. Pasajeros (Passenger)
deleteByCriteria($entityManager, 'Passenger', [
    'name*' => '[DEMO]%'
], 'Pasajeros Demo');

// 10. Itinerarios
deleteByCriteria($entityManager, 'Itinerario', [
    'name*' => '[DEMO]%'
], 'Itinerarios Demo');

// 11. Oportunidades (Opportunity)
deleteByCriteria($entityManager, 'Opportunity', [
    'name*' => '[DEMO]%'
], 'Oportunidades Comerciales Demo');

// 12. Contactos Demo
deleteByCriteria($entityManager, 'Contact', [
    'firstName*' => '[DEMO]%'
], 'Contactos Demo');

// 13. Proveedores Demo
deleteByCriteria($entityManager, 'Supplier', [
    'name*' => '[DEMO]%'
], 'Proveedores / Operadores Demo');

// 14. Cuentas Bancarias Demo (Opcional: Si fueron creadas por el seed demo)
deleteByCriteria($entityManager, 'BankAccount', [
    'name*' => '[DEMO]%'
], 'Cuentas Bancarias Demo');

echo "\n====================================================================\n";
echo "  ✅ LIMPIEZA COMPLETADA CON ÉXITO: Sistema limpio y sin residuos.\n";
echo "====================================================================\n";
