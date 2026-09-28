<?php

/**
 * Script de Configuración de Interfaz, Pestañas, Dashboards y Roles
 * CRM Viajes SaaS - Travel Agency Platform 2026
 *
 * 1. Configura la barra de pestañas (tabList) con las entidades de la agencia de viajes.
 * 2. Diseña la Home (Inicio) con los Dashlets de viajes y analítica.
 * 3. Crea y asegura el Rol 'Asesor de Viajes' (restringiendo configuración y borrado).
 * 4. Limpia la caché y actualiza preferencias.
 */

if (file_exists('/var/www/html/bootstrap.php')) {
    chdir('/var/www/html');
    require_once 'bootstrap.php';
} elseif (file_exists(__DIR__ . '/../../../../bootstrap.php')) {
    chdir(__DIR__ . '/../../../../');
    require_once 'bootstrap.php';
} else {
    echo "❌ Error: bootstrap.php no encontrado.\n";
    exit(1);
}

use Espo\Core\Application;

$app = new Application();
$container = $app->getContainer();
$entityManager = $container->get('entityManager');
$config = $container->get('config');

$systemUser = $entityManager->getEntity('User', 'system');
if ($systemUser) {
    $container->set('user', $systemUser);
}

echo "====================================================================\n";
echo "  🛠️  CONFIGURANDO INTERFAZ DE VIAJES, PESTAÑAS, DASHBOARDS Y ROLES\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. CONFIGURACIÓN DE PESTAÑAS (tabList)
// -----------------------------------------------------------------------------
echo "▶ [1/4] Configurando menú de navegación y pestañas de la agencia...\n";

$newTabList = [
    [
        'type' => 'divider',
        'id'   => 'div_travel_ops',
        'text' => 'Operaciones Turísticas'
    ],
    'Itinerario',
    'Passenger',
    'Supplier',
    'PackageTemplate',
    [
        'type' => 'divider',
        'id'   => 'div_sales_clients',
        'text' => 'Comercial & Clientes'
    ],
    'Opportunity',
    'Contact',
    'Account',
    [
        'type' => 'divider',
        'id'   => 'div_finances',
        'text' => 'Finanzas & Cobros'
    ],
    'Payment',
    'PaymentSchedule',
    'BankAccount',
    [
        'type' => 'divider',
        'id'   => 'div_quality',
        'text' => 'Calidad & Seguimiento'
    ],
    'Incident',
    'Feedback',
    'Task',
    '_delimiter-ext_'
];

$config->set('tabList', $newTabList);

// -----------------------------------------------------------------------------
// 2. CONFIGURACIÓN DEL DASHBOARD DE LA HOME (Inicio)
// -----------------------------------------------------------------------------
echo "▶ [2/4] Configurando Home con Dashlets de viajes y analítica...\n";

$newDashboardLayout = [
    [
        'name' => 'Panel del Asesor de Viajes',
        'layout' => [
            [
                'id'     => 'dashlet-pipeline-funnel',
                'name'   => 'PipelineFunnel',
                'x'      => 0,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-real-profitability',
                'name'   => 'RealProfitability',
                'x'      => 2,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-itineraries-list',
                'name'   => 'Records',
                'x'      => 0,
                'y'      => 4,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-urgent-tasks',
                'name'   => 'Tasks',
                'x'      => 2,
                'y'      => 4,
                'width'  => 2,
                'height' => 4
            ]
        ]
    ],
    [
        'name' => 'Analítica de Negocio',
        'layout' => [
            [
                'id'     => 'dashlet-destination-quality',
                'name'   => 'DestinationQuality',
                'x'      => 0,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-agent-performance',
                'name'   => 'AgentPerformance',
                'x'      => 2,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-pipeline-funnel-2',
                'name'   => 'PipelineFunnel',
                'x'      => 0,
                'y'      => 4,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'dashlet-real-profitability-2',
                'name'   => 'RealProfitability',
                'x'      => 2,
                'y'      => 4,
                'width'  => 2,
                'height' => 4
            ]
        ]
    ],
    [
        'name' => 'Flujo & Actividades',
        'layout' => [
            [
                'id'     => 'default-stream',
                'name'   => 'Stream',
                'x'      => 0,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ],
            [
                'id'     => 'default-activities',
                'name'   => 'Activities',
                'x'      => 2,
                'y'      => 0,
                'width'  => 2,
                'height' => 4
            ]
        ]
    ]
];

$dashletsOptions = (object) [
    'dashlet-itineraries-list' => (object) [
        'title'          => 'Expedientes de Viaje Recientes',
        'entityType'     => 'Itinerario',
        'displayRecords' => 5,
        'sortBy'         => 'startDate',
        'sortDirection'  => 'desc'
    ],
    'dashlet-urgent-tasks' => (object) [
        'title'          => 'Alertas y Tareas Urgentes',
        'displayRecords' => 5
    ]
];

$config->set('dashboardLayout', $newDashboardLayout);
$config->set('dashletsOptions', $dashletsOptions);
$config->save();

echo "   ✔ Pestañas y Dashboard guardados en configuración global.\n";

// -----------------------------------------------------------------------------
// 3. ROL 'ASESOR DE VIAJES' Y CONTROL DE ACCESO (ACL)
// -----------------------------------------------------------------------------
echo "▶ [3/4] Creando / Configurando Rol 'Asesor de Viajes'...\n";

$roleRepo = $entityManager->getRDBRepository('Role');
$role = $roleRepo->where(['name' => 'Asesor de Viajes'])->findOne();

if (!$role) {
    // Buscar rol 'Agent' anterior si existe
    $role = $roleRepo->where(['name' => 'Agent'])->findOne();
}

if (!$role) {
    $role = $entityManager->getNewEntity('Role');
    $role->set('name', 'Asesor de Viajes');
}

// Configurar permisos estrictos para Asesores:
// - Pueden crear y editar itinerarios, cotizaciones, cobros y pasajeros
// - NO pueden borrar registros (prevención de pérdida de expedientes)
// - NO pueden editar proveedores ni cuentas bancarias maestras
// - NO tienen acceso a Administración ni usuarios del sistema
$roleData = [
    'Itinerario' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Opportunity' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Passenger' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Payment' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'PaymentSchedule' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'BudgetLine' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'ItineraryItem' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Incident' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Feedback' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Supplier' => [
        'create' => 'no',
        'read'   => 'all',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'PackageTemplate' => [
        'create' => 'no',
        'read'   => 'all',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'BankAccount' => [
        'create' => 'no',
        'read'   => 'all',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'no'
    ],
    'Contact' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'Account' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ],
    'User' => [
        'create' => 'no',
        'read'   => 'team',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'no'
    ],
    'Team' => [
        'create' => 'no',
        'read'   => 'team',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'no'
    ],
    'Lead' => [
        'create' => 'no',
        'read'   => 'no',
        'edit'   => 'no',
        'delete' => 'no',
        'stream' => 'no'
    ],
    'Task' => [
        'create' => 'yes',
        'read'   => 'all',
        'edit'   => 'all',
        'delete' => 'no',
        'stream' => 'all'
    ]
];

$role->set([
    'name'                 => 'Asesor de Viajes',
    'assignmentPermission' => 'all',
    'userPermission'       => 'no',
    'portalPermission'     => 'no',
    'exportPermission'     => 'yes',
    'massUpdatePermission' => 'no',
    'data'                 => (object) $roleData
]);

$entityManager->saveEntity($role);

echo "   ✔ Rol 'Asesor de Viajes' configurado con permisos y restricciones seguras.\n";

// Crear o actualizar usuario de prueba 'asesor' con contraseña 'asesor123'
$userRepo = $entityManager->getRDBRepository('User');
$agentUser = $userRepo->where(['userName' => 'asesor'])->findOne();
if (!$agentUser) {
    $agentUser = $entityManager->getNewEntity('User');
    $agentUser->set([
        'userName' => 'asesor',
        'firstName' => 'Carlos',
        'lastName' => 'Asesor',
        'type' => 'regular',
        'emailAddress' => 'asesor@agencia.demo',
        'isActive' => true,
    ]);
    if ($container->has('passwordHash')) {
        $agentUser->set('password', $container->get('passwordHash')->hash('asesor123'));
    } else {
        $agentUser->set('password', password_hash('asesor123', PASSWORD_BCRYPT));
    }
    $entityManager->saveEntity($agentUser);
    echo "   ✔ Usuario 'asesor' (clave: asesor123) creado correctamente.\n";
} else {
    // Asegurar contraseña 'asesor123'
    if ($container->has('passwordHash')) {
        $agentUser->set('password', $container->get('passwordHash')->hash('asesor123'));
    } else {
        $agentUser->set('password', password_hash('asesor123', PASSWORD_BCRYPT));
    }
    $agentUser->set('isActive', true);
    $entityManager->saveEntity($agentUser);
    echo "   ✔ Usuario 'asesor' actualizado con credencial activa (clave: asesor123).\n";
}

$entityManager->getRDBRepository('Role')->getRelation($role, 'users')->relate($agentUser);

$agents = $userRepo->where(['type' => 'regular'])->find();
foreach ($agents as $agent) {
    $entityManager->getRDBRepository('Role')->getRelation($role, 'users')->relate($agent);
}

// -----------------------------------------------------------------------------
// 4. LIMPIEZA DE PREFERENCIAS ANTIGUAS Y RECONSTRUCCIÓN DE CACHÉ
// -----------------------------------------------------------------------------
echo "▶ [4/4] Limpiando caché y aplicando nueva interfaz a todos los usuarios...\n";

// Limpiar preferencias de usuario antiguas para que adopten el nuevo dashboard
try {
    $pdo = $container->get('pdo');
    $pdo->exec("DELETE FROM preferences WHERE data LIKE '%dashboardLayout%' OR data LIKE '%tabList%'");
} catch (\Throwable $e) {
    // Continuar si no aplica
}

// Reconstruir metadatos y caché de EspoCRM
$dataManager = $container->get('dataManager');
if ($dataManager && method_exists($dataManager, 'rebuild')) {
    $dataManager->rebuild();
}

echo "\n====================================================================\n";
echo "  ✅ CONFIGURACIÓN COMPLETADA CON ÉXITO\n";
echo "====================================================================\n";
echo "  1. La barra lateral ahora incluye: Itinerarios, Pasajeros, Proveedores,\n";
echo "     Cobros/Pagos, Incidencias, Encuestas NPS y Oportunidades.\n";
echo "  2. La 'Home' ahora tiene 3 pestañas:\n";
echo "     - 'Panel del Asesor de Viajes': Embudo, Rentabilidad, Expedientes y Tareas.\n";
echo "     - 'Analítica de Negocio': Desempeño de Asesores, Calidad y Satisfacción.\n";
echo "     - 'Flujo & Actividades': Colaboración interna.\n";
echo "  3. Rol 'Asesor de Viajes':\n";
echo "     - Solo usuarios 'admin' tienen acceso a la tuerca/Configuración.\n";
echo "     - Los Asesores no pueden borrar expedientes ni alterar cuentas bancarias.\n";
echo "====================================================================\n";
