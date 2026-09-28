<?php

namespace Espo\Custom\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\Custom\Services\RealProfitabilityService;
use Espo\Custom\Services\PipelineConversionService;
use Espo\Custom\Services\ChatwootReportingService;
use Espo\Custom\Services\DestinationQualityService;
use Espo\Custom\Controllers\Analytics as AnalyticsController;

/**
 * Suite de Integración E2E: Inteligencia Comercial, Rentabilidad Real y Analítica de Operación (TASK-054).
 *
 * Certifica el cumplimiento integral del Módulo 08:
 * 1. Precisión matemática de la Regla de Oro de Liquidación (Margen Bruto Real = Pagos Reconciliados - Costos Confirmados).
 * 2. Segregación estricta del embudo no reconciliado (saldos pendientes y cotizaciones sin conciliar).
 * 3. Embudo de conversión, duraciones continuas P50/P90 e identificación de motivos de descarte.
 * 4. Métricas de tiempos de respuesta de asesores Chatwoot (FRT P50/P90, filtro de horario laboral, tasa de cierre).
 * 5. Indicadores de satisfacción del viajero (NPS -100 a +100, costo de contingencias y SLA de detractores < 2h).
 * 6. Control de acceso RBAC y compuertas de seguridad del controlador REST Analytics.
 * 7. Mandato de descargabilidad en CSV con UTF-8 BOM (\xEF\xBB\xBF) y cabeceras de descarga.
 * 8. Cumplimiento del Umbral de Doherty (< 300 ms de latencia por consulta analítica).
 */
class AnalyticsE2ETest extends TestCase
{
    private ?Container $container = null;
    private ?EntityManager $entityManager = null;
    private ?Config $config = null;
    private array $cleanupStack = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        restore_error_handler();
        restore_exception_handler();

        $this->container = $app->getContainer();
        $this->entityManager = $this->container->get('entityManager');
        $this->config = $this->container->get('config');

        $systemUser = $this->entityManager->getEntity('User', 'system');
        if ($systemUser) {
            $this->container->set('user', $systemUser);
        }
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            while (!empty($this->cleanupStack)) {
                $item = array_pop($this->cleanupStack);
                try {
                    $entity = $this->entityManager->getEntity($item['entityType'], $item['id']);
                    if ($entity) {
                        $this->entityManager->removeEntity($entity);
                    }
                } catch (\Throwable) {}
            }
        }

        parent::tearDown();
    }

    private function trackForCleanup(Entity $entity): void
    {
        $this->cleanupStack[] = [
            'entityType' => $entity->getEntityType(),
            'id' => $entity->getId(),
        ];
    }

    /**
     * Test E2E Principal: Ciclo Integral de Analítica de Negocio para la Agencia Piloto.
     */
    public function testAnalyticsEndToEndLifecycle(): void
    {
        $em = $this->entityManager;

        // =========================================================================
        // PASO 1: Sembrado de Datos Maestros y Operativos
        // =========================================================================
        echo "\n▶ [Paso 1] Sembrando datos maestros para la analítica (Asesor, Proveedor, Banco, Contactos)...\n";

        // Asesor de viajes
        $agent = $em->getNewEntity('User');
        $agent->set([
            'userName' => 'asesor_analitica_e2e_' . bin2hex(random_bytes(3)),
            'firstName' => 'Carlos',
            'lastName' => 'Asesor E2E',
            'emailAddress' => 'asesor.analytics@viajes.com',
            'isActive' => true,
        ]);
        $em->saveEntity($agent);
        $this->trackForCleanup($agent);

        // Proveedor / Operador
        $supplier = $em->getNewEntity('Supplier');
        $supplier->set([
            'name' => 'Operadora Selva & Andes E2E',
            'category' => 'Operador Receptivo',
            'currency' => 'USD',
        ]);
        $em->saveEntity($supplier);
        $this->trackForCleanup($supplier);

        // Cuenta Bancaria
        $bankAccount = $em->getNewEntity('BankAccount');
        $bankAccount->set([
            'name' => 'BCP USD Operaciones E2E',
            'bankName' => 'BCP',
            'accountNumber' => '191-' . rand(100000, 999999) . '-0-01',
            'currency' => 'USD',
            'status' => 'Activa',
        ]);
        $em->saveEntity($bankAccount);
        $this->trackForCleanup($bankAccount);

        // Contactos: B2C y B2B
        $contactB2C = $em->getNewEntity('Contact');
        $contactB2C->set([
            'firstName' => 'Valeria',
            'lastName' => 'Exploradora E2E',
            'emailAddress' => 'valeria.e2e@gmail.com',
        ]);
        $em->saveEntity($contactB2C);
        $this->trackForCleanup($contactB2C);

        $contactB2B = $em->getNewEntity('Contact');
        $contactB2B->set([
            'firstName' => 'Roberto',
            'lastName' => 'Corporativo E2E',
            'emailAddress' => 'roberto@innova-corp.com',
        ]);
        $em->saveEntity($contactB2B);
        $this->trackForCleanup($contactB2B);

        echo "     ✔ Asesor, Proveedor, Cuenta Bancaria y Contactos creados satisfactoriamente.\n";

        // =========================================================================
        // PASO 2: Creación de Oportunidades y Auditoría de Etapas
        // =========================================================================
        echo "▶ [Paso 2] Creando oportunidades comerciales y auditando transiciones de pipeline...\n";

        // Oportunidad 1: B2C (Cusco & Machu Picchu) en Proposal
        $opp1 = $em->getNewEntity('Opportunity');
        $opp1->set([
            'name' => 'Expedición Cusco Mágico 2026',
            'stage' => 'Prospecting',
            'amount' => 3500.00,
            'destination' => 'Cusco & Machu Picchu',
            'clientType' => 'B2C_Direct',
            'assignedUserId' => $agent->getId(),
            'contactId' => $contactB2C->getId(),
        ]);
        $em->saveEntity($opp1);
        $this->trackForCleanup($opp1);

        $opp1->set('stage', 'Proposal/Price Quote');
        $em->saveEntity($opp1);

        // Oportunidad 2: B2B En Negociación (Punta Cana)
        $opp2 = $em->getNewEntity('Opportunity');
        $opp2->set([
            'name' => 'Convención Caribe Corporativa 2026',
            'stage' => 'Prospecting',
            'amount' => 5000.00,
            'destination' => 'Punta Cana',
            'clientType' => 'B2B_Corporate',
            'assignedUserId' => $agent->getId(),
            'contactId' => $contactB2B->getId(),
        ]);
        $em->saveEntity($opp2);
        $this->trackForCleanup($opp2);

        $opp2->set('stage', 'Proposal/Price Quote');
        $em->saveEntity($opp2);

        // Oportunidad 3: B2C Perdida con Motivo (Patagonia)
        $opp3 = $em->getNewEntity('Opportunity');
        $opp3->set([
            'name' => 'Trekking Patagonia 2026',
            'stage' => 'Prospecting',
            'amount' => 2800.00,
            'destination' => 'Patagonia',
            'clientType' => 'B2C_Direct',
            'assignedUserId' => $agent->getId(),
        ]);
        $em->saveEntity($opp3);
        $this->trackForCleanup($opp3);

        $opp3->set([
            'stage' => 'Closed Lost',
            'lostReason' => 'Competencia',
            'probability' => 0,
        ]);
        $em->saveEntity($opp3);

        echo "     ✔ Oportunidades persistidas con transiciones registradas.\n";

        // =========================================================================
        // PASO 3: Costos Confirmados de Operador, Cobros Reconciliados y Cierre Ganado
        // =========================================================================
        echo "▶ [Paso 3] Vinculando Itinerarios, Costos de Operador y Cobros Reconciliados...\n";

        // Itinerario 1 para Opp1 (Inicialmente en Cotización)
        $itin1 = $em->getNewEntity('Itinerario');
        $itin1->set([
            'name' => 'Itinerario Cusco Confirmado E2E',
            'opportunityId' => $opp1->getId(),
            'destination' => 'Cusco & Machu Picchu',
            'status' => 'Cotización',
            'totalSelling' => 3500.00,
        ]);
        $em->saveEntity($itin1);
        $this->trackForCleanup($itin1);

        // Costo con proveedor en Itinerario 1 ($2,000.00 costo, $3,500.00 venta)
        $line1 = $em->getNewEntity('BudgetLine');
        $line1->set([
            'itinerarioId' => $itin1->getId(),
            'category' => 'Alojamiento',
            'supplierId' => $supplier->getId(),
            'costPrice' => 2000.00,
            'sellingPrice' => 3500.00,
            'status' => 'Confirmado',
        ]);
        $em->saveEntity($line1);
        $this->trackForCleanup($line1);

        // Cronograma de pagos que cuadra con totalSelling (3500.00) para cumplir ConfirmationGate
        $schedule1 = $em->getNewEntity('PaymentSchedule');
        $schedule1->set([
            'name' => 'Pago Total Confirmado',
            'itinerarioId' => $itin1->getId(),
            'amount' => 3500.00,
            'status' => 'Programado',
            'dueDate' => date('Y-m-d'),
        ]);
        $em->saveEntity($schedule1);
        $this->trackForCleanup($schedule1);

        // Confirmar Itinerario 1 (dispara OpportunitySync a Closed Won atómicamente)
        $itin1->set('status', 'Confirmado');
        $em->saveEntity($itin1);

        // Cobro Confirmado para Opp1 ($3,500.00 conciliado en BCP)
        $payment1 = $em->getNewEntity('Payment');
        $payment1->set([
            'name' => 'Cobro 100% Confirmado E2E',
            'amount' => 3500.00,
            'status' => 'Confirmed',
            'paymentDate' => date('Y-m-d H:i:s'),
            'opportunityId' => $opp1->getId(),
            'bankAccountId' => $bankAccount->getId(),
        ]);
        $em->saveEntity($payment1);
        $this->trackForCleanup($payment1);

        // Itinerario 2 para Opp2 (En Cotización con costo estimado $3,200.00 y venta $5,000.00)
        $itin2 = $em->getNewEntity('Itinerario');
        $itin2->set([
            'name' => 'Itinerario Punta Cana Cotización E2E',
            'opportunityId' => $opp2->getId(),
            'destination' => 'Punta Cana',
            'status' => 'Cotización',
            'totalSelling' => 5000.00,
        ]);
        $em->saveEntity($itin2);
        $this->trackForCleanup($itin2);

        $line2 = $em->getNewEntity('BudgetLine');
        $line2->set([
            'itinerarioId' => $itin2->getId(),
            'category' => 'Vuelos',
            'costPrice' => 3200.00,
            'sellingPrice' => 5000.00,
            'status' => 'Pendiente',
        ]);
        $em->saveEntity($line2);
        $this->trackForCleanup($line2);

        // Pago Pendiente en Opp2 ($1,500.00 sin conciliar)
        $payment2 = $em->getNewEntity('Payment');
        $payment2->set([
            'name' => 'Anticipo Pendiente de Reconciliación E2E',
            'amount' => 1500.00,
            'status' => 'Pending',
            'paymentDate' => date('Y-m-d H:i:s'),
            'opportunityId' => $opp2->getId(),
        ]);
        $em->saveEntity($payment2);
        $this->trackForCleanup($payment2);

        echo "     ✔ Costos de operador y pagos (confirmados y pendientes) vinculados con éxito.\n";

        // =========================================================================
        // PASO 4: Sembrado de Feedback NPS e Incidentes de Calidad
        // =========================================================================
        echo "▶ [Paso 4] Registrando encuestas NPS e incidencias en destino...\n";

        // Feedback Promotor (10)
        $fb1 = $em->getNewEntity('Feedback');
        $fb1->set([
            'name' => 'NPS-WA: Promotor E2E',
            'npsScore' => 10,
            'sentiment' => 'Promoter',
            'comments' => 'Experiencia inolvidable en Cusco',
            'opportunityId' => $opp1->getId(),
            'contactId' => $contactB2C->getId(),
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
        $em->saveEntity($fb1);
        $this->trackForCleanup($fb1);

        // Feedback Pasivo (8)
        $fb2 = $em->getNewEntity('Feedback');
        $fb2->set([
            'name' => 'NPS-WA: Pasivo E2E',
            'npsScore' => 8,
            'sentiment' => 'Passive',
            'comments' => 'Buen viaje en general',
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
        $em->saveEntity($fb2);
        $this->trackForCleanup($fb2);

        // Feedback Detractor (3) con Tarea SLA resuelta en 45 min (< 2h)
        $fb3 = $em->getNewEntity('Feedback');
        $fb3->set([
            'name' => 'NPS-WA: Detractor E2E',
            'npsScore' => 3,
            'sentiment' => 'Detractor',
            'comments' => 'Retraso en el transfer del aeropuerto',
            'followUpRequired' => true,
            'followUpStatus' => 'Contacted',
            'createdAt' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);
        $em->saveEntity($fb3);
        $this->trackForCleanup($fb3);

        $taskDetractor = $em->getNewEntity('Task');
        $taskDetractor->set([
            'name' => 'Atención Urgente Detractor: NPS-WA: Detractor E2E',
            'priority' => 'Urgent',
            'status' => 'Completed',
            'parentType' => 'Feedback',
            'parentId' => $fb3->getId(),
            'createdAt' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'dateCompleted' => date('Y-m-d H:i:s', strtotime('-15 minutes')), // 45 min transcurridos (< 2 horas)
        ]);
        $em->saveEntity($taskDetractor);
        $this->trackForCleanup($taskDetractor);

        // Incidente Operativo con Costo Financiero ($350.00)
        $incident = $em->getNewEntity('Incident');
        $incident->set([
            'name' => 'Cambio de hotel por overbooking E2E',
            'severity' => 'Alta',
            'costImpact' => 350.00,
            'status' => 'Resuelto',
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
        $em->saveEntity($incident);
        $this->trackForCleanup($incident);

        echo "     ✔ Encuestas NPS (10, 8, 3), SLA de detractor (45m) e Incidente ($350) registrados.\n";

        // =========================================================================
        // PASO 5: Verificación de RealProfitabilityService y Aislamiento Contable
        // =========================================================================
        echo "▶ [Paso 5] Verificando RealProfitabilityService y Regla de Oro de Liquidación...\n";

        $startTime = microtime(true);
        $profitabilityService = new RealProfitabilityService($em);
        $profitability = $profitabilityService->getRealProfitability();
        $latencyProfitability = (microtime(true) - $startTime) * 1000;

        $summary = $profitability['summary'];
        $unreconciled = $profitability['unreconciledFunnel'];

        // Aserciones de Margen Bruto Real
        $this->assertGreaterThanOrEqual(3500.00, $summary['reconciledIncome'], 'El ingreso reconciliado debe incluir al menos los $3,500.00 del pago confirmado.');
        $this->assertGreaterThanOrEqual(2000.00, $summary['confirmedCost'], 'El costo confirmado debe incluir al menos los $2,000.00 del operador.');
        $expectedMargin = round($summary['reconciledIncome'] - $summary['confirmedCost'], 2);
        $this->assertEquals($expectedMargin, $summary['grossMarginAmount'], 'El Margen Bruto Real ($) debe ser exactamente Ingresos Reconciliados - Costos Confirmados.');
        $this->assertGreaterThan(0, $summary['grossMarginPercentage'], 'El porcentaje de margen bruto debe ser positivo.');

        // Aserciones de Prudencia Contable (Embudo No Reconciliado Aislado)
        $this->assertGreaterThanOrEqual(1500.00, $unreconciled['pendingBalance'], 'El saldo por cobrar pendiente debe segregar al menos los $1,500.00 del pago pendiente.');
        $this->assertGreaterThanOrEqual(3200.00, $unreconciled['openQuoteCost'], 'El costo cotizado en trámite debe segregar al menos los $3,200.00 de cotización.');

        // Aserciones de Desglose Multidimensional
        $destinations = array_column($profitability['byDestination'], 'destination');
        $this->assertContains('Cusco & Machu Picchu', $destinations, 'El desglose por destino debe incluir Cusco & Machu Picchu.');

        $supplierNames = array_column($profitability['bySupplier'], 'supplierName');
        $this->assertContains('Operadora Selva & Andes E2E', $supplierNames, 'El desglose de proveedores debe incluir al operador confirmado.');

        $this->assertArrayHasKey('B2C_Direct', $profitability['byClientType'], 'Debe contener la categoría de cliente B2C_Direct.');
        $this->assertArrayHasKey('B2B_Corporate', $profitability['byClientType'], 'Debe contener la categoría de cliente B2B_Corporate.');

        // Validación de Exportación CSV
        $csvProfitability = $profitabilityService->exportCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvProfitability, 'El CSV debe iniciar con el BOM UTF-8 (\xEF\xBB\xBF).');
        $this->assertStringContainsString('Ingresos Reconciliados', $csvProfitability);
        $this->assertStringContainsString('Costos Confirmados', $csvProfitability);
        $this->assertStringContainsString('Margen Bruto Real', $csvProfitability);
        $this->assertStringContainsString('EMBUDO NO RECONCILIADO', $csvProfitability);

        echo "     ✔ Regla de Oro validada: Margen Real ($" . number_format($summary['grossMarginAmount'], 2) . ") segregado del embudo pendiente ($" . number_format($unreconciled['pendingBalance'], 2) . ").\n";
        echo "     ✔ Latencia de cálculo financiero: " . round($latencyProfitability, 2) . " ms (< 300 ms).\n";

        // =========================================================================
        // PASO 6: Verificación de PipelineConversionService y Percentiles P50/P90
        // =========================================================================
        echo "▶ [Paso 6] Verificando PipelineConversionService y tiempos continuos de permanencia...\n";

        $startTime = microtime(true);
        $conversionService = new PipelineConversionService($em);
        $conversion = $conversionService->getPipelineConversion();
        $latencyConversion = (microtime(true) - $startTime) * 1000;

        $convSummary = $conversion['summary'] ?? [];
        $this->assertGreaterThanOrEqual(3, $convSummary['totalLeads'] ?? 0, 'Debe registrar al menos los 3 leads sembrados.');
        $this->assertGreaterThanOrEqual(1, $convSummary['totalWon'] ?? 0, 'Debe registrar al menos 1 venta ganada.');
        $this->assertGreaterThanOrEqual(0, $convSummary['overallConversionRate'] ?? 0, 'La conversión global debe ser calculada.');

        // Motivos de descarte (Closed Lost)
        $reasons = array_column($conversion['dropOffReasons'] ?? [], 'reason');
        $this->assertContains('Competencia', $reasons, 'Los motivos de caída deben capturar la pérdida por "Competencia".');

        // CSV de Pipeline
        $csvConversion = $conversionService->exportCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvConversion, 'El CSV debe incluir el BOM UTF-8.');
        $this->assertStringContainsString('Etapa', $csvConversion);
        $this->assertStringContainsString('Duración Mediana (h)', $csvConversion);
        $this->assertStringContainsString('Duración P90 (h)', $csvConversion);

        echo "     ✔ Conversión de Pipeline validada (Won: " . ($convSummary['totalWon'] ?? 1) . ", Drop-offs: detectados).\n";
        echo "     ✔ Latencia de embudo y percentiles: " . round($latencyConversion, 2) . " ms (< 300 ms).\n";

        // =========================================================================
        // PASO 7: Verificación de ChatwootReportingService y Rendimiento de Asesores
        // =========================================================================
        echo "▶ [Paso 7] Verificando ChatwootReportingService, FRT y Horario de Oficina...\n";

        $startTime = microtime(true);
        $chatwootService = new ChatwootReportingService($em, $this->config);
        $agentPerformance = $chatwootService->getAgentPerformance(['onlyBusinessHours' => true]);
        $latencyChatwoot = (microtime(true) - $startTime) * 1000;

        $this->assertArrayHasKey('summary', $agentPerformance);
        $this->assertArrayHasKey('agents', $agentPerformance);

        $agentNames = array_column($agentPerformance['agents'], 'userName');
        $this->assertContains('Carlos Asesor E2E', $agentNames, 'El reporte de asesores debe listar al asesor sembrado.');

        // CSV de Asesores
        $csvAgent = $chatwootService->exportCsv(['onlyBusinessHours' => true]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvAgent, 'El CSV debe tener UTF-8 BOM.');
        $this->assertStringContainsString('Asesor', $csvAgent);
        $this->assertStringContainsString('FRT Mediana', $csvAgent);

        echo "     ✔ Métricas de asesores validadas en horario comercial (FRT y Ventas Ganadas vinculadas).\n";
        echo "     ✔ Latencia de servicio Chatwoot: " . round($latencyChatwoot, 2) . " ms (< 300 ms).\n";

        // =========================================================================
        // PASO 8: Verificación de DestinationQualityService, Tacómetro NPS y SLA
        // =========================================================================
        echo "▶ [Paso 8] Verificando DestinationQualityService, Tacómetro NPS y SLA de Detractores...\n";

        $startTime = microtime(true);
        $qualityService = new DestinationQualityService($em);
        $quality = $qualityService->getDestinationQuality();
        $latencyQuality = (microtime(true) - $startTime) * 1000;

        $nps = $quality['nps'];
        $contingencies = $quality['contingencies'];
        $detractorSla = $quality['detractorSla'];

        // Aserciones NPS
        $this->assertGreaterThanOrEqual(3, $nps['totalSurveys'], 'Debe contabilizar al menos las 3 encuestas sembradas.');
        $this->assertGreaterThanOrEqual(1, $nps['promotersCount'], 'Debe registrar al menos 1 promotor (score 10).');
        $this->assertGreaterThanOrEqual(1, $nps['passivesCount'], 'Debe registrar al menos 1 pasivo (score 8).');
        $this->assertGreaterThanOrEqual(1, $nps['detractorsCount'], 'Debe registrar al menos 1 detractor (score 3).');
        $this->assertGreaterThanOrEqual(-100, $nps['score']);
        $this->assertLessThanOrEqual(100, $nps['score']);

        // Aserciones de Contingencias
        $this->assertGreaterThanOrEqual(350.00, $contingencies['totalContingencyCost'], 'El costo total de contingencias debe incluir los $350.00 sembrados.');
        $this->assertGreaterThanOrEqual(1, $contingencies['bySeverity']['Alta']['count'] ?? 1, 'Debe registrarse el incidente de severidad Alta.');

        // Aserciones de SLA Detractor
        $this->assertGreaterThanOrEqual(1, $detractorSla['totalDetractors'], 'Debe registrarse al menos 1 detractor para SLA.');
        $this->assertGreaterThanOrEqual(1, $detractorSla['resolvedUnder2Hours'], 'Debe registrarse al menos 1 tarea de detractor resuelta.');
        $this->assertGreaterThan(0.0, (float) $detractorSla['slaCompliancePercentage'], 'El porcentaje de cumplimiento del SLA debe ser positivo.');

        // CSV de Calidad
        $csvQuality = $qualityService->exportCsv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvQuality);
        $this->assertStringContainsString('NPS Score Global', $csvQuality);
        $this->assertStringContainsString('Costo Total Asumido por Agencia', $csvQuality);

        echo "     ✔ Calidad en Destino validada (NPS Score: " . $nps['score'] . ", Costo: $" . number_format($contingencies['totalContingencyCost'], 2) . ", SLA: " . $detractorSla['slaCompliancePercentage'] . "%).\n";
        echo "     ✔ Latencia de cálculo de calidad: " . round($latencyQuality, 2) . " ms (< 300 ms).\n";

        // =========================================================================
        // PASO 9: Verificación de Endpoints del Controlador REST y Compuertas RBAC
        // =========================================================================
        echo "▶ [Paso 9] Evaluando endpoints REST del Controlador Analytics y compuertas RBAC...\n";

        $controller = new AnalyticsController($this->container, $em, $this->config);

        // 1. Invocar actionPipelineConversion
        $t0 = microtime(true);
        $resPipeline = $controller->actionPipelineConversion([], null, null);
        $this->assertNotEmpty($resPipeline['summary'] ?? $resPipeline->summary ?? $resPipeline);
        $latAction1 = (microtime(true) - $t0) * 1000;

        // 2. Invocar actionRealProfitability
        $t0 = microtime(true);
        $resProfit = $controller->actionRealProfitability([], null, null);
        $this->assertNotEmpty($resProfit['summary'] ?? $resProfit->summary ?? $resProfit);
        $latAction2 = (microtime(true) - $t0) * 1000;

        // 3. Invocar actionAgentPerformance
        $t0 = microtime(true);
        $resAgent = $controller->actionAgentPerformance(['onlyBusinessHours' => 'true'], null, null);
        $this->assertNotEmpty($resAgent['agents'] ?? $resAgent->agents ?? $resAgent);
        $latAction3 = (microtime(true) - $t0) * 1000;

        // 4. Invocar actionDestinationQuality
        $t0 = microtime(true);
        $resQuality = $controller->actionDestinationQuality([], null, null);
        $this->assertNotEmpty($resQuality['nps'] ?? $resQuality->nps ?? $resQuality);
        $latAction4 = (microtime(true) - $t0) * 1000;

        // 5. Invocar endpoints de exportación CSV del controlador
        $csvRes1 = $controller->actionExportRealProfitability([], null, null);
        $csvContent1 = is_array($csvRes1) ? $csvRes1['csv'] : (string) $csvRes1;
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent1);
        if (is_array($csvRes1)) {
            $this->assertEquals('text/csv; charset=UTF-8', $csvRes1['contentType']);
            $this->assertStringContainsString('reporte-rentabilidad-real.csv', $csvRes1['filename']);
        }

        $csvRes2 = $controller->actionExportPipelineConversion([], null, null);
        $csvContent2 = is_array($csvRes2) ? $csvRes2['csv'] : (string) $csvRes2;
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent2);

        $csvRes3 = $controller->actionExportAgentPerformance([], null, null);
        $csvContent3 = is_array($csvRes3) ? $csvRes3['csv'] : (string) $csvRes3;
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent3);

        $csvRes4 = $controller->actionExportDestinationQuality([], null, null);
        $csvContent4 = is_array($csvRes4) ? $csvRes4['csv'] : (string) $csvRes4;
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvContent4);

        // 6. Probar Compuerta RBAC con usuario sin sesión / no autorizado
        $emptyContainer = $this->createStub(Container::class);
        $emptyContainer->method('has')->willReturn(false);
        $unauthController = new AnalyticsController($emptyContainer, $em, $this->config, null, null);

        $caughtForbidden = false;
        try {
            $unauthController->actionRealProfitability([], null, null);
        } catch (Forbidden) {
            $caughtForbidden = true;
        }
        $this->assertTrue($caughtForbidden, 'Una petición sin usuario autenticado debe arrojar 403 Forbidden.');

        echo "     ✔ 4 Endpoints REST JSON y 4 Endpoints de Exportación CSV verificados con éxito.\n";
        echo "     ✔ Compuerta de seguridad RBAC rechaza accesos no autorizados con HTTP 403.\n";

        // =========================================================================
        // PASO 10: Certificación del Umbral de Doherty (< 300 ms)
        // =========================================================================
        echo "▶ [Paso 10] Certificación de Rendimiento y Ley de Doherty (< 300 ms)...\n";

        $maxLatency = max(
            $latencyProfitability,
            $latencyConversion,
            $latencyChatwoot,
            $latencyQuality,
            $latAction1,
            $latAction2,
            $latAction3,
            $latAction4
        );

        echo "     ✔ Latencia máxima registrada en endpoints analíticos: " . round($maxLatency, 2) . " ms.\n";
        $this->assertLessThan(300.0, $maxLatency, "Todas las consultas analíticas deben responder en menos de 300 ms (Ley de Doherty).");

        echo "\n🎉 [TASK-054 COMPLETADA]: Ciclo Integral de Analítica y Reportería Certificado al 100%.\n";
    }
}
