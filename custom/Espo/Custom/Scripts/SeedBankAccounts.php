<?php

/**
 * Script de Inicialización y Poblado (Seed) de Cuentas Bancarias
 * Módulo 06: Conciliación de Cobros y Cuentas Bancarias (TASK-037)
 */

chdir('/var/www/html');
require_once 'bootstrap.php';

use Espo\Core\Application;

$app = new Application();
$container = $app->getContainer();
$entityManager = $container->get('entityManager');

$systemUser = $entityManager->getEntity('User', 'system');
if ($systemUser) {
    $container->set('user', $systemUser);
}

echo "=== Iniciando Poblado de Cuentas Bancarias Operativas ===\n";

$accounts = [
    [
        'name'                 => 'BCP Dólares Corriente Empresa',
        'bankName'             => 'BCP',
        'country'              => 'PER',
        'currency'             => 'USD',
        'accountHolder'        => 'DESTINOS VIAJES S.A.C.',
        'accountType'          => 'Corriente',
        'accountNumber'        => '194-98765432-1-89',
        'cci'                  => '00219400987654321890',
        'swiftBic'             => 'BCPLPEPL',
        'intermediaryBankInfo' => 'Citibank New York - SWIFT: CITIUS33',
        'instructions'         => 'Transferencias locales en USD vía BCP o interbancarias vía CCI. Colocar la referencia de cobro en el concepto.',
        'isActive'             => true,
    ],
    [
        'name'                 => 'BBVA Soles Corriente Empresa',
        'bankName'             => 'BBVA',
        'country'              => 'PER',
        'currency'             => 'PEN',
        'accountHolder'        => 'DESTINOS VIAJES S.A.C.',
        'accountType'          => 'Corriente',
        'accountNumber'        => '0011-0123-0100045678',
        'cci'                  => '01112300010004567890',
        'swiftBic'             => 'BBVAPELM',
        'intermediaryBankInfo' => null,
        'instructions'         => 'Transferencias locales en Soles (PEN). Acepta transferencias interbancarias inmediatas CCI.',
        'isActive'             => true,
    ],
    [
        'name'                 => 'Interbank Dólares Corriente',
        'bankName'             => 'Interbank',
        'country'              => 'PER',
        'currency'             => 'USD',
        'accountHolder'        => 'DESTINOS VIAJES S.A.C.',
        'accountType'          => 'Corriente',
        'accountNumber'        => '200-3001234567',
        'cci'                  => '00320000300123456789',
        'swiftBic'             => 'BINSPEPL',
        'intermediaryBankInfo' => 'JPMorgan Chase Bank NY - SWIFT: CHASUS33',
        'instructions'         => 'Cuenta alternativa de recaudación en dólares americanos.',
        'isActive'             => true,
    ]
];

$repository = $entityManager->getRDBRepository('BankAccount');

foreach ($accounts as $data) {
    $existing = $repository->where([
        'accountNumber' => $data['accountNumber'],
        'deleted'       => false
    ])->findOne();

    if ($existing) {
        echo "  [i] La cuenta '{$data['name']}' ({$data['currency']}) ya existe con ID: " . $existing->getId() . "\n";
        continue;
    }

    $account = $entityManager->getNewEntity('BankAccount');
    $account->set($data);
    $entityManager->saveEntity($account);

    echo "  [✔] Cuenta creada: '{$data['name']}' | Moneda: {$data['currency']} | ID: " . $account->getId() . "\n";
}

echo "=== Poblado de Cuentas Finalizado Exitosamente ===\n";
