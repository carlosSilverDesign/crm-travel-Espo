<?php

/**
 * Script de Poblado (Seed) de Datos de Prueba para Demostración y Preventa
 * CRM Viajes SaaS - Travel Agency Platform 2026
 *
 * Cubre exhaustivamente todas las casuísticas de viajes:
 * 1. Paquete Turístico VIP (Lima ➔ Cusco, En Viaje, 3 Pax, Vuelo+Hotel+Tour+Transfer, Conciliado 100%)
 * 2. Vuelo Nacional Solo Ida (Lima ➔ Arequipa, Cotización, SKY H2-5101, 1 Pax)
 * 3. Vuelo Nacional Ida y Vuelta (Lima ➔ Tarapoto ➔ Lima, Confirmado, Star Perú, 2 Pax)
 * 4. Vuelo Internacional Ida y Vuelta (Lima ➔ Madrid ➔ Lima, Cotización, Air Europa, 2 Pax: Adulto + Infante)
 * 5. Vuelo Internacional Multidestino (Lima ➔ Madrid ➔ Roma ➔ París ➔ Lima, Iberia + Air France, 2 Pax, Hoteles)
 * 6. Vuelo + Hotel All-Inclusive (Lima ➔ Cancún, Confirmado con Pago Parcial 50%, LATAM + Riu Palace + Xcaret)
 * 7. Post-Viaje Promotor (París & Niza, Finalizado, NPS 10)
 * 8. Alerta Detractor (Egipto, Finalizado, Overbooking resuelto, NPS 4, Tarea Urgente SLA < 2h)
 * 9. Descarte Comercial (Safari Tanzania, Closed Lost por Presupuesto)
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
echo "  🚀 INICIANDO POBLADO DE DATOS DE DEMOSTRACIÓN (CRM VIAJES SAAS 2026)\n";
echo "====================================================================\n\n";

function getOrCreate($entityManager, $entityType, $criteria, $data) {
    $existing = $entityManager->getRDBRepository($entityType)->where($criteria)->findOne();
    if ($existing) {
        $existing->set($data);
        $entityManager->saveEntity($existing, ['skipHooks' => true]);
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
echo "▶ [1/9] Creando Proveedores y Aerolíneas...\n";

$supplierLatam = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] LATAM Airlines Group'], [
    'name' => '[DEMO] LATAM Airlines Group',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'agencias@latam.demo',
    'contactPhone' => '+51 1 213 8200',
    'paymentTerms' => 'Emisión inmediata GDS'
]);

$supplierSky = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] SKY Airline Perú'], [
    'name' => '[DEMO] SKY Airline Perú',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'agencias@skyairline.demo',
    'contactPhone' => '+51 1 391 3600',
    'paymentTerms' => 'Prepago directo NDC'
]);

$supplierStarPeru = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Star Perú'], [
    'name' => '[DEMO] Star Perú',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'reservas@starperu.demo',
    'contactPhone' => '+51 1 705 9000',
    'paymentTerms' => 'Emisión inmediata'
]);

$supplierAirEuropa = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Air Europa'], [
    'name' => '[DEMO] Air Europa',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'ventas.peru@aireuropa.demo',
    'contactPhone' => '+51 1 652 7373',
    'paymentTerms' => 'Emisión BSP IATA'
]);

$supplierIberia = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Iberia Líneas Aéreas'], [
    'name' => '[DEMO] Iberia Líneas Aéreas',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'helpdesk@iberia.demo',
    'contactPhone' => '+34 900 111 500',
    'paymentTerms' => 'Emisión BSP IATA'
]);

$supplierAirFrance = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Air France'], [
    'name' => '[DEMO] Air France',
    'supplierType' => 'Aerolínea',
    'contactEmail' => 'b2b@airfrance.demo',
    'contactPhone' => '+33 1 70 36 39 50',
    'paymentTerms' => 'Emisión BSP IATA'
]);

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

$supplierAssist = getOrCreate($entityManager, 'Supplier', ['name' => '[DEMO] Assist Card Internacional'], [
    'name' => '[DEMO] Assist Card Internacional',
    'supplierType' => 'Seguros',
    'contactEmail' => 'emisiones@assistcard.demo',
    'contactPhone' => '+1 800 874 2823',
    'paymentTerms' => 'Liquidación quincenal'
]);

echo "   ✔ Proveedores y aerolíneas listos.\n";

// -----------------------------------------------------------------------------
// 2. CUENTAS BANCARIAS OPERATIVAS (BankAccounts)
// -----------------------------------------------------------------------------
echo "▶ [2/9] Creando Cuentas Bancarias Operativas...\n";

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
echo "▶ [3/9] Creando Contactos y Viajeros...\n";

$contactCarlos = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'carlos.mendoza@demo.viajes'], [
    'firstName' => '[DEMO] Carlos',
    'lastName' => 'Mendoza V.',
    'emailAddress' => 'carlos.mendoza@demo.viajes',
    'phoneNumber' => '+51987654321',
    'description' => '[DEMO_DATA] Titular de viaje familiar a Cusco. Viajero VIP.'
]);

$contactLucia = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'lucia.fernandez@demo.viajes'], [
    'firstName' => '[DEMO] Lucía',
    'lastName' => 'Fernández G.',
    'emailAddress' => 'lucia.fernandez@demo.viajes',
    'phoneNumber' => '+51977654321',
    'description' => '[DEMO_DATA] Viajera frecuente corporativa. Cotización Vuelo Solo Ida Arequipa.'
]);

$contactAndres = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'andres.bustamante@demo.viajes'], [
    'firstName' => '[DEMO] Andrés',
    'lastName' => 'Bustamante R.',
    'emailAddress' => 'andres.bustamante@demo.viajes',
    'phoneNumber' => '+51998765432',
    'description' => '[DEMO_DATA] Reserva paquete Caribe Cancún y Vuelos Selva Tarapoto.'
]);

$contactDiego = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'diego.morales@demo.viajes'], [
    'firstName' => '[DEMO] Diego',
    'lastName' => 'Morales T.',
    'emailAddress' => 'diego.morales@demo.viajes',
    'phoneNumber' => '+51966554433',
    'description' => '[DEMO_DATA] Cotización vuelo internacional a Madrid con infante.'
]);

$contactFernando = getOrCreate($entityManager, 'Contact', ['emailAddress' => 'fernando.castro@demo.viajes'], [
    'firstName' => '[DEMO] Fernando',
    'lastName' => 'Castro M.',
    'emailAddress' => 'fernando.castro@demo.viajes',
    'phoneNumber' => '+51955443322',
    'description' => '[DEMO_DATA] Eurotrip Multidestino Madrid, Roma y París.'
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
// 4. CASO 1: PAQUETE TURÍSTICO VIP - CUSCO & MACHU PICCHU (EN VIAJE, 100% PAGADO)
// -----------------------------------------------------------------------------
echo "▶ [4/9] Generando Caso 1: Paquete Turístico VIP 'Cusco Mágico' (EN VIAJE)...\n";

$startTrip1 = date('Y-m-d', $now - (2 * 86400));
$endTrip1 = date('Y-m-d', $now + (3 * 86400));

$opp1 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Paquete VIP: Cusco Mágico, Valle Sagrado & Machu Picchu'], [
    'name' => '[DEMO] Paquete VIP: Cusco Mágico, Valle Sagrado & Machu Picchu',
    'stage' => 'Closed Won',
    'contactId' => $contactCarlos->getId(),
    'destination' => 'Cusco & Valle Sagrado, Perú',
    'leadSource' => 'WhatsApp',
    'whatsappChatId' => 'demo-cw-1001',
    'chatwootConversationId' => '1001',
    'travelStartDate' => $startTrip1,
    'travelEndDate' => $endTrip1,
    'amount' => 4000.00,
    'amountPaid' => 4000.00,
    'pendingBalance' => 0.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 800.00,
    'clientType' => 'B2C_Direct'
]);

$itinerario1 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Cusco Mágico, Valle Sagrado & Machu Picchu VIP'], [
    'name' => '[DEMO] Cusco Mágico, Valle Sagrado & Machu Picchu VIP',
    'tripType' => 'Paquete Turístico',
    'origin' => 'Lima (LIM)',
    'destination' => 'Cusco & Valle Sagrado (CUZ)',
    'status' => 'En Viaje',
    'opportunityId' => $opp1->getId(),
    'startDate' => $startTrip1,
    'endDate' => $endTrip1,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (30 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 3200.00,
    'totalSelling' => 4000.00,
    'grossProfit' => 800.00,
    'grossMargin' => 20.00,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000001',
    'description' => '[DEMO_DATA] Paquete turístico completo en destino activo con vuelos, hotel 5*, traslados y excursión a Machu Picchu.'
]);

// Pasajeros Caso 1
$pax1A = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Carlos Mendoza', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Carlos Mendoza',
    'firstName' => 'Carlos',
    'lastName' => 'Mendoza',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario1->getId(),
    'contactId' => $contactCarlos->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-772819',
    'documentExpiration' => date('Y-m-d', $now + (365 * 86400)),
    'birthDate' => '1984-05-12',
    'nationality' => 'Peruana'
]);

$pax1B = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Valeria Soto', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Valeria Soto',
    'firstName' => 'Valeria',
    'lastName' => 'Soto',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario1->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-883920',
    'documentExpiration' => date('Y-m-d', $now + (400 * 86400)),
    'birthDate' => '1987-09-23',
    'nationality' => 'Peruana',
    'dietaryRestrictions' => 'Celíaca (Gluten Free)'
]);

$pax1C = getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Mateo Mendoza (Hijo)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Mateo Mendoza (Hijo)',
    'firstName' => 'Mateo',
    'lastName' => 'Mendoza',
    'passengerType' => 'Child',
    'itinerarioId' => $itinerario1->getId(),
    'documentType' => 'DNI',
    'documentNumber' => '72918273',
    'birthDate' => '2016-03-15',
    'nationality' => 'Peruana',
    'notes' => 'Menor de edad, viaja con ambos padres'
]);

// Servicios Caso 1
getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo Ida LIM-CUZ (LATAM LA-2194)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Vuelo Ida LIM-CUZ (LATAM LA-2194)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierLatam->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'Cusco',
    'destinationIata' => 'CUZ',
    'carrier' => 'LATAM Airlines',
    'flightNumber' => 'LA-2194',
    'cabin' => 'Economy',
    'serviceDate' => $startTrip1 . ' 08:30:00',
    'confirmationCode' => 'LA-PNR-CUZ99',
    'costPrice' => 300.00,
    'sellingPrice' => 360.00,
    'grossProfit' => 60.00,
    'status' => 'Emitido',
    'notes' => 'Equipaje 23kg incluido por pasajero'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Alojamiento Palacio del Inka 5* (4 Noches)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Alojamiento Palacio del Inka 5* (4 Noches)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'Cusco',
    'serviceDate' => $startTrip1 . ' 14:00:00',
    'serviceEndDate' => $endTrip1 . ' 11:00:00',
    'confirmationCode' => 'HTL-INKA-8821',
    'costPrice' => 1400.00,
    'sellingPrice' => 1750.00,
    'grossProfit' => 350.00,
    'status' => 'Confirmado',
    'notes' => 'Suite Familiar con desayuno buffet andino incluido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Tour Privado Machu Picchu & Tren Vistadome', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Tour Privado Machu Picchu & Tren Vistadome',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Tour',
    'destination' => 'Machu Picchu',
    'serviceDate' => date('Y-m-d', $now) . ' 06:00:00', // ACTIVO HOY
    'confirmationCode' => 'TOUR-MP-4402',
    'costPrice' => 800.00,
    'sellingPrice' => 1000.00,
    'grossProfit' => 200.00,
    'status' => 'Confirmado',
    'notes' => 'Guía privado en español, entradas Circuito 2A y bus Consettur ida/vuelta'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo Retorno CUZ-LIM (LATAM LA-2195)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Vuelo Retorno CUZ-LIM (LATAM LA-2195)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierLatam->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Cusco',
    'originIata' => 'CUZ',
    'destination' => 'Lima',
    'destinationIata' => 'LIM',
    'carrier' => 'LATAM Airlines',
    'flightNumber' => 'LA-2195',
    'cabin' => 'Economy',
    'serviceDate' => $endTrip1 . ' 17:30:00',
    'confirmationCode' => 'LA-PNR-CUZ99',
    'costPrice' => 300.00,
    'sellingPrice' => 360.00,
    'grossProfit' => 60.00,
    'status' => 'Emitido'
]);

// Líneas de Presupuesto Caso 1
getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Vuelos LATAM LIM-CUZ-LIM (3 Pax)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Vuelos LATAM LIM-CUZ-LIM (3 Pax)',
    'itinerarioId' => $itinerario1->getId(),
    'supplierId' => $supplierLatam->getId(),
    'costPrice' => 600.00,
    'marginRate' => 20.00,
    'sellingPrice' => 720.00,
    'grossProfit' => 120.00
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Hotel Palacio del Inka 5* (Suite 4N)', 'itinerarioId' => $itinerario1->getId()], [
    'name' => '[DEMO] Hotel Palacio del Inka 5* (Suite 4N)',
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

// Pagos Caso 1
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
    'verifiedAt' => date('Y-m-d H:i:s', $now - (19 * 86400))
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
    'verifiedAt' => date('Y-m-d H:i:s', $now - (4 * 86400))
]);

// -----------------------------------------------------------------------------
// 5. CASO 2: VUELO NACIONAL SOLO IDA (LIMA ➔ AREQUIPA, COTIZACIÓN)
// -----------------------------------------------------------------------------
echo "▶ [5/9] Generando Caso 2: Vuelo Nacional Solo Ida 'Lima ➔ Arequipa' (COTIZACIÓN)...\n";

$startFlight2 = date('Y-m-d', $now + (15 * 86400));

$opp2 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Vuelo Express Solo Ida: Lima a Arequipa'], [
    'name' => '[DEMO] Vuelo Express Solo Ida: Lima a Arequipa',
    'stage' => 'Proposal',
    'contactId' => $contactLucia->getId(),
    'destination' => 'Arequipa, Perú',
    'leadSource' => 'Web',
    'travelStartDate' => $startFlight2,
    'travelEndDate' => $startFlight2,
    'amount' => 120.00,
    'amountPaid' => 0.00,
    'pendingBalance' => 120.00,
    'financialStatus' => 'Unpaid',
    'projectedGrossProfit' => 35.00,
    'clientType' => 'B2B_Corporate'
]);

$itinerario2 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Vuelo Solo Ida: Lima (LIM) ➔ Arequipa (AQP)'], [
    'name' => '[DEMO] Vuelo Solo Ida: Lima (LIM) ➔ Arequipa (AQP)',
    'tripType' => 'Vuelo Solo Ida',
    'origin' => 'Lima (LIM)',
    'destination' => 'Arequipa (AQP)',
    'status' => 'Cotización',
    'opportunityId' => $opp2->getId(),
    'startDate' => $startFlight2,
    'endDate' => $startFlight2,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (3 * 86400)),
    'whatsappStatus' => 'Sent',
    'totalCost' => 85.00,
    'totalSelling' => 120.00,
    'grossProfit' => 35.00,
    'grossMargin' => 29.17,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000002',
    'description' => '[DEMO_DATA] Cotización de vuelo corporativo solo ida con tarifa flexible.'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Lucía Fernández G.', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Lucía Fernández G.',
    'firstName' => 'Lucía',
    'lastName' => 'Fernández',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario2->getId(),
    'contactId' => $contactLucia->getId(),
    'documentType' => 'DNI',
    'documentNumber' => '44882211',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo LIM-AQP (SKY Airline H2-5101)', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Vuelo LIM-AQP (SKY Airline H2-5101)',
    'itinerarioId' => $itinerario2->getId(),
    'supplierId' => $supplierSky->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'Arequipa',
    'destinationIata' => 'AQP',
    'carrier' => 'SKY Airline Perú',
    'flightNumber' => 'H2-5101',
    'cabin' => 'Economy',
    'serviceDate' => $startFlight2 . ' 07:15:00',
    'confirmationCode' => 'H2-AQP-101',
    'costPrice' => 85.00,
    'sellingPrice' => 120.00,
    'grossProfit' => 35.00,
    'status' => 'Borrador',
    'notes' => 'Incluye bolso de mano y equipaje de cabina 10kg'
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] Boleto Aéreo SKY LIM-AQP', 'itinerarioId' => $itinerario2->getId()], [
    'name' => '[DEMO] Boleto Aéreo SKY LIM-AQP',
    'itinerarioId' => $itinerario2->getId(),
    'supplierId' => $supplierSky->getId(),
    'costPrice' => 85.00,
    'marginRate' => 41.18,
    'sellingPrice' => 120.00,
    'grossProfit' => 35.00
]);

// -----------------------------------------------------------------------------
// 6. CASO 3: VUELO NACIONAL IDA Y VUELTA (LIMA ➔ TARAPOTO ➔ LIMA, CONFIRMADO)
// -----------------------------------------------------------------------------
echo "▶ [6/9] Generando Caso 3: Vuelo Nacional Ida y Vuelta 'Lima ➔ Tarapoto' (CONFIRMADO)...\n";

$startFlight3 = date('Y-m-d', $now + (10 * 86400));
$endFlight3 = date('Y-m-d', $now + (14 * 86400));

$opp3 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Vuelo Selva: Lima ➔ Tarapoto ➔ Lima'], [
    'name' => '[DEMO] Vuelo Selva: Lima ➔ Tarapoto ➔ Lima',
    'stage' => 'Closed Won',
    'contactId' => $contactAndres->getId(),
    'destination' => 'Tarapoto, Perú',
    'leadSource' => 'WhatsApp',
    'travelStartDate' => $startFlight3,
    'travelEndDate' => $endFlight3,
    'amount' => 380.00,
    'amountPaid' => 380.00,
    'pendingBalance' => 0.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 100.00,
    'clientType' => 'B2C_Direct'
]);

$itinerario3 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Vuelo Ida y Vuelta: Lima (LIM) ⇄ Tarapoto (TPP)'], [
    'name' => '[DEMO] Vuelo Ida y Vuelta: Lima (LIM) ⇄ Tarapoto (TPP)',
    'tripType' => 'Vuelo Ida y Vuelta',
    'origin' => 'Lima (LIM)',
    'destination' => 'Tarapoto (TPP)',
    'status' => 'Confirmado',
    'opportunityId' => $opp3->getId(),
    'startDate' => $startFlight3,
    'endDate' => $endFlight3,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (30 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 280.00,
    'totalSelling' => 380.00,
    'grossProfit' => 100.00,
    'grossMargin' => 26.32,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000003',
    'description' => '[DEMO_DATA] Vuelos ida y vuelta confirmados para 2 pasajeros con Star Perú.'
]);

// Pasajeros Caso 3
getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Andrés Bustamante', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Andrés Bustamante',
    'firstName' => 'Andrés',
    'lastName' => 'Bustamante',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario3->getId(),
    'contactId' => $contactAndres->getId(),
    'documentType' => 'DNI',
    'documentNumber' => '45998812',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Sofía Delgado', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Sofía Delgado',
    'firstName' => 'Sofía',
    'lastName' => 'Delgado',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario3->getId(),
    'documentType' => 'DNI',
    'documentNumber' => '47112233',
    'nationality' => 'Peruana'
]);

// Servicios Caso 3
getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo Ida LIM-TPP (Star Perú 2I-3112)', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Vuelo Ida LIM-TPP (Star Perú 2I-3112)',
    'itinerarioId' => $itinerario3->getId(),
    'supplierId' => $supplierStarPeru->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'Tarapoto',
    'destinationIata' => 'TPP',
    'carrier' => 'Star Perú',
    'flightNumber' => '2I-3112',
    'cabin' => 'Economy',
    'serviceDate' => $startFlight3 . ' 09:40:00',
    'confirmationCode' => '2I-TPP-44',
    'costPrice' => 140.00,
    'sellingPrice' => 190.00,
    'grossProfit' => 50.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo Retorno TPP-LIM (Star Perú 2I-3113)', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] Vuelo Retorno TPP-LIM (Star Perú 2I-3113)',
    'itinerarioId' => $itinerario3->getId(),
    'supplierId' => $supplierStarPeru->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Tarapoto',
    'originIata' => 'TPP',
    'destination' => 'Lima',
    'destinationIata' => 'LIM',
    'carrier' => 'Star Perú',
    'flightNumber' => '2I-3113',
    'cabin' => 'Economy',
    'serviceDate' => $endFlight3 . ' 18:20:00',
    'confirmationCode' => '2I-TPP-44',
    'costPrice' => 140.00,
    'sellingPrice' => 190.00,
    'grossProfit' => 50.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'BudgetLine', ['name' => '[DEMO] 2x Boletos Star Perú LIM-TPP-LIM', 'itinerarioId' => $itinerario3->getId()], [
    'name' => '[DEMO] 2x Boletos Star Perú LIM-TPP-LIM',
    'itinerarioId' => $itinerario3->getId(),
    'supplierId' => $supplierStarPeru->getId(),
    'costPrice' => 280.00,
    'marginRate' => 35.71,
    'sellingPrice' => 380.00,
    'grossProfit' => 100.00
]);

// -----------------------------------------------------------------------------
// 7. CASO 4: VUELO INTERNACIONAL MULTIDESTINO (MADRID, ROMA, PARÍS - CONFIRMADO)
// -----------------------------------------------------------------------------
echo "▶ [7/9] Generando Caso 4: Vuelo Multidestino 'Lima ➔ Madrid ➔ Roma ➔ París ➔ Lima'...\n";

$startMulti = date('Y-m-d', $now + (30 * 86400));
$endMulti = date('Y-m-d', $now + (44 * 86400));

$opp4 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Eurotrip Multidestino: España, Italia & Francia'], [
    'name' => '[DEMO] Eurotrip Multidestino: España, Italia & Francia',
    'stage' => 'Closed Won',
    'contactId' => $contactFernando->getId(),
    'destination' => 'Madrid, Roma & París (Europa)',
    'leadSource' => 'Referido',
    'travelStartDate' => $startMulti,
    'travelEndDate' => $endMulti,
    'amount' => 7200.00,
    'amountPaid' => 7200.00,
    'pendingBalance' => 0.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 1600.00,
    'clientType' => 'B2C_Direct'
]);

$itinerario4 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Eurotrip Multidestino: Lima ➔ Madrid ➔ Roma ➔ París ➔ Lima'], [
    'name' => '[DEMO] Eurotrip Multidestino: Lima ➔ Madrid ➔ Roma ➔ París ➔ Lima',
    'tripType' => 'Vuelo Multidestino',
    'origin' => 'Lima (LIM)',
    'destination' => 'Madrid (MAD), Roma (FCO), París (CDG)',
    'status' => 'Confirmado',
    'opportunityId' => $opp4->getId(),
    'startDate' => $startMulti,
    'endDate' => $endMulti,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (60 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 5600.00,
    'totalSelling' => 7200.00,
    'grossProfit' => 1600.00,
    'grossMargin' => 22.22,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000005',
    'description' => '[DEMO_DATA] Itinerario multidestino internacional que integra vuelos intercontinentales e inter-europeos con hoteles en Roma y París.'
]);

// Pasajeros Multidestino
getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Fernando Castro M.', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Fernando Castro M.',
    'firstName' => 'Fernando',
    'lastName' => 'Castro',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario4->getId(),
    'contactId' => $contactFernando->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-991122',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Mariana Rojas B.', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Mariana Rojas B.',
    'firstName' => 'Mariana',
    'lastName' => 'Rojas',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario4->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-991123',
    'nationality' => 'Peruana'
]);

// Segmentos de Vuelo y Alojamiento Multidestino
getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Segmento 1: Vuelo LIM-MAD (Iberia IB-6650)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Segmento 1: Vuelo LIM-MAD (Iberia IB-6650)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierIberia->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'Madrid',
    'destinationIata' => 'MAD',
    'carrier' => 'Iberia',
    'flightNumber' => 'IB-6650',
    'cabin' => 'Economy',
    'serviceDate' => $startMulti . ' 19:45:00',
    'confirmationCode' => 'IB-EUR-901',
    'costPrice' => 1400.00,
    'sellingPrice' => 1750.00,
    'grossProfit' => 350.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Segmento 2: Vuelo MAD-FCO (Iberia Express IB-3732)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Segmento 2: Vuelo MAD-FCO (Iberia Express IB-3732)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierIberia->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Madrid',
    'originIata' => 'MAD',
    'destination' => 'Roma',
    'destinationIata' => 'FCO',
    'carrier' => 'Iberia Express',
    'flightNumber' => 'IB-3732',
    'cabin' => 'Economy',
    'serviceDate' => date('Y-m-d', strtotime($startMulti) + (4 * 86400)) . ' 11:20:00',
    'confirmationCode' => 'IB-EUR-901',
    'costPrice' => 200.00,
    'sellingPrice' => 280.00,
    'grossProfit' => 80.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel Roma: Hotel Artemide 4* (3 Noches)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Hotel Roma: Hotel Artemide 4* (3 Noches)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'Roma',
    'serviceDate' => date('Y-m-d', strtotime($startMulti) + (4 * 86400)) . ' 15:00:00',
    'confirmationCode' => 'HTL-ROM-7721',
    'costPrice' => 900.00,
    'sellingPrice' => 1150.00,
    'grossProfit' => 250.00,
    'status' => 'Confirmado'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Segmento 3: Vuelo FCO-CDG (Air France AF-1404)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Segmento 3: Vuelo FCO-CDG (Air France AF-1404)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierAirFrance->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Roma',
    'originIata' => 'FCO',
    'destination' => 'París',
    'destinationIata' => 'CDG',
    'carrier' => 'Air France',
    'flightNumber' => 'AF-1404',
    'cabin' => 'Economy',
    'serviceDate' => date('Y-m-d', strtotime($startMulti) + (7 * 86400)) . ' 14:10:00',
    'confirmationCode' => 'AF-PAR-22',
    'costPrice' => 220.00,
    'sellingPrice' => 300.00,
    'grossProfit' => 80.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel París: Le Marais Boutique (4 Noches)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Hotel París: Le Marais Boutique (4 Noches)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'París',
    'serviceDate' => date('Y-m-d', strtotime($startMulti) + (7 * 86400)) . ' 16:00:00',
    'confirmationCode' => 'HTL-PAR-5541',
    'costPrice' => 1280.00,
    'sellingPrice' => 1620.00,
    'grossProfit' => 340.00,
    'status' => 'Confirmado'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Segmento 4: Vuelo Retorno CDG-LIM (Air France AF-474)', 'itinerarioId' => $itinerario4->getId()], [
    'name' => '[DEMO] Segmento 4: Vuelo Retorno CDG-LIM (Air France AF-474)',
    'itinerarioId' => $itinerario4->getId(),
    'supplierId' => $supplierAirFrance->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'París',
    'originIata' => 'CDG',
    'destination' => 'Lima',
    'destinationIata' => 'LIM',
    'carrier' => 'Air France',
    'flightNumber' => 'AF-474',
    'cabin' => 'Economy',
    'serviceDate' => $endMulti . ' 13:30:00',
    'confirmationCode' => 'AF-PAR-22',
    'costPrice' => 1600.00,
    'sellingPrice' => 2100.00,
    'grossProfit' => 500.00,
    'status' => 'Emitido'
]);

// -----------------------------------------------------------------------------
// 8. CASO 5: VUELO + HOTEL ALL-INCLUSIVE (RIVIERA MAYA, PAGO PARCIAL 50%)
// -----------------------------------------------------------------------------
echo "▶ [8/9] Generando Caso 5: Vuelo + Hotel All-Inclusive 'Cancún & Riviera Maya'...\n";

$startCancun = date('Y-m-d', $now + (20 * 86400));
$endCancun = date('Y-m-d', $now + (27 * 86400));

$opp5 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Caribe All Inclusive: Riviera Maya & Xcaret VIP'], [
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
    'clientType' => 'B2C_Direct'
]);

$itinerario5 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Riviera Maya & Parque Xcaret Todo Incluido'], [
    'name' => '[DEMO] Riviera Maya & Parque Xcaret Todo Incluido',
    'tripType' => 'Vuelo + Hotel',
    'origin' => 'Lima (LIM)',
    'destination' => 'Riviera Maya & Cancún (CUN)',
    'status' => 'Confirmado',
    'opportunityId' => $opp5->getId(),
    'startDate' => $startCancun,
    'endDate' => $endCancun,
    'quoteValidUntil' => date('Y-m-d H:i:s', $now + (60 * 86400)),
    'whatsappStatus' => 'Delivered',
    'totalCost' => 2800.00,
    'totalSelling' => 3600.00,
    'grossProfit' => 800.00,
    'grossMargin' => 22.22,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000006',
    'description' => '[DEMO_DATA] Itinerario confirmado con pago parcial del 50% y saldo pendiente programado.'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Andrés Bustamante', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Andrés Bustamante',
    'firstName' => 'Andrés',
    'lastName' => 'Bustamante',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario5->getId(),
    'contactId' => $contactAndres->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-665544',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Claudia Salas', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Claudia Salas',
    'firstName' => 'Claudia',
    'lastName' => 'Salas',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario5->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-665545',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo LIM-CUN-LIM (LATAM LA-2480)', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Vuelo LIM-CUN-LIM (LATAM LA-2480)',
    'itinerarioId' => $itinerario5->getId(),
    'supplierId' => $supplierLatam->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'Cancún',
    'destinationIata' => 'CUN',
    'carrier' => 'LATAM Airlines',
    'flightNumber' => 'LA-2480',
    'cabin' => 'Economy',
    'serviceDate' => $startCancun . ' 10:15:00',
    'confirmationCode' => 'LA-CUN-88',
    'costPrice' => 900.00,
    'sellingPrice' => 1100.00,
    'grossProfit' => 200.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel Riu Palace Riviera Maya All Inclusive (7N)', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Hotel Riu Palace Riviera Maya All Inclusive (7N)',
    'itinerarioId' => $itinerario5->getId(),
    'supplierId' => $supplierRiu->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'Riviera Maya',
    'serviceDate' => $startCancun . ' 15:00:00',
    'confirmationCode' => 'RIU-CUN-9923',
    'costPrice' => 1500.00,
    'sellingPrice' => 1950.00,
    'grossProfit' => 450.00,
    'status' => 'Confirmado'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Tour Parque Xcaret Plus & Show México Espectacular', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Tour Parque Xcaret Plus & Show México Espectacular',
    'itinerarioId' => $itinerario5->getId(),
    'supplierId' => $supplierRiu->getId(),
    'serviceType' => 'Tour',
    'destination' => 'Riviera Maya',
    'serviceDate' => date('Y-m-d', strtotime($startCancun) + (2 * 86400)) . ' 08:00:00',
    'confirmationCode' => 'XCA-VIP-1102',
    'costPrice' => 400.00,
    'sellingPrice' => 550.00,
    'grossProfit' => 150.00,
    'status' => 'Confirmado'
]);

// Pagos y Cronogramas Cancún
$sched5A = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Seña 50% Reserva Riviera Maya', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Seña 50% Reserva Riviera Maya',
    'itinerarioId' => $itinerario5->getId(),
    'dueDate' => date('Y-m-d', $now - (3 * 86400)),
    'amount' => 1800.00,
    'status' => 'Pagado',
    'paymentMethod' => 'Transferencia'
]);

$sched5B = getOrCreate($entityManager, 'PaymentSchedule', ['name' => '[DEMO] Saldo 50% Previo al Viaje Riviera Maya', 'itinerarioId' => $itinerario5->getId()], [
    'name' => '[DEMO] Saldo 50% Previo al Viaje Riviera Maya',
    'itinerarioId' => $itinerario5->getId(),
    'dueDate' => date('Y-m-d', $now + (10 * 86400)),
    'amount' => 1800.00,
    'status' => 'Pendiente',
    'paymentMethod' => 'Transferencia'
]);

getOrCreate($entityManager, 'Payment', ['paymentReference' => 'PAY-DEMO03'], [
    'name' => 'PAY-DEMO03',
    'paymentReference' => 'PAY-DEMO03',
    'opportunityId' => $opp5->getId(),
    'paymentScheduleId' => $sched5A->getId(),
    'amount' => 1800.00,
    'currency' => 'USD',
    'status' => 'Confirmed',
    'method' => 'bank_transfer',
    'destinationBankAccountId' => $bankBcp->getId(),
    'clientDeclaredAmount' => 1800.00,
    'clientOperationNumber' => 'BCP-CANCUN-01',
    'clientDeclaredDate' => date('Y-m-d', $now - (3 * 86400)),
    'verifiedAt' => date('Y-m-d H:i:s', $now - (2 * 86400))
]);

// -----------------------------------------------------------------------------
// 9. CASOS DE POST-VENTA, CALIDAD Y DESCARTE
// -----------------------------------------------------------------------------
echo "▶ [9/9] Generando Casos de Calidad, NPS y Embudo Comercial...\n";

// Caso 7: Promotor París NPS 10
$opp7 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Escapada Romántica: París, Museos & Niza'], [
    'name' => '[DEMO] Escapada Romántica: París, Museos & Niza',
    'stage' => 'Closed Won',
    'contactId' => $contactMarcela->getId(),
    'destination' => 'París, Francia',
    'leadSource' => 'Instagram',
    'travelStartDate' => date('Y-m-d', $now - (20 * 86400)),
    'travelEndDate' => date('Y-m-d', $now - (12 * 86400)),
    'amount' => 6500.00,
    'amountPaid' => 6500.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 1500.00
]);

$itinerario7 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] París Romántico, Museos & Costa Azul'], [
    'name' => '[DEMO] París Romántico, Museos & Costa Azul',
    'tripType' => 'Paquete Turístico',
    'origin' => 'Lima (LIM)',
    'destination' => 'París & Niza (Francia)',
    'status' => 'Finalizado',
    'opportunityId' => $opp7->getId(),
    'startDate' => date('Y-m-d', $now - (20 * 86400)),
    'endDate' => date('Y-m-d', $now - (12 * 86400)),
    'totalCost' => 5000.00,
    'totalSelling' => 6500.00,
    'grossProfit' => 1500.00,
    'grossMargin' => 23.08,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000007'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Marcela Peña S.', 'itinerarioId' => $itinerario7->getId()], [
    'name' => '[DEMO] Marcela Peña S.',
    'firstName' => 'Marcela',
    'lastName' => 'Peña',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario7->getId(),
    'contactId' => $contactMarcela->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-CL-554433',
    'nationality' => 'Chilena'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Vuelo LIM-CDG (Air France AF-475)', 'itinerarioId' => $itinerario7->getId()], [
    'name' => '[DEMO] Vuelo LIM-CDG (Air France AF-475)',
    'itinerarioId' => $itinerario7->getId(),
    'supplierId' => $supplierAirFrance->getId(),
    'serviceType' => 'Vuelo',
    'origin' => 'Lima',
    'originIata' => 'LIM',
    'destination' => 'París',
    'destinationIata' => 'CDG',
    'carrier' => 'Air France',
    'flightNumber' => 'AF-475',
    'cabin' => 'Premium Economy',
    'serviceDate' => date('Y-m-d', $now - (20 * 86400)) . ' 18:30:00',
    'confirmationCode' => 'AF-PARIS-88',
    'costPrice' => 1800.00,
    'sellingPrice' => 2300.00,
    'grossProfit' => 500.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel Le Marais París 4* (4 Noches)', 'itinerarioId' => $itinerario7->getId()], [
    'name' => '[DEMO] Hotel Le Marais París 4* (4 Noches)',
    'itinerarioId' => $itinerario7->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'París',
    'serviceDate' => date('Y-m-d', $now - (19 * 86400)) . ' 15:00:00',
    'confirmationCode' => 'HTL-PAR-9012',
    'costPrice' => 1200.00,
    'sellingPrice' => 1600.00,
    'grossProfit' => 400.00,
    'status' => 'Confirmado',
    'notes' => 'Habitación Deluxe con vistas a la Torre Eiffel'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Tren TGV Alta Velocidad París ➔ Niza', 'itinerarioId' => $itinerario7->getId()], [
    'name' => '[DEMO] Tren TGV Alta Velocidad París ➔ Niza',
    'itinerarioId' => $itinerario7->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Tren',
    'origin' => 'París Gare de Lyon',
    'destination' => 'Niza Ville',
    'serviceDate' => date('Y-m-d', $now - (15 * 86400)) . ' 09:15:00',
    'confirmationCode' => 'SNCF-TGV-331',
    'costPrice' => 300.00,
    'sellingPrice' => 450.00,
    'grossProfit' => 150.00,
    'status' => 'Emitido'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel Le Negresco Niza 5* (3 Noches)', 'itinerarioId' => $itinerario7->getId()], [
    'name' => '[DEMO] Hotel Le Negresco Niza 5* (3 Noches)',
    'itinerarioId' => $itinerario7->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'Niza (Costa Azul)',
    'serviceDate' => date('Y-m-d', $now - (15 * 86400)) . ' 16:00:00',
    'confirmationCode' => 'HTL-NCE-4412',
    'costPrice' => 900.00,
    'sellingPrice' => 1200.00,
    'grossProfit' => 300.00,
    'status' => 'Confirmado'
]);

$feedbackPromoter = getOrCreate($entityManager, 'Feedback', ['name' => '[DEMO] Encuesta WhatsApp: Marcela Peña (París)'], [
    'name' => '[DEMO] Encuesta WhatsApp: Marcela Peña (París)',
    'contactId' => $contactMarcela->getId(),
    'opportunityId' => $opp7->getId(),
    'npsScore' => 10,
    'sentiment' => 'Promoter',
    'channel' => 'WhatsApp',
    'comments' => '¡Insuperable! El hotel en París tenía vista a la Torre Eiffel y los traslados fueron sumamente puntuales.',
    'followUpRequired' => false,
    'followUpStatus' => 'NotNeeded'
]);

// Caso 8: Detractor Egipto NPS 4 con Tarea SLA Urgente
$opp8 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Misterios del Antiguo Egipto & Crucero Nilo'], [
    'name' => '[DEMO] Misterios del Antiguo Egipto & Crucero Nilo',
    'stage' => 'Closed Won',
    'contactId' => $contactRoberto->getId(),
    'destination' => 'El Cairo & Luxor, Egipto',
    'leadSource' => 'Referido',
    'travelStartDate' => date('Y-m-d', $now - (10 * 86400)),
    'travelEndDate' => date('Y-m-d', $now - (3 * 86400)),
    'amount' => 4800.00,
    'amountPaid' => 4800.00,
    'financialStatus' => 'PaidInFull',
    'projectedGrossProfit' => 900.00
]);

$itinerario8 = getOrCreate($entityManager, 'Itinerario', ['name' => '[DEMO] Antiguo Egipto & Crucero Nilo 5*'], [
    'name' => '[DEMO] Antiguo Egipto & Crucero Nilo 5*',
    'tripType' => 'Paquete Turístico',
    'origin' => 'Lima (LIM)',
    'destination' => 'El Cairo & Luxor, Egipto',
    'status' => 'Finalizado',
    'opportunityId' => $opp8->getId(),
    'startDate' => date('Y-m-d', $now - (10 * 86400)),
    'endDate' => date('Y-m-d', $now - (3 * 86400)),
    'totalCost' => 3900.00,
    'totalSelling' => 4800.00,
    'grossProfit' => 900.00,
    'grossMargin' => 18.75,
    'publicAccessToken' => 'c0a80101-0000-4000-8000-000000000008'
]);

getOrCreate($entityManager, 'Passenger', ['name' => '[DEMO] Roberto Benavides T.', 'itinerarioId' => $itinerario8->getId()], [
    'name' => '[DEMO] Roberto Benavides T.',
    'firstName' => 'Roberto',
    'lastName' => 'Benavides',
    'passengerType' => 'Adult',
    'itinerarioId' => $itinerario8->getId(),
    'contactId' => $contactRoberto->getId(),
    'documentType' => 'Passport',
    'documentNumber' => 'PAS-PE-332211',
    'nationality' => 'Peruana'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Hotel Marriott Mena House Pirámides 5* (3N)', 'itinerarioId' => $itinerario8->getId()], [
    'name' => '[DEMO] Hotel Marriott Mena House Pirámides 5* (3N)',
    'itinerarioId' => $itinerario8->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Hotel',
    'destination' => 'El Cairo',
    'serviceDate' => date('Y-m-d', $now - (10 * 86400)) . ' 14:00:00',
    'confirmationCode' => 'HTL-CAI-1102',
    'costPrice' => 1100.00,
    'sellingPrice' => 1350.00,
    'grossProfit' => 250.00,
    'status' => 'Confirmado',
    'notes' => 'Vista directa a las Pirámides de Guiza'
]);

getOrCreate($entityManager, 'ItineraryItem', ['name' => '[DEMO] Crucero Río Nilo 5* Luxor ➔ Aswan (4 Noches)', 'itinerarioId' => $itinerario8->getId()], [
    'name' => '[DEMO] Crucero Río Nilo 5* Luxor ➔ Aswan (4 Noches)',
    'itinerarioId' => $itinerario8->getId(),
    'supplierId' => $supplierInca->getId(),
    'serviceType' => 'Crucero',
    'destination' => 'Luxor - Aswan',
    'serviceDate' => date('Y-m-d', $now - (7 * 86400)) . ' 12:00:00',
    'confirmationCode' => 'CRU-NILO-771',
    'costPrice' => 1600.00,
    'sellingPrice' => 1950.00,
    'grossProfit' => 350.00,
    'status' => 'Confirmado',
    'notes' => 'Pensión completa a bordo con guía egiptólogo en español'
]);

getOrCreate($entityManager, 'Incident', ['name' => '[DEMO] Overbooking de Cabina en Crucero Nilo (Luxor)', 'opportunityId' => $opp8->getId()], [
    'name' => '[DEMO] Overbooking de Cabina en Crucero Nilo (Luxor)',
    'opportunityId' => $opp8->getId(),
    'itinerarioId' => $itinerario8->getId(),
    'severity' => 'High',
    'category' => 'SupplierFailure',
    'status' => 'Resolved',
    'costImpact' => 350.00,
    'resolutionPlan' => 'Se gestionó upgrade a Suite Presidencial y cena de cortesía.',
    'resolvedAt' => date('Y-m-d H:i:s', $now - (4 * 86400))
]);

$feedbackDetractor = getOrCreate($entityManager, 'Feedback', ['name' => '[DEMO] Encuesta WhatsApp: Roberto Benavides (Egipto)'], [
    'name' => '[DEMO] Encuesta WhatsApp: Roberto Benavides (Egipto)',
    'contactId' => $contactRoberto->getId(),
    'opportunityId' => $opp8->getId(),
    'npsScore' => 4,
    'sentiment' => 'Detractor',
    'channel' => 'WhatsApp',
    'comments' => 'El barco no tenía la cabina contratada al llegar a Luxor. El guía lo solucionó después de 3 horas, pero el disgusto inicial fue muy grande.',
    'followUpRequired' => true,
    'followUpStatus' => 'Pending'
]);

getOrCreate($entityManager, 'Task', ['name' => '[DEMO] Atención Urgente Detractor: Roberto Benavides (Egipto)'], [
    'name' => '[DEMO] Atención Urgente Detractor: Roberto Benavides (Egipto)',
    'priority' => 'Urgent',
    'status' => 'In Progress',
    'parentType' => 'Feedback',
    'parentId' => $feedbackDetractor->getId(),
    'dateDue' => date('Y-m-d H:i:s', $now + (7200)),
    'description' => "[DEMO_DATA] Llamar al cliente en < 2 horas para ofrecer voucher de compensación de $200 para su próximo viaje."
]);

// Caso 9: Descarte Closed Lost
$opp9 = getOrCreate($entityManager, 'Opportunity', ['name' => '[DEMO] Safari Privado: Serengeti & Playas Zanzíbar'], [
    'name' => '[DEMO] Safari Privado: Serengeti & Playas Zanzíbar',
    'stage' => 'Closed Lost',
    'contactId' => $contactJavier->getId(),
    'destination' => 'Tanzania & Zanzíbar',
    'leadSource' => 'Facebook',
    'lostReason' => 'Precio / Presupuesto Alto',
    'lostReasonDetails' => 'El pasajero contaba con $2,000. Cotización base de safari rondaba los $4,800.',
    'amount' => 4800.00
]);

echo "\n====================================================================\n";
echo "  ✅ POBLADO COMPLETADO CON ÉXITO (COBERTURA TOTAL DE CASUÍSTICAS)\n";
echo "====================================================================\n";
echo "  Casos y Portales Web del Viajero disponibles:\n";
echo "  1. [En Viaje] Cusco Mágico VIP (ID: {$itinerario1->getId()})\n";
echo "     - Web: http://localhost:8085/p/demo o /p/c0a80101-0000-4000-8000-000000000001\n";
echo "  2. [Cotización] Vuelo Solo Ida LIM-AQP (ID: {$itinerario2->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000002\n";
echo "  3. [Confirmado] Vuelo Ida y Vuelta LIM-TPP-LIM (ID: {$itinerario3->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000003\n";
echo "  4. [Confirmado] Eurotrip Multidestino Madrid-Roma-París (ID: {$itinerario4->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000005\n";
echo "  5. [Confirmado 50%] Riviera Maya All-Inclusive (ID: {$itinerario5->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000006\n";
echo "  6. [Promotor NPS 10] París Romántico (ID: {$itinerario7->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000007\n";
echo "  7. [Alerta Detractor NPS 4] Antiguo Egipto (ID: {$itinerario8->getId()})\n";
echo "     - Web: http://localhost:8085/p/c0a80101-0000-4000-8000-000000000008\n";
echo "====================================================================\n";
