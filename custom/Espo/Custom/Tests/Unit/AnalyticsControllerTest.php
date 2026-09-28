<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Controllers\Analytics;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Services\RealProfitabilityService;
use Espo\Custom\Services\PipelineConversionService;
use Espo\Custom\Services\ChatwootReportingService;
use Espo\Custom\Services\DestinationQualityService;

/**
 * Suite de Pruebas Unitarias para TASK-052:
 * Analytics REST Controller - Control de acceso RBAC, respuestas de endpoints analíticos y descargas CSV.
 */
class AnalyticsControllerTest extends TestCase
{
    private function createController(
        ?User $user = null,
        ?Acl $acl = null,
        ?RealProfitabilityService $profitabilityService = null,
        ?PipelineConversionService $conversionService = null,
        ?ChatwootReportingService $chatwootService = null,
        ?DestinationQualityService $destinationQualityService = null
    ): Analytics {
        $container = $this->createStub(Container::class);
        $em = $this->createStub(EntityManager::class);
        $config = $this->createStub(Config::class);

        $controller = new Analytics($container, $em, $config, $user, $acl);

        if ($profitabilityService !== null) {
            $controller->setProfitabilityService($profitabilityService);
        }
        if ($conversionService !== null) {
            $controller->setConversionService($conversionService);
        }
        if ($chatwootService !== null) {
            $controller->setChatwootService($chatwootService);
        }
        if ($destinationQualityService !== null) {
            $controller->setDestinationQualityService($destinationQualityService);
        }

        return $controller;
    }

    private function createAdminUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn(true);
        $user->method('getId')->willReturn('admin-01');
        return $user;
    }

    private function createManagerUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getId')->willReturn('mgr-01');

        $role = $this->createStub(\Espo\ORM\Entity::class);
        $role->method('get')->willReturnCallback(fn($f) => $f === 'name' ? 'Gerente General' : null);

        $user->method('get')->willReturnCallback(fn($f) => $f === 'roles' ? [$role] : null);

        return $user;
    }

    private function createUnauthorizedUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('getId')->willReturn('guest-01');
        $user->method('get')->willReturn(null);
        return $user;
    }

    /**
     * Test 1: Endpoint GET /api/v1/Analytics/pipeline-conversion
     */
    public function testGetPipelineConversionActionSuccess(): void
    {
        $conversionService = $this->createStub(PipelineConversionService::class);
        $expectedPayload = [
            'summary' => [
                'totalLeads' => 100,
                'totalWon' => 25,
                'overallConversionRate' => 25.0,
            ],
            'stages' => [
                [
                    'stage' => 'Prospecting',
                    'count' => 100,
                    'conversionToNext' => 80.0,
                    'dropOffCount' => 20,
                    'medianDurationHours' => 4.0,
                    'p90DurationHours' => 18.0,
                ],
            ],
            'dropOffReasons' => [
                ['reason' => 'Precio / Presupuesto Alto', 'count' => 15, 'percentage' => 75.0],
            ],
        ];
        $conversionService->method('getPipelineConversion')->willReturn($expectedPayload);

        $controller = $this->createController(
            user: $this->createAdminUser(),
            conversionService: $conversionService
        );

        $start = microtime(true);
        $result = $controller->actionPipelineConversion();
        $durationMs = (microtime(true) - $start) * 1000;

        // Umbral de Doherty (< 400 ms)
        $this->assertLessThan(400.0, $durationMs);
        $this->assertEquals($expectedPayload, $result);
    }

    /**
     * Test 2: Endpoint GET /api/v1/Analytics/real-profitability
     */
    public function testGetRealProfitabilityActionSuccess(): void
    {
        $profitabilityService = $this->createStub(RealProfitabilityService::class);
        $expectedPayload = [
            'reconciled' => [
                'totalIncome' => 50000.00,
                'totalCost' => 35000.00,
                'grossProfit' => 15000.00,
                'grossMarginRate' => 30.00,
                'confirmedBookingsCount' => 12,
            ],
            'unreconciledFunnel' => [
                'pendingBalance' => 8000.00,
                'unconfirmedQuotesCost' => 5000.00,
                'pipelineBookingsCount' => 4,
            ],
            'byDestination' => [
                [
                    'destination' => 'Cusco, Perú',
                    'reconciledIncome' => 50000.00,
                    'reconciledCost' => 35000.00,
                    'grossProfit' => 15000.00,
                    'marginRate' => 30.00,
                ],
            ],
            'bySupplier' => [],
            'byClientType' => [
                'B2C_Direct' => ['income' => 50000.00, 'profit' => 15000.00, 'marginRate' => 30.00],
                'B2B_Corporate' => ['income' => 0.0, 'profit' => 0.0, 'marginRate' => 0.0],
            ],
        ];
        $profitabilityService->method('getRealProfitability')->willReturn($expectedPayload);

        $controller = $this->createController(
            user: $this->createManagerUser(),
            profitabilityService: $profitabilityService
        );

        $start = microtime(true);
        $result = $controller->actionRealProfitability();
        $durationMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(400.0, $durationMs);
        $this->assertEquals($expectedPayload, $result);
    }

    /**
     * Test 3: Endpoint GET /api/v1/Analytics/agent-performance
     */
    public function testGetAgentPerformanceActionSuccess(): void
    {
        $chatwootService = $this->createStub(ChatwootReportingService::class);
        $expectedPayload = [
            'agents' => [
                [
                    'userId' => 'usr-001',
                    'userName' => 'Carlos Asesor',
                    'conversationsCount' => 85,
                    'closedWonCount' => 24,
                    'conversionRate' => 28.24,
                    'frtMedianMinutes' => 6.5,
                    'frtP90Minutes' => 22.0,
                    'businessHoursCompliance' => 94.5,
                ],
            ],
        ];
        $chatwootService->method('getAgentPerformance')->willReturn($expectedPayload);

        $controller = $this->createController(
            user: $this->createAdminUser(),
            chatwootService: $chatwootService
        );

        $start = microtime(true);
        $result = $controller->actionAgentPerformance();
        $durationMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(400.0, $durationMs);
        $this->assertEquals($expectedPayload, $result);
    }

    /**
     * Test 4: Endpoint GET /api/v1/Analytics/destination-quality
     */
    public function testGetDestinationQualityActionSuccess(): void
    {
        $qualityService = $this->createStub(DestinationQualityService::class);
        $expectedPayload = [
            'nps' => [
                'totalResponses' => 64,
                'promoters' => 48,
                'passives' => 11,
                'detractors' => 5,
                'npsScore' => 67.18,
            ],
            'contingency' => [
                'incidentsCount' => 4,
                'totalCostImpact' => 420.00,
                'averageCostPerIncident' => 105.00,
            ],
            'detractorSla' => [
                'totalDetractorTasks' => 5,
                'resolvedUnder2Hours' => 4,
                'slaComplianceRate' => 80.0,
            ],
        ];
        $qualityService->method('getDestinationQuality')->willReturn($expectedPayload);

        $controller = $this->createController(
            user: $this->createAdminUser(),
            destinationQualityService: $qualityService
        );

        $start = microtime(true);
        $result = $controller->actionDestinationQuality();
        $durationMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(400.0, $durationMs);
        $this->assertEquals($expectedPayload, $result);
    }

    /**
     * Test 5: Control de Acceso RBAC - Usuario no autorizado recibe HTTP 403 Forbidden.
     */
    public function testRbacForbiddenForUnauthorizedUser(): void
    {
        $aclMock = $this->createStub(Acl::class);
        $aclMock->method('check')->willReturn(false);

        $controller = $this->createController(
            user: $this->createUnauthorizedUser(),
            acl: $aclMock
        );

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('Acceso denegado. Se requieren permisos de Administración, Dirección o Gerencia para consultar la analítica.');

        $controller->actionRealProfitability();
    }

    /**
     * Test 6: Descargas CSV vía parámetro ?format=csv y endpoints dedicados.
     */
    public function testCsvExportOutputsWithBOM(): void
    {
        $profitabilityService = $this->createStub(RealProfitabilityService::class);
        $dummyCsv = "\xEF\xBB\xBFREPORTE DE RENTABILIDAD REAL\nIngresos Reconciliados,50000.00\n";
        $profitabilityService->method('exportCsv')->willReturn($dummyCsv);

        $controller = $this->createController(
            user: $this->createAdminUser(),
            profitabilityService: $profitabilityService
        );

        // A) Vía exportAction dedicado
        $resultExport = $controller->actionExportRealProfitability();
        $this->assertIsArray($resultExport);
        $this->assertEquals('reporte-rentabilidad-real.csv', $resultExport['filename']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $resultExport['csv']);

        // B) Vía parámetro format=csv
        $resultQuery = $controller->actionRealProfitability(['format' => 'csv']);
        $this->assertIsArray($resultQuery);
        $this->assertEquals('reporte-rentabilidad-real.csv', $resultQuery['filename']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $resultQuery['csv']);
    }
}
