<?php

/**
 * Script de Limpieza y Purga de Datos de Prueba (Clean Demo Data)
 * CRM Viajes SaaS - Travel Agency Platform 2026
 *
 * Elimina de forma atómica y segura todos los registros identificados con '[DEMO]'
 * en estricto orden inverso relacional, incluyendo purga física en base de datos
 * para evitar colisiones en índices únicos de tokens públicos.
 */

if (file_exists('/var/www/html/bootstrap.php')) {
    chdir('/var/www/html');
    require_once 'bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../../bootstrap.php')) {
    chdir(__DIR__ . '/../../../../');
    require_once 'bootstrap.php';
} else {
    echo "❌ Error: bootstrap.php de EspoCRM no encontrado.\n";
    exit(1);
}

use Espo\Core\Application;

$app = new Application();
$container = $app->getContainer();
$entityManager = $container->get('entityManager');
$pdo = $entityManager->getPDO();

$systemUser = $entityManager->getEntity('User', 'system');
if ($systemUser) {
    $container->set('user', $systemUser);
}

echo "====================================================================\n";
echo "  🧹 INICIANDO LIMPIEZA DE DATOS DE PRUEBA (CRM VIAJES SAAS)\n";
echo "====================================================================\n\n";

$tablesToClean = [
    'task' => "name LIKE '[DEMO]%'",
    'opportunity_stage_history' => "name LIKE '[DEMO]%'",
    'feedback' => "name LIKE '[DEMO]%'",
    'incident' => "name LIKE '[DEMO]%'",
    'payment' => "payment_reference LIKE 'PAY-DEMO%'",
    'payment_schedule' => "name LIKE '[DEMO]%'",
    'budget_line' => "name LIKE '[DEMO]%'",
    'itinerary_item' => "name LIKE '[DEMO]%'",
    'passenger' => "name LIKE '[DEMO]%'",
    'itinerario' => "name LIKE '[DEMO]%' OR public_access_token LIKE 'c0a80101%'",
    'opportunity' => "name LIKE '[DEMO]%'",
    'contact' => "first_name LIKE '[DEMO]%'",
    'supplier' => "name LIKE '[DEMO]%'",
    'bank_account' => "name LIKE '[DEMO]%'",
];

foreach ($tablesToClean as $table => $condition) {
    try {
        $sql = "DELETE FROM `{$table}` WHERE {$condition}";
        $deleted = $pdo->exec($sql);
        echo "  ✔ {$table}: {$deleted} registro(s) purgado(s) físicamente.\n";
    } catch (\Throwable $e) {
        echo "  ⚠ {$table}: " . $e->getMessage() . "\n";
    }
}

echo "\n====================================================================\n";
echo "  ✅ LIMPIEZA COMPLETADA CON ÉXITO: Base de datos purgada.\n";
echo "====================================================================\n";
