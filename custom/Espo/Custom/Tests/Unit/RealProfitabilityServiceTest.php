<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Services\RealProfitabilityService;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

/**
 * Suite de Pruebas Unitarias para TASK-050:
 * Servicio Backend de Rentabilidad Real Consolidada (RealProfitabilityService).
 *
 * Criterios de Aceptación:
 * 1. Regla de Oro de Liquidación: Margen Real = Cobros Reconciliados (status=Confirmed) - Costos Confirmados de Operadores.
 * 2. Aislamiento Contable Estricto: Cotizaciones abiertas y saldos pendientes se segregan en 'unreconciledFunnel'
 *    y jamás entran al cálculo del margen bruto real.
 * 3. Desglose multidimensional: byDestination, bySupplier y byClientType (B2C_Direct vs B2B_Corporate).
 * 4. Filtros operativos: por destino, por tipo de cliente, por proveedor y por rango de fechas.
 * 5. Exportabilidad CSV: Generación de informe descargable completo con BOM UTF-8 y formatos tabulares.
 * 6. Umbral de rendimiento: Cálculo de agregados en menos de 100 ms.
 */
class RealProfitabilityServiceTest extends TestCase
{
    /**
     * Helper para construir stubs de Entity de EspoCRM con atributos tipados.
     */
    private function createEntityStub(string $id, array $attributes): Entity
    {
        $stub = $this->createStub(Entity::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('get')->willReturnCallback(function (string $field) use ($id, $attributes) {
            if ($field === 'id') {
                return $id;
            }
            return $attributes[$field] ?? null;
        });

        return $stub;
    }

    /**
     * Helper para construir repositorios ORM stub con respuesta predecible.
     */
    private function createRepositoryStub(array $entities): RDBRepository
    {
        $builder = $this->createStub(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection($entities));

        $repo = $this->createStub(RDBRepository::class);
        $repo->method('where')->willReturn($builder);

        return $repo;
    }

    /**
     * Helper para mockear el EntityManager con las colecciones de entidades.
     */
    private function createEntityManagerMock(
        array $opportunities,
        array $payments,
        array $itinerarios,
        array $budgetLines,
        array $suppliers
    ): EntityManager {
        $em = $this->createStub(EntityManager::class);

        $em->method('getRDBRepository')->willReturnCallback(function (string $entityType) use (
            $opportunities,
            $payments,
            $itinerarios,
            $budgetLines,
            $suppliers
        ) {
            return match ($entityType) {
                'Opportunity' => $this->createRepositoryStub($opportunities),
                'Payment' => $this->createRepositoryStub($payments),
                'Itinerario' => $this->createRepositoryStub($itinerarios),
                'BudgetLine' => $this->createRepositoryStub($budgetLines),
                'Supplier' => $this->createRepositoryStub($suppliers),
                default => $this->createRepositoryStub([]),
            };
        });

        return $em;
    }

    /**
     * Test 1: Regla de Oro y Aislamiento Contable Estricto.
     * Valida que cotizaciones pendientes NO contaminen el margen real en banco.
     */
    public function testGoldenRuleStrictAccountingIsolation(): void
    {
        // Oportunidad 1: Ganada y reconciliada al 100% en banco
        $oppWon = $this->createEntityStub('opp-01', [
            'name' => 'Luna de Miel en Cusco',
            'stage' => 'Closed Won',
            'amount' => 5000.0,
            'amountPaid' => 5000.0,
            'pendingBalance' => 0.0,
            'destination' => 'Cusco, Perú',
            'clientType' => 'B2C_Direct',
            'deleted' => false,
        ]);

        // Pago confirmado de $5,000 en banco
        $paymentConfirmed = $this->createEntityStub('pay-01', [
            'opportunityId' => 'opp-01',
            'status' => 'Confirmed',
            'amount' => 5000.0,
            'verifiedAt' => '2026-09-15 10:00:00',
            'deleted' => false,
        ]);

        // Itinerario confirmado con costos de operador
        $itinConfirmed = $this->createEntityStub('itin-01', [
            'opportunityId' => 'opp-01',
            'status' => 'Confirmado',
            'destination' => 'Cusco, Perú',
            'deleted' => false,
        ]);

        $line1 = $this->createEntityStub('line-01', [
            'itinerarioId' => 'itin-01',
            'supplierId' => 'sup-hotel-01',
            'costPrice' => 2000.0,
            'deleted' => false,
        ]);
        $line2 = $this->createEntityStub('line-02', [
            'itinerarioId' => 'itin-01',
            'supplierId' => 'sup-tour-01',
            'costPrice' => 1500.0,
            'deleted' => false,
        ]);

        // Oportunidad 2: En cotización (dinero de papel, sin pagos)
        $oppQuote = $this->createEntityStub('opp-02', [
            'name' => 'Cotización Grupo Punta Cana',
            'stage' => 'Proposal',
            'amount' => 12000.0,
            'amountPaid' => 0.0,
            'pendingBalance' => 12000.0,
            'destination' => 'Punta Cana, Rep. Dominicana',
            'clientType' => 'B2C_Direct',
            'deleted' => false,
        ]);

        // Itinerario en Cotización con presupuesto de operador
        $itinQuote = $this->createEntityStub('itin-02', [
            'opportunityId' => 'opp-02',
            'status' => 'Cotización',
            'destination' => 'Punta Cana, Rep. Dominicana',
            'deleted' => false,
        ]);

        $lineQuote = $this->createEntityStub('line-03', [
            'itinerarioId' => 'itin-02',
            'supplierId' => 'sup-hotel-pc',
            'costPrice' => 8000.0,
            'deleted' => false,
        ]);

        // Proveedores
        $supHotel = $this->createEntityStub('sup-hotel-01', ['name' => 'Hotel Palacio del Inka', 'deleted' => false]);
        $supTour = $this->createEntityStub('sup-tour-01', ['name' => 'Andean Adventures SAC', 'deleted' => false]);
        $supHotelPc = $this->createEntityStub('sup-hotel-pc', ['name' => 'Resort Punta Cana All Inclusive', 'deleted' => false]);

        $em = $this->createEntityManagerMock(
            [$oppWon, $oppQuote],
            [$paymentConfirmed],
            [$itinConfirmed, $itinQuote],
            [$line1, $line2, $lineQuote],
            [$supHotel, $supTour, $supHotelPc]
        );

        $service = new RealProfitabilityService($em);

        $startTime = microtime(true);
        $result = $service->getRealProfitability();
        $executionTimeMs = (microtime(true) - $startTime) * 1000;

        // Criterio DoD: Menos de 100 ms
        $this->assertLessThan(100.0, $executionTimeMs, 'El servicio debe retornar en menos de 100 ms');

        // 1. Reconciled: Sólo la venta cerrada con transferencias verificadas
        $this->assertEquals(5000.00, $result['reconciled']['totalIncome']);
        $this->assertEquals(3500.00, $result['reconciled']['totalCost']);
        $this->assertEquals(1500.00, $result['reconciled']['grossProfit']);
        $this->assertEquals(30.00, $result['reconciled']['grossMarginRate']);
        $this->assertEquals(1, $result['reconciled']['confirmedBookingsCount']);

        // 2. Unreconciled Funnel: Dinero en trámite / cotizaciones
        $this->assertEquals(12000.00, $result['unreconciledFunnel']['pendingBalance']);
        $this->assertEquals(8000.00, $result['unreconciledFunnel']['unconfirmedQuotesCost']);
        $this->assertEquals(1, $result['unreconciledFunnel']['pipelineBookingsCount']);

        // 3. Destinos reconciliados: Únicamente Cusco tiene ingresos reales
        $this->assertCount(1, $result['byDestination']);
        $this->assertEquals('Cusco, Perú', $result['byDestination'][0]['destination']);
        $this->assertEquals(5000.00, $result['byDestination'][0]['reconciledIncome']);
        $this->assertEquals(3500.00, $result['byDestination'][0]['reconciledCost']);
        $this->assertEquals(1500.00, $result['byDestination'][0]['grossProfit']);
        $this->assertEquals(30.00, $result['byDestination'][0]['marginRate']);

        // 4. Proveedores reconciliados: sup-hotel-pc NO debe aparecer porque no está confirmado
        $this->assertCount(2, $result['bySupplier']);
        $this->assertEquals('Hotel Palacio del Inka', $result['bySupplier'][0]['supplierName']);
        $this->assertEquals(2000.00, $result['bySupplier'][0]['confirmedCost']);
        $this->assertEquals('Andean Adventures SAC', $result['bySupplier'][1]['supplierName']);
        $this->assertEquals(1500.00, $result['bySupplier'][1]['confirmedCost']);
    }

    /**
     * Test 2: Reserva con Pago Parcial y Reconciliación Híbrida.
     * Una reserva de $10,000 con un abono de $4,000 en banco y $6,000 pendientes.
     */
    public function testPartiallyPaidOpportunityReconciliation(): void
    {
        $oppPartial = $this->createEntityStub('opp-partial', [
            'name' => 'Viaje Corporativo Tech SAC',
            'stage' => 'PaymentPending',
            'amount' => 10000.0,
            'amountPaid' => 4000.0,
            'pendingBalance' => 6000.0,
            'destination' => 'Arequipa, Perú',
            'clientType' => 'B2B_Corporate',
            'deleted' => false,
        ]);

        $paymentDeposit = $this->createEntityStub('pay-deposit', [
            'opportunityId' => 'opp-partial',
            'status' => 'Confirmed',
            'amount' => 4000.0,
            'verifiedAt' => '2026-09-20 15:30:00',
            'deleted' => false,
        ]);

        // Itinerario confirmado con costos garantizados a proveedores
        $itinPartial = $this->createEntityStub('itin-partial', [
            'opportunityId' => 'opp-partial',
            'status' => 'Confirmado',
            'destination' => 'Arequipa, Perú',
            'deleted' => false,
        ]);

        $lineCost = $this->createEntityStub('line-partial', [
            'itinerarioId' => 'itin-partial',
            'supplierId' => 'sup-aqp',
            'costPrice' => 2800.0,
            'deleted' => false,
        ]);

        $supAqp = $this->createEntityStub('sup-aqp', ['name' => 'Transportes Colca SAC', 'deleted' => false]);

        $em = $this->createEntityManagerMock(
            [$oppPartial],
            [$paymentDeposit],
            [$itinPartial],
            [$lineCost],
            [$supAqp]
        );

        $service = new RealProfitabilityService($em);
        $result = $service->getRealProfitability();

        // Ingreso reconciliado = Sólo los $4,000 efectivamente cobrados
        $this->assertEquals(4000.00, $result['reconciled']['totalIncome']);
        $this->assertEquals(2800.00, $result['reconciled']['totalCost']);
        $this->assertEquals(1200.00, $result['reconciled']['grossProfit']);
        $this->assertEquals(30.00, $result['reconciled']['grossMarginRate']);
        $this->assertEquals(1, $result['reconciled']['confirmedBookingsCount']);

        // Saldo pendiente = Los $6,000 restantes a liquidar
        $this->assertEquals(6000.00, $result['unreconciledFunnel']['pendingBalance']);
        $this->assertEquals(1, $result['unreconciledFunnel']['pipelineBookingsCount']);

        // Segmentación B2B Corporate
        $this->assertEquals(4000.00, $result['byClientType']['B2B_Corporate']['income']);
        $this->assertEquals(1200.00, $result['byClientType']['B2B_Corporate']['profit']);
        $this->assertEquals(30.00, $result['byClientType']['B2B_Corporate']['marginRate']);
    }

    /**
     * Test 3: Segmentación Multidimensional y Diferenciación B2C vs B2B.
     */
    public function testMultidimensionalAggregationAndClientTypeSegmentation(): void
    {
        // 1. Venta Directa B2C: Cusco
        $oppB2C = $this->createEntityStub('opp-b2c', [
            'name' => 'Vacaciones Familiares Cusco',
            'stage' => 'Closed Won',
            'amount' => 6000.0,
            'amountPaid' => 6000.0,
            'pendingBalance' => 0.0,
            'destination' => 'Cusco, Perú',
            'clientType' => 'B2C_Direct',
            'deleted' => false,
        ]);
        $payB2C = $this->createEntityStub('pay-b2c', [
            'opportunityId' => 'opp-b2c',
            'status' => 'Confirmed',
            'amount' => 6000.0,
            'deleted' => false,
        ]);
        $itinB2C = $this->createEntityStub('itin-b2c', [
            'opportunityId' => 'opp-b2c',
            'status' => 'Confirmado',
            'destination' => 'Cusco, Perú',
            'deleted' => false,
        ]);
        $lineB2C = $this->createEntityStub('line-b2c', [
            'itinerarioId' => 'itin-b2c',
            'supplierId' => 'sup-hotel-cusco',
            'costPrice' => 4000.0,
            'deleted' => false,
        ]);

        // 2. Venta Corporativa B2B: Lima Eventos
        $oppB2B = $this->createEntityStub('opp-b2b', [
            'name' => 'Convención Anual Farmacéutica',
            'stage' => 'Closed Won',
            'amount' => 10000.0,
            'amountPaid' => 10000.0,
            'pendingBalance' => 0.0,
            'destination' => 'Lima, Perú',
            'clientType' => 'B2B_Corporate',
            'deleted' => false,
        ]);
        $payB2B = $this->createEntityStub('pay-b2b', [
            'opportunityId' => 'opp-b2b',
            'status' => 'Confirmed',
            'amount' => 10000.0,
            'deleted' => false,
        ]);
        $itinB2B = $this->createEntityStub('itin-b2b', [
            'opportunityId' => 'opp-b2b',
            'status' => 'Confirmado',
            'destination' => 'Lima, Perú',
            'deleted' => false,
        ]);
        $lineB2B = $this->createEntityStub('line-b2b', [
            'itinerarioId' => 'itin-b2b',
            'supplierId' => 'sup-centro-convenciones',
            'costPrice' => 8000.0,
            'deleted' => false,
        ]);

        $supCusco = $this->createEntityStub('sup-hotel-cusco', ['name' => 'Hotel Cusco Centro', 'deleted' => false]);
        $supLima = $this->createEntityStub('sup-centro-convenciones', ['name' => 'Centro Convenciones Lima', 'deleted' => false]);

        $em = $this->createEntityManagerMock(
            [$oppB2C, $oppB2B],
            [$payB2C, $payB2B],
            [$itinB2C, $itinB2B],
            [$lineB2C, $lineB2B],
            [$supCusco, $supLima]
        );

        $service = new RealProfitabilityService($em);
        $result = $service->getRealProfitability();

        // Totales reconciliados
        $this->assertEquals(16000.00, $result['reconciled']['totalIncome']);
        $this->assertEquals(12000.00, $result['reconciled']['totalCost']);
        $this->assertEquals(4000.00, $result['reconciled']['grossProfit']);
        $this->assertEquals(25.00, $result['reconciled']['grossMarginRate']);
        $this->assertEquals(2, $result['reconciled']['confirmedBookingsCount']);

        // Desglose por Tipo de Cliente
        // B2C: 6000 - 4000 = 2000 (33.33%)
        $this->assertEquals(6000.00, $result['byClientType']['B2C_Direct']['income']);
        $this->assertEquals(2000.00, $result['byClientType']['B2C_Direct']['profit']);
        $this->assertEquals(33.33, $result['byClientType']['B2C_Direct']['marginRate']);

        // B2B: 10000 - 8000 = 2000 (20.00%)
        $this->assertEquals(10000.00, $result['byClientType']['B2B_Corporate']['income']);
        $this->assertEquals(2000.00, $result['byClientType']['B2B_Corporate']['profit']);
        $this->assertEquals(20.00, $result['byClientType']['B2B_Corporate']['marginRate']);

        // Desglose por Destino ordenado descendente por ingresos
        $this->assertCount(2, $result['byDestination']);
        $this->assertEquals('Lima, Perú', $result['byDestination'][0]['destination']);
        $this->assertEquals(10000.00, $result['byDestination'][0]['reconciledIncome']);
        $this->assertEquals('Cusco, Perú', $result['byDestination'][1]['destination']);
        $this->assertEquals(6000.00, $result['byDestination'][1]['reconciledIncome']);
    }

    /**
     * Test 4: Filtrado Operativo (dateRange, clientType, destination, supplierId).
     */
    public function testFiltersApplication(): void
    {
        $opp1 = $this->createEntityStub('opp-f1', [
            'name' => 'Viaje Cancún 2026',
            'stage' => 'Closed Won',
            'amount' => 4000.0,
            'destination' => 'Cancún, México',
            'clientType' => 'B2C_Direct',
            'deleted' => false,
        ]);
        $pay1 = $this->createEntityStub('pay-f1', [
            'opportunityId' => 'opp-f1',
            'status' => 'Confirmed',
            'amount' => 4000.0,
            'verifiedAt' => '2026-09-10 12:00:00',
            'deleted' => false,
        ]);
        $itin1 = $this->createEntityStub('itin-f1', [
            'opportunityId' => 'opp-f1',
            'status' => 'Confirmado',
            'destination' => 'Cancún, México',
            'deleted' => false,
        ]);
        $line1 = $this->createEntityStub('line-f1', [
            'itinerarioId' => 'itin-f1',
            'supplierId' => 'sup-cancun',
            'costPrice' => 2500.0,
            'deleted' => false,
        ]);

        $opp2 = $this->createEntityStub('opp-f2', [
            'name' => 'Viaje Madrid B2B',
            'stage' => 'Closed Won',
            'amount' => 8000.0,
            'destination' => 'Madrid, España',
            'clientType' => 'B2B_Corporate',
            'deleted' => false,
        ]);
        $pay2 = $this->createEntityStub('pay-f2', [
            'opportunityId' => 'opp-f2',
            'status' => 'Confirmed',
            'amount' => 8000.0,
            'verifiedAt' => '2026-08-20 12:00:00', // Mes anterior
            'deleted' => false,
        ]);
        $itin2 = $this->createEntityStub('itin-f2', [
            'opportunityId' => 'opp-f2',
            'status' => 'Confirmado',
            'destination' => 'Madrid, España',
            'deleted' => false,
        ]);
        $line2 = $this->createEntityStub('line-f2', [
            'itinerarioId' => 'itin-f2',
            'supplierId' => 'sup-madrid',
            'costPrice' => 5000.0,
            'deleted' => false,
        ]);

        $em = $this->createEntityManagerMock(
            [$opp1, $opp2],
            [$pay1, $pay2],
            [$itin1, $itin2],
            [$line1, $line2],
            [
                $this->createEntityStub('sup-cancun', ['name' => 'Operador Cancún', 'deleted' => false]),
                $this->createEntityStub('sup-madrid', ['name' => 'Operador Madrid', 'deleted' => false]),
            ]
        );

        $service = new RealProfitabilityService($em);

        // A) Filtrar por fecha (Septiembre 2026 excluye Madrid de Agosto)
        $resDate = $service->getRealProfitability(['dateFrom' => '2026-09-01', 'dateTo' => '2026-09-30']);
        $this->assertEquals(4000.00, $resDate['reconciled']['totalIncome']);
        $this->assertEquals(1, $resDate['reconciled']['confirmedBookingsCount']);

        // B) Filtrar por Tipo de Cliente (B2B_Corporate)
        $resB2B = $service->getRealProfitability(['clientType' => 'B2B_Corporate']);
        $this->assertEquals(8000.00, $resB2B['reconciled']['totalIncome']);
        $this->assertEquals('Madrid, España', $resB2B['byDestination'][0]['destination']);

        // C) Filtrar por Destino ('Cancún')
        $resDest = $service->getRealProfitability(['destination' => 'Cancún']);
        $this->assertEquals(4000.00, $resDest['reconciled']['totalIncome']);
        $this->assertCount(1, $resDest['byDestination']);
    }

    /**
     * Test 5: Generación y Formato de Archivo CSV Descargable.
     * Valida encabezado UTF-8 BOM, secciones ejecutivas y datos tabulares.
     */
    public function testExportCsvFormatsAndBOM(): void
    {
        $opp = $this->createEntityStub('opp-csv', [
            'name' => 'Expedición Machu Picchu',
            'stage' => 'Closed Won',
            'amount' => 7000.0,
            'amountPaid' => 7000.0,
            'pendingBalance' => 0.0,
            'destination' => 'Cusco, Perú',
            'clientType' => 'B2C_Direct',
            'deleted' => false,
        ]);
        $pay = $this->createEntityStub('pay-csv', [
            'opportunityId' => 'opp-csv',
            'status' => 'Confirmed',
            'amount' => 7000.0,
            'deleted' => false,
        ]);
        $itin = $this->createEntityStub('itin-csv', [
            'opportunityId' => 'opp-csv',
            'status' => 'Confirmado',
            'destination' => 'Cusco, Perú',
            'deleted' => false,
        ]);
        $line = $this->createEntityStub('line-csv', [
            'itinerarioId' => 'itin-csv',
            'supplierId' => 'sup-perurail',
            'costPrice' => 4500.0,
            'deleted' => false,
        ]);
        $sup = $this->createEntityStub('sup-perurail', ['name' => 'Inca Rail SA', 'deleted' => false]);

        $em = $this->createEntityManagerMock([$opp], [$pay], [$itin], [$line], [$sup]);
        $service = new RealProfitabilityService($em);

        // 1. CSV Completo Consolidado
        $csvContent = $service->exportCsv(['destination' => 'Cusco']);

        // Validar BOM UTF-8 (\xEF\xBB\xBF) al inicio
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent, 'El archivo CSV debe iniciar con BOM UTF-8');

        // Validar secciones
        $this->assertStringContainsString('REPORTE DE RENTABILIDAD REAL LIQUIDADA', $csvContent);
        $this->assertStringContainsString('1. RESUMEN EJECUTIVO (RECONCILIADO EN BANCO)', $csvContent);
        $this->assertStringContainsString('2. DINERO EN TRÁMITE (EMBUDO NO RECONCILIADO)', $csvContent);
        $this->assertStringContainsString('3. RENTABILIDAD POR DESTINO', $csvContent);
        $this->assertStringContainsString('4. COSTOS CONSOLIDADOS POR OPERADOR / PROVEEDOR', $csvContent);
        $this->assertStringContainsString('5. RENTABILIDAD POR TIPO DE CLIENTE', $csvContent);

        // Validar datos numéricos
        $this->assertStringContainsString('7000.00', $csvContent);
        $this->assertStringContainsString('4500.00', $csvContent);
        $this->assertStringContainsString('2500.00', $csvContent);
        $this->assertStringContainsString('Inca Rail SA', $csvContent);

        // 2. CSV Tabular de Destinos
        $destCsv = $service->exportDestinationsCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $destCsv);
        $this->assertStringContainsString('Destino', $destCsv);
        $this->assertStringContainsString('Ingreso Reconciliado', $destCsv);
        $this->assertStringContainsString('Costo Confirmado', $destCsv);
        $this->assertStringContainsString('Margen Bruto Real', $destCsv);
        $this->assertStringContainsString('Cusco, Perú', $destCsv);

        // 3. CSV Tabular de Proveedores
        $supCsv = $service->exportSuppliersCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $supCsv);
        $this->assertStringContainsString('ID Operador', $supCsv);
        $this->assertStringContainsString('Nombre Operador', $supCsv);
        $this->assertStringContainsString('Costo Confirmado', $supCsv);
        $this->assertStringContainsString('Inca Rail SA', $supCsv);
    }
}
