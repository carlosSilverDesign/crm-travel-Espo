<?php

/**
 * Script de Poblado (Seed) de Datos de Prueba para Demostración y Preventa
 * CRM Viajes SaaS - Travel Agency Platform 2026
 *
 * Crea expedientes completos y representativos con:
 * - 6 Casuísticas operativas (En Viaje, Cotización, Confirmado, Post-Viaje Promotor, Incidencia/Detractor, Descarte)
 * - Líneas de Presupuesto con cálculo de márgenes y comisiones
 * - Cronogramas de pago y conciliación bancaria
 * - Pasajeros y validaciones documentarias
 * - Incidencias en viaje con impacto financiero
 * - Encuestas de satisfacción NPS (Promotor y Detractor con SLA urgente < 2h)
 * - Historial de etapas para embudo de conversión y dashlets ECharts
 *
 * Todos los registros contienen el prefijo '[DEMO]' para borrado fácil y seguro.
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
    echo "  docker exec -it crm_app php custom/Espo/Custom/Scripts/SeedDemoData.php\n";
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

$now = time();
$today = date('Y-m-d');
$todayTime = date('Y-m-d H:i:s');

echo "====================================================================\n";
echo "  🚀 INICIANDO POBLADO DE DATOS DE DEMOSTRACIÓN (CRM VIAJES SAAS)\n";
echo "====================================================================\n\n";

// Helper para buscar o crear registros demo
function getOrCreate($entityManager, $entityType, $criteria, $data) {
    $existing = $entityManager->getRDBRepository($entityType)->where($criteria)->findOne();
    if ($existing) {
        return $existing;
    }
    $entity = $entityManager->getNewEntity($entityType);
    $entity->set($data);
    $entityManager->saveEntity($entity, ['skipHooks' => true]);
    return $entity;
}

// -----------------------------------------------------------------------------
// 1. PROVEEDORES Y OPERADORES LOCALES (Suppliers)
// -----------------------------------------------------------------------------
echo "▶ [1/8] Verificando / Creando Proveedores y Operadores...\n";

$supplierRiu = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Riu Palace Riviera Maya'], [
    'name' => '[DEMO] Riu Palace Riviera Maya',
    'supplierType' => 'Hotel',
    'contactEmail' => 'reservas@riu-palace.demo',
    'contactPhone' => '+52 984 877 2200',
    'paymentTerms' => 'Prepago 15 días antes del check-in'
]);

$supplierInca = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Inca Rail & Tours Receptivo'], [
    'name' => '[DEMO] Inca Rail & Tours Receptivo',
    'supplierType' => 'Operador Local',
    'contactEmail' => 'operaciones@incarail.demo',
    'contactPhone' => '+51 84 581 860',
    'paymentTerms' => 'Crédito a 30 días, liquidación mensual'
]);

$supplierLatam = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] LATAM Airlines Group'], [
    'name' => '[DEMO] LATAM Airlines Group',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'agencias@latam.demo',
    'contactPhone' => '+51 1 213 8200',
    'paymentTerms' => 'Emisión inmediata GDS'
]);

$supplierAssist = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Assist Card Internacional'], [
    'name' => '[DEMO] Assist Card Internacional',
    'supplierType' => 'Seguros',
    'contactEmail' => 'emisiones@assistcard.demo',
    'contactPhone' => '+1 800 874 2823',
    'paymentTerms' => 'Liquidación quincenal'
]);

$supplierPatagonia = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Patagonia Wilderness Receptivo'], [
    'name' => '[DEMO] Patagonia Wilderness Receptivo',
    'supplierType' => 'Operador Local',
    'contactEmail' => 'info@patagoniawilderness.demo',
    'contactPhone' => '+54 2902 491 500',
    'paymentTerms' => 'Seña del 30% a la reserva'
]);

echo "   ✔ Proveedores listos.\n";

// -----------------------------------------------------------------------------
// 2. CUENTAS BANCARIAS OPERATIVAS (BankAccounts)
// -----------------------------------------------------------------------------
echo "▶ [2/8] Verificando Cuentas Bancarias Operativas...\n";

$bankBcp = getOrCreate($entityManager, 'BankAccount', ['accountNumber' => '194-98765432-1-89'], [
    'name' => '[DEMO] BCP Dólares Corriente Empresa',
    'bankName' => 'BCP',
    'country' => 'PER',
    'currency' => 'USD',
    'accountHolder' => 'DESTINOS VIAJES S.A.C.',
    'accountType' => 'Corriente',
    'accountNumber' => '194-98765432-1-89',
    'cci' => '00219400987654321890',
    'swiftBic' => 'BCPLPEPL',
    'instructions' => 'Transferencias locales en USD vía BCP o interbancarias vía CCI.',
    'isActive' => true
]);

$bankBbva = getOrCreate($entityManager, 'BankAccount', ['accountNumber' => '0011-0123-0100045678'], [
    'name' => '[DEMO] BBVA Soles Corriente Empresa',
    'bankName' => 'BBVA',
    'country' => 'PER',
    'currency' => 'PEN',
    'accountHolder' => 'DESTINOS VIAJES S.A.C.',
    'accountType' => 'Corriente',
    'accountNumber' => '0011-0123-0100045678',
    'cci' => '01112300010004567890',
    'swiftBic' => 'BBVAPELM',
    'instructions' => 'Transferencias locales en Soles (PEN).',
    'isActive' => true
]);

echo "   ✔ Cuentas Bancarias operativas verificadas.\n";

// -----------------------------------------------------------------------------
// 3. CONTACTOS / VIAJEROS (Contacts)
// -----------------------------------------------------------------------------
echo "▶ [3/8] Creando Contactos y Viajeros Frecuentes...\n";

$contactCarlos = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'carlos.mendoza@demo.viajes'], [
    'firstName' => '[DEMO] Carlos',
    'lastName' => 'Mendoza V.',
    'emailAddress' => 'carlos.mendoza@demo.viajes',
    'phoneNumber' => '+51987654321',
    'description' => '[DEMO_DATA] Titular de viaje familiar a Cusco. Viajero frecuente VIP.'
]);

$contactLucia = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'lucia.fernandez@demo.viajes'], [
    'firstName' => '[DEMO] Lucía',
    'lastName' => 'Fernández G.',
    'emailAddress' => 'lucia.fernandez@demo.viajes',
    'phoneNumber' => '+5491187654321',
    'description' => '[DEMO_DATA] Interesada en turismo aventura en Patagonia.'
]);

$contactAndres = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'andres.bustamante@demo.viajes'], [
    'firstName' => '[DEMO] Andrés',
    'lastName' => 'Bustamante R.',
    'emailAddress' => 'andres.bustamante@demo.viajes',
    'phoneNumber' => '+529987654321',
    'description' => '[DEMO_DATA] Reserva paquete Caribe Cancún todo incluido.'
]);

$contactMarcela = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'marcela.pena@demo.viajes'], [
    'firstName' => '[DEMO] Marcela',
    'lastName' => 'Peña S.',
    'emailAddress' => 'marcela.pena@demo.viajes',
    'phoneNumber' => '+56987654321',
    'description' => '[DEMO_DATA] Regresó de París. Muy satisfecha (Promotora NPS 10).'
]);

$contactRoberto = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'roberto.benavides@demo.viajes'], [
    'firstName' => '[DEMO] Roberto',
    'lastName' => 'Benavides T.',
    'emailAddress' => 'roberto.benavides@demo.viajes',
    'phoneNumber' => '+51912345678',
    'description' => '[DEMO_DATA] Viaje a Egipto. Sufrió overbooking de camarote (Detractor NPS 4).'
]);

$contactJavier = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'javier.morales@demo.viajes'], [
    'firstName' => '[DEMO] Javier',
    'lastName' => 'Morales H.',
    'emailAddress' => 'javier.morales@demo.viajes',
    'phoneNumber' => '+51999887766',
    'description' => '[DEMO_DATA] Consulta Safari Tanzania descartada por presupuesto.'
]);

echo "   ✔ Contactos demo listos.\n";

// -----------------------------------------------------------------------------
// 4. CASO 1 (ESTRELLA): VIAJE EN CURSO (EN VIAJE) & RECONCILIADO AL 100%
// -----------------------------------------------------------------------------
echo "▶ [4/8] Generando Caso 1 (Estrella): 'Cusco Mágico VIP' - Estado: EN VIAJE...\n";

// Fechas que cubren el día actual para activar la consola en destino
$startTrip = date('Y-m-d', $now - (2 * 86400)); // Empezó hace 2 días
$endTrip = date('Y-m-d', $now + (3 * 86400));   // Termina en 3 días

$opp1 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Vacaciones en Familia: Cusco Mágico & Machu Picchu VIP'], [
    'name' => '[DEMO] Vacaciones en Familia: Cusco Mágico & Machu Picchu VIP',
    'stage' => 'Closed Won',
    'contactId' => $contactCarlos->getId(),
    'destination' => 'Cusco & Valle Sagrado, Perú',
    'leadSource' => 'WhatsApp',
    'whatsappChatId' => 'demo-cw-1001',
    'chatwootConversationId' => '1001',
    'travelStartDate' => $startTrip,
    'travelEndDate' => $endTrip,
    'amount' => 4000.00,
    'amountPaid' => 4000.00,
    'pendingBalance' => 0.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 800.00,
    'clientType' => 'B2C_Direct',
    'description' => '[DEMO_DATA] Expediente en destino activo. Demuestra consola operativa y expediente web/PDF.'
]);

$itinerario1 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Cusco Mágico, Valle Sagrado & Machu Picchu VIP'], [
    'name' => '[DEMO] Cusco Mágico, Valle Sagrado & Machu Picchu VIP',
    'destination' => 'Cusco & Valle Sagrado, Perú',
    'status' => 'En Viaje',
    'opportunityId' => $opp1->getId(),
    'startDate' => $startTrip,
    'endDate' => $endTrip,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (30 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 3200.00,
    'totalSelling' => 4000.00,
    'grossProfit' => 800.00,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000001', // Token UUID accesible para pruebas
    'description' => '[DEMO_DATA] Expediente de demostración principal. Compatible con /p/demo y vista PDF.'
]);

// Pasajeros del caso 1
$pax1 = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Carlos Mendoza', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Carlos Mendoza',
    'itinerarioId' => $itinerario1->getId(),
    'contactId' => $contactCarlos->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-772819',
    'documentExpiration' => date('Y-m-d', $now + (365 * 86400)),
    'birthDate' => '1984-05-12',
    'nationality' => 'Peruana',
    'dietaryRestrictions' => 'Ninguna'
]);

$pax2 = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Valeria Soto', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Valeria Soto',
    'itinerarioId' => $itinerario1->getId(),
    'contactId' => $contactCarlos->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-883920',
    'documentExpiration' => date('Y-m-d', $now + (400 * 86400)),
    'birthDate' => '1987-09-23',
    'nationality' => 'Peruana',
    'dietaryRestrictions' => 'Celíaca (Gluten Free)'
]);

$pax3 = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Mateo Mendoza (Hijo)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Mateo Mendoza (Hijo)',
    'itinerarioId' => $itinerario1->getId(),
    'contactId' => $contactCarlos->getId(),
    'documentType' => 'DNI',
    'documentNumber' => '72918273',
    'birthDate' => '2016-03-15',
    'nationality' => 'Peruana',
    'notes' => 'Menor de edad, viaja con ambos padres'
]);

// Servicios del Itinerario 1 (ItineraryItems)
getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo LIM-CUZ-LIM (LATAM LA-2194)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Vuelo LIM-CUZ-LIM (LATAM LA-2194)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierLatam->getId(),
    'serviceType' => 'Vuelo',
    'serviceDate' => $startTrip . ' 08:30:00',
    'confirmationCode' => 'LA-PNR-CUZ99',
    'status' => 'Emitido',
    'notes' => 'Equipaje de mano y bodega 23kg incluidos'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Alojamiento Palacio del Inka 5* (4 Noches)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Alojamiento Palacio del Inka 5* (4 Noches)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'serviceDate' => $startTrip . ' 14:00:00',
    'confirmationCode' => 'HTL-INKA-8821',
    'status' => 'Confirmado',
    'notes' => 'Suite Familiar con desayuno buffet andino incluido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Tour Privado Machu Picchu & Tren Vistadome', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Tour Privado Machu Picchu & Tren Vistadome',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Tour',
    'serviceDate' => date('Y-m-d', $now) . ' 06:00:00', // SERVICIO ACTIVO HOY!
    'confirmationCode' => 'TOUR-MP-4402',
    'status' => 'Confirmado',
    'notes' => 'Guía privado en español, entradas Circuito 2A y bus Consettur ida/vuelta'
]);

// Líneas de Presupuesto del Caso 1 (BudgetLines)
getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Vuelos LATAM Airlines (3 Pasajeros)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Vuelos LATAM Airlines (3 Pasajeros)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierLatam->getId(),
    'costPrice' => 600.00,
    'marginRate' => 20.00,
    'sellingPrice' => 720.00,
    'grossProfit' => 120.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Palacio del Inka Hotel & Spa (Suite 4N)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Palacio del Inka Hotel & Spa (Suite 4N)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'costPrice' => 1400.00,
    'marginRate' => 25.00,
    'sellingPrice' => 1750.00,
    'grossProfit' => 350.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Excursión Machu Picchu Vistadome + Guía', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Excursión Machu Picchu Vistadome + Guía',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'costPrice' => 800.00,
    'marginRate' => 25.00,
    'sellingPrice' => 1000.00,
    'grossProfit' => 200.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Traslados y Asistencia Assist Card', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Traslados y Asistencia Assist Card',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierAssist->getId(),
    'costPrice' => 400.00,
    'marginRate' => 32.50,
    'sellingPrice' => 530.00,
    'grossProfit' => 130.00
]);

// Cronograma de Cobros Caso 1 (PaymentSchedule)
$sched1A = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Cuota 1 (50% Seña Reserva) - Cusco', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Cuota 1 (50% Seña Reserva) - Cusco',
    'itinerarioId' => $itinerario1->getId(),
    'dueDate' => date('Y-m-d', $now - (20 * 86400)),
    'amount' => 2000.00,
    'status' => 'Pagado',
    'paymentMethod' => 'Transferencia',
    'transactionId' => 'BCP-TX-998811'
]);

$sched1B = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Cuota 2 (50% Saldo Final) - Cusco', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Cuota 2 (50% Saldo Final) - Cusco',
    'itinerarioId' => $itinerario1->getId(),
    'dueDate' => date('Y-m-d', $now - (5 * 86400)),
    'amount' => 2000.00,
    'status' => 'Pagado',
    'paymentMethod' => 'Transferencia',
    'transactionId' => 'BCP-TX-999432'
]);

// Pagos Reconciliados en Caso 1 (Payments)
getOrCreate($entityManager, 'Payment', ['paymentReference' => 'PAY-DEMO01'], [
    'name' => 'PAY-DEMO01',
    'paymentReference' => 'PAY-DEMO01',
    'opportunityId' => $opp1->getId(),
    'paymentScheduleId' => $sched1A->getId(),
    'amount' => 2000.00,
    'currency' => 'USD',
    'status' => 'Confirmed',
    'method' => 'bank_transfer',
    'destinationBankAccountId' => $bankBcp->getId(),
    'clientDeclaredAmount' => 2000.00,
    'clientOperationNumber' => 'BCP-OP-112233',
    'clientDeclaredDate' => date('Y-m-d', $now - (20 * 86400)),
    'verifiedAt' => date('Y-m-d H:i:s', $now - (19 * 86400)),
    'verificationNotes' => '[DEMO_DATA] Verificado contra extracto BCP Dólares sin diferencias.'
]);

getOrCreate($entityManager, 'Payment', ['paymentReference' => 'PAY-DEMO02'], [
    'name' => 'PAY-DEMO02',
    'paymentReference' => 'PAY-DEMO02',
    'opportunityId' => $opp1->getId(),
    'paymentScheduleId' => $sched1B->getId(),
    'amount' => 2000.00,
    'currency' => 'USD',
    'status' => 'Confirmed',
    'method' => 'bank_transfer',
    'destinationBankAccountId' => $bankBcp->getId(),
    'clientDeclaredAmount' => 2000.00,
    'clientOperationNumber' => 'BCP-OP-445566',
    'clientDeclaredDate' => date('Y-m-d', $now - (5 * 86400)),
    'verifiedAt' => date('Y-m-d H:i:s', $now - (4 * 86400)),
    'verificationNotes' => '[DEMO_DATA] Saldo total cancelado con éxito.'
]);

// Incidencia en Viaje Menor (Resuelta sin costo)
getOrCreate($entityManager, 'Incident', ['name' => '[DEMO] Retraso Transfer Valle Sagrado por Tráfico', 'opportunityId' => $opp1->getId()], [
    'name' => '[DEMO] Retraso Transfer Valle Sagrado por Tráfico',
    'opportunityId' => $opp1->getId(),
    'itinerarioId' => $itinerario1->getId(),
    'severity' => 'Low',
    'category' => 'SupplierFailure',
    'status' => 'Resolved',
    'costImpact' => 0.0,
    'resolutionPlan' => 'Se coordinó vehículo alterno con Inca Rail. Pasajeros llegaron a tiempo al tren sin contratiempos.',
    'resolvedAt' => date('Y-m-d H:i:s', $now - (1 * 86400))
]);

echo "   ✔ Caso 1 generado (En Viaje, 4K USD reconciliado, PNR LA-PNR-CUZ99).\n";

// -----------------------------------------------------------------------------
// 5. CASO 2: NEGOCIACIÓN ACTIVA / COTIZACIÓN (PATAGONIA)
// -----------------------------------------------------------------------------
echo "▶ [5/8] Generando Caso 2: Cotización en Negociación 'Patagonia & Calafate'...\n";

$startPata = date('Y-m-d', $now + (45 * 86400));
$endPata = date('Y-m-d', $now + (53 * 86400));

$opp2 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Aventura Glaciares: Patagonia & El Calafate 2026'], [
    'name' => '[DEMO] Aventura Glaciares: Patagonia & El Calafate 2026',
    'stage' => 'Negotiation',
    'contactId' => $contactLucia->getId(),
    'destination' => 'El Calafate, Argentina',
    'leadSource' => 'WhatsApp',
    'whatsappChatId' => 'demo-cw-1002',
    'chatwootConversationId' => '1002',
    'travelStartDate' => $startPata,
    'travelEndDate' => $endPata,
    'amount' => 5400.00,
    'amountPaid' => 0.00,
    'pendingBalance' => 5400.00,
    'financialStatus' => 'Unpaid',
    'projectedGrossProfit' => 1200.00,
    'clientType' => 'B2C_Direct',
    'description' => '[DEMO_DATA] Cotización enviada. Cliente solicitó opción con trekking Minitrekking sobre el glaciar.'
]);

$itinerario2 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Expediente Patagonia: El Calafate & Glaciar Perito Moreno'], [
    'name' => '[DEMO] Expediente Patagonia: El Calafate & Glaciar Perito Moreno',
    'destination' => 'El Calafate, Argentina',
    'status' => 'Cotización',
    'opportunityId' => $opp2->getId(),
    'startDate' => $startPata,
    'endDate' => $endPata,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (7 * 86400)), // Tarifa válida por 7 días
    'whatsappStatus' => 'Sent',
    'totalCost' => 4200.00,
    'totalSelling' => 5400.00,
    'grossProfit' => 1200.00,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000002',
    'description' => '[DEMO_DATA] Itinerario en cotización con alerta de vigencia de tarifas.'
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Vuelos Buenos Aires - El Calafate', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Vuelos Buenos Aires - El Calafate',
    'itinerarioId' => $itinerario2->getId(),
    'supplierId' => $supplierLatam->getId(),
    'costPrice' => 1200.00,
    'marginRate' => 20.00,
    'sellingPrice' => 1440.00,
    'grossProfit' => 240.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Hotel Boutique Posada Los Álamos (7N)', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Hotel Boutique Posada Los Álamos (7N)',
    'itinerarioId' => $itinerario2->getId(),
    'supplierId' => $supplierPatagonia->getId(),
    'costPrice' => 1800.00,
    'marginRate' => 30.00,
    'sellingPrice' => 2340.00,
    'grossProfit' => 540.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Safari Náutico & Trekking Perito Moreno', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Safari Náutico & Trekking Perito Moreno',
    'itinerarioId' => $itinerario2->getId(),
    'supplierId' => $supplierPatagonia->getId(),
    'costPrice' => 1200.00,
    'marginRate' => 35.00,
    'sellingPrice' => 1620.00,
    'grossProfit' => 420.00
]);

echo "   ✔ Caso 2 generado (En Negociación, Margen 1,200 USD proyectado).\n";

// -----------------------------------------------------------------------------
// 6. CASO 3: CONFIRMADO CON PAGO PARCIAL (RIVIERA MAYA)
// -----------------------------------------------------------------------------
echo "▶ [6/8] Generando Caso 3: Confirmado con Pago Parcial 50% 'Riviera Maya'...\n";

$startCancun = date('Y-m-d', $now + (20 * 86400));
$endCancun = date('Y-m-d', $now + (27 * 86400));

$opp3 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Caribe All Inclusive: Riviera Maya & Xcaret VIP'], [
    'name' => '[DEMO] Caribe All Inclusive: Riviera Maya & Xcaret VIP',
    'stage' => 'Closed Won',
    'contactId' => $contactAndres->getId(),
    'destination' => 'Riviera Maya, México',
    'leadSource' => 'Web',
    'travelStartDate' => $startCancun,
    'travelEndDate' => $endCancun,
    'amount' => 3600.00,
    'amountPaid' => 1800.00,
    'pendingBalance' => 1800.00,
    'financialStatus' => 'PartiallyPaid',
    'projectedGrossProfit' => 800.00,
    'clientType' => 'B2C_Direct',
    'description' => '[DEMO_DATA] Seña recibida. Falta cobro del saldo 15 días antes del vuelo.'
]);

$itinerario3 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Riviera Maya & Parque Xcaret Todo Incluido'], [
    'name' => '[DEMO] Riviera Maya & Parque Xcaret Todo Incluido',
    'destination' => 'Riviera Maya, México',
    'status' => 'Confirmado',
    'opportunityId' => $opp3->getId(),
    'startDate' => $startCancun,
    'endDate' => $endCancun,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (60 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 2800.00,
    'totalSelling' => 3600.00,
    'grossProfit' => 800.00,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000003',
    'description' => '[DEMO_DATA] Itinerario confirmado con cobros programados cuadrados.'
]);

$sched3A = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Seña 50% Reserva Riviera Maya', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Seña 50% Reserva Riviera Maya',
    'itinerarioId' => $itinerario3->getId(),
    'dueDate' => date('Y-m-d', $now - (3 * 86400)),
    'amount' => 1800.00,
    'status' => 'Pagado',
    'paymentMethod' => 'Transferencia'
]);

$sched3B = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Saldo 50% Previo al Viaje Riviera Maya', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Saldo 50% Previo al Viaje Riviera Maya',
    'itinerarioId' => $itinerario3->getId(),
    'dueDate' => date('Y-m-d', $now + (10 * 86400)),
    'amount' => 1800.00,
    'status' => 'Pendiente',
    'paymentMethod' => 'Transferencia'
]);

getOrCreate($entityManager, 'Payment', ['paymentReference' => 'PAY-DEMO03'], [
    'name' => 'PAY-DEMO03',
    'paymentReference' => 'PAY-DEMO03',
    'opportunityId' => $opp3->getId(),
    'paymentScheduleId' => $sched3A->getId(),
    'amount' => 1800.00,
    'currency' => 'USD',
    'status' => 'Confirmed',
    'method' => 'bank_transfer',
    'destinationBankAccountId' => $bankBcp->getId(),
    'clientDeclaredAmount' => 1800.00,
    'clientOperationNumber' => 'BCP-CANCUN-01',
    'clientDeclaredDate' => date('Y-m-d', $now - (3 * 86400)),
    'verifiedAt' => date('Y-m-d H:i:s', $now - (2 * 86400)),
    'verificationNotes' => '[DEMO_DATA] Seña del 50% confirmada en banco.'
]);

echo "   ✔ Caso 3 generado (Confirmado, saldo pendiente de 1,800 USD en seguimiento).\n";

// -----------------------------------------------------------------------------
// 7. CASOS DE POST-VENTA & CALIDAD: PROMOTOR NPS 10 vs DETRACTOR NPS 4 (SLA URGENTE)
// -----------------------------------------------------------------------------
echo "▶ [7/8] Generando Casos de Calidad: Promotor NPS 10 vs Detractor NPS 4 con Tarea SLA...\n";

// Caso 4: Promotor París
$opp4 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Escapada Romántica: París, Museos & Niza'], [
    'name' => '[DEMO] Escapada Romántica: París, Museos & Niza',
    'stage' => 'Closed Won',
    'contactId' => $contactMarcela->getId(),
    'destination' => 'París, Francia',
    'leadSource' => 'Instagram',
    'amount' => 6500.00,
    'amountPaid' => 6500.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 1500.00,
    'description' => '[DEMO_DATA] Viaje finalizado con éxito total.'
]);

$feedbackPromoter = getOrCreate($entityManager, 'Feedback', ['name' => '[DEMO] Encuesta WhatsApp: Marcela Peña (París)'], [
    'name' => '[DEMO] Encuesta WhatsApp: Marcela Peña (París)',
    'contactId' => $contactMarcela->getId(),
    'opportunityId' => $opp4->getId(),
    'npsScore' => 10,
    'sentiment' => 'Promoter',
    'channel' => 'WhatsApp',
    'comments' => '¡Insuperable! El hotel en París tenía vista a la Torre Eiffel y los traslados fueron sumamente puntuales.',
    'followUpRequired' => false,
    'followUpStatus' => 'NotNeeded'
]);

// Caso 5: Detractor Egipto (Genera Tarea Urgente SLA < 2h)
$opp5 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Misterios del Antiguo Egipto & Crucero Nilo'], [
    'name' => '[DEMO] Misterios del Antiguo Egipto & Crucero Nilo',
    'stage' => 'Closed Won',
    'contactId' => $contactRoberto->getId(),
    'destination' => 'El Cairo & Luxor, Egipto',
    'leadSource' => 'Referido',
    'amount' => 4800.00,
    'amountPaid' => 4800.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 900.00,
    'description' => '[DEMO_DATA] Pasajero experimentó overbooking en camarote de barco en Luxor.'
]);

$incidentEgypt = getOrCreate($entityManager, 'Incident', ['name' => '[DEMO] Overbooking de Cabina en Crucero Nilo (Luxor)', 'opportunityId' => $opp5->getId()], [
    'name' => '[DEMO] Overbooking de Cabina en Crucero Nilo (Luxor)',
    'opportunityId' => $opp5->getId(),
    'severity' => 'High',
    'category' => 'SupplierFailure',
    'status' => 'Resolved',
    'costImpact' => 350.00,
    'resolutionPlan' => 'Se pagó directamente upgrade a Suite Presidencial y cena de cortesía para mitigar malestar.',
    'resolvedAt' => date('Y-m-d H:i:s', $now - (4 * 86400))
]);

$feedbackDetractor = getOrCreate($entityManager, 'Feedback', ['name' => '[DEMO] Encuesta WhatsApp: Roberto Benavides (Egipto)'], [
    'name' => '[DEMO] Encuesta WhatsApp: Roberto Benavides (Egipto)',
    'contactId' => $contactRoberto->getId(),
    'opportunityId' => $opp5->getId(),
    'npsScore' => 4,
    'sentiment' => 'Detractor',
    'channel' => 'WhatsApp',
    'comments' => 'El barco no tenía la cabina contratada al llegar a Luxor. El guía lo solucionó después de 3 horas, pero el disgusto inicial fue muy grande.',
    'followUpRequired' => true,
    'followUpStatus' => 'Pending'
]);

// Tarea urgente para protocolo de retención de detractores
getOrCreate($entityManager, 'Task', ['name' => '[DEMO] Atención Urgente Detractor: Roberto Benavides (Egipto)'], [
    'name' => '[DEMO] Atención Urgente Detractor: Roberto Benavides (Egipto)',
    'priority' => 'Urgent',
    'status' => 'In Progress',
    'parentType' => 'Feedback',
    'parentId' => $feedbackDetractor->getId(),
    'dateDue' => date('Y-m-d H:i:s', $now + (7200)), // SLA < 2 horas
    'description' => "[DEMO_DATA] Protocolo Heurística 9: Llamar al cliente en < 2 horas para ofrecer voucher de compensación de $200 para próximo viaje."
]);

// Caso 6: Venta Perdida (Closed Lost) para Embudo de Conversión
$opp6 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Safari Privado: Serengeti & Playas Zanzíbar'], [
    'name' => '[DEMO] Safari Privado: Serengeti & Playas Zanzíbar',
    'stage' => 'Closed Lost',
    'contactId' => $contactJavier->getId(),
    'destination' => 'Tanzania & Zanzíbar',
    'leadSource' => 'Facebook',
    'lostReason' => 'Precio / Presupuesto Alto',
    'lostReasonDetails' => 'El pasajero contaba con $2,000 para viaje de luna de miel. Cotización base de safari rondaba los $4,800.',
    'amount' => 4800.00,
    'description' => '[DEMO_DATA] Registrado para alimentar estadísticas de caída de embudo por precio.'
]);

// Caso 7: Lead Nuevo en WhatsApp (Prospecting)
$opp7 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Consulta WhatsApp: Luna de Miel en Bora Bora'], [
    'name' => '[DEMO] Consulta WhatsApp: Luna de Miel en Bora Bora',
    'stage' => 'Prospecting',
    'leadSource' => 'WhatsApp',
    'whatsappChatId' => 'demo-cw-1007',
    'chatwootConversationId' => '1007',
    'destination' => 'Bora Bora, Polinesia Francesa',
    'description' => '[DEMO_DATA] Lead recién ingresado en cola de asignación Round-Robin.'
]);

echo "   ✔ Casos de Calidad y Post-Venta listos (1 Promotor, 1 Detractor con SLA urgente, 1 LostReason).\n";

// -----------------------------------------------------------------------------
// 8. HISTORIAL DE ETAPAS PARA EMBUDO Y DASHBOARDS (OpportunityStageHistory)
// -----------------------------------------------------------------------------
echo "▶ [8/8] Poblando Historial de Transiciones para Dashboards ECharts...\n";

$stagesSequence = [
    ['stage' => 'Prospecting', 'entered' => -30, 'exited' => -28, 'duration' => 172800],
    ['stage' => 'Qualification', 'entered' => -28, 'exited' => -25, 'duration' => 259200],
    ['stage' => 'Proposal', 'entered' => -25, 'exited' => -21, 'duration' => 345600],
    ['stage' => 'Negotiation', 'entered' => -21, 'exited' => -18, 'duration' => 259200],
    ['stage' => 'PaymentPending', 'entered' => -18, 'exited' => -16, 'duration' => 172800],
    ['stage' => 'Closed Won', 'entered' => -16, 'exited' => null, 'duration' => null],
];

foreach ($stagesSequence as $step) {
    $enteredAt = date('Y-m-d H:i:s', $now + ($step['entered'] * 86400));
    $exitedAt = $step['exited'] ? date('Y-m-d H:i:s', $now + ($step['exited'] * 86400)) : null;

    getOrCreate($entityManager, 'OpportunityStageHistory', [
        'name' => "[DEMO] Cusco VIP - {$step['stage']}",
        'opportunityId' => $opp1->getId()
    ], [
        'name' => "[DEMO] Cusco VIP - {$step['stage']}",
        'opportunityId' => $opp1->getId(),
        'stage' => $step['stage'],
        'enteredAt' => $enteredAt,
        'exitedAt' => $exitedAt,
        'durationSeconds' => $step['duration'],
        'leadSource' => 'WhatsApp'
    ]);
}

// Historial para Opp 6 (Closed Lost)
getOrCreate($entityManager, 'OpportunityStageHistory', [
    'name' => "[DEMO] Safari Tanzania - Prospecting",
    'opportunityId' => $opp6->getId()
], [
    'name' => "[DEMO] Safari Tanzania - Prospecting",
    'opportunityId' => $opp6->getId(),
    'stage' => 'Prospecting',
    'enteredAt' => date('Y-m-d H:i:s', $now - (15 * 86400)),
    'exitedAt' => date('Y-m-d H:i:s', $now - (12 * 86400)),
    'durationSeconds' => 259200,
    'leadSource' => 'Facebook'
]);

getOrCreate($entityManager, 'OpportunityStageHistory', [
    'name' => "[DEMO] Safari Tanzania - Proposal",
    'opportunityId' => $opp6->getId()
], [
    'name' => "[DEMO] Safari Tanzania - Proposal",
    'opportunityId' => $opp6->getId(),
    'stage' => 'Proposal',
    'enteredAt' => date('Y-m-d H:i:s', $now - (12 * 86400)),
    'exitedAt' => date('Y-m-d H:i:s', $now - (9 * 86400)),
    'durationSeconds' => 259200,
    'leadSource' => 'Facebook'
]);

echo "   ✔ Historial de transiciones comercial cargado.\n\n";

echo "====================================================================\n";
echo "  ✅ DATOS DE PRUEBA CARGADOS EXITOSAMENTE (100% LISTO PARA PRESENTAR)\n";
echo "====================================================================\n";
echo "  Resumen de Casos Disponibles en el CRM:\n";
echo "  1. [En Viaje] Cusco Mágico VIP (ID: {$itinerario1->getId()})\n";
echo "     - Ver Consola Operativa en: /#Itinerario/view/{$itinerario1->getId()}\n";
echo "     - Ver Expediente Web Público: http://localhost:8085/p/demo (o con token c0a80101-0000-4000-8000-000000000001)\n";
echo "  2. [Cotización] Aventura en Patagonia (ID: {$itinerario2->getId()})\n";
echo "     - Vigencia de 7 días, márgenes calculados automáticamente\n";
echo "  3. [Confirmado / Pago 50%] Riviera Maya (ID: {$itinerario3->getId()})\n";
echo "     - Cobro parcial de 1,800 USD y saldo pendiente de 1,800 USD\n";
echo "  4. [Calidad Promotor] París & Niza (NPS 10 Promotor)\n";
echo "  5. [Alerta Detractor] Egipto (NPS 4 Detractor) -> Tarea Urgente creada (SLA < 2h)\n";
echo "  6. [Embudo / Descarte] Safari Tanzania (Closed Lost por Precio Alto)\n";
echo "  7. [Dashboards]: Embudo de conversión, Rentabilidad Real y Calidad en Destino activos\n\n";
echo "  ℹ Para limpiar estos datos en cualquier momento, ejecute:\n";
echo "    php custom/Espo/Custom/Scripts/CleanDemoData.php\n";
echo "====================================================================\n";
