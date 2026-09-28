<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Services\ChatwootReportingService;
use Espo\Core\Utils\Config;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

/**
 * Suite de Pruebas Unitarias para TASK-051:
 * ChatwootReportingService - Métricas de Velocidad de Respuesta (FRT) y Rendimiento por Asesor.
 */
class ChatwootReportingServiceTest extends TestCase
{
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

    private function createRepositoryStub(array $entities): RDBRepository
    {
        $builder = $this->createStub(RDBSelectBuilder::class);
        $builder->method('find')->willReturn(new EntityCollection($entities));

        $repo = $this->createStub(RDBRepository::class);
        $repo->method('where')->willReturn($builder);

        return $repo;
    }

    private function createEntityManagerMock(
        array $opportunities,
        array $stageHistories,
        array $users
    ): EntityManager {
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use (
            $opportunities,
            $stageHistories,
            $users
        ) {
            return match ($type) {
                'Opportunity' => $this->createRepositoryStub($opportunities),
                'OpportunityStageHistory' => $this->createRepositoryStub($stageHistories),
                'User' => $this->createRepositoryStub($users),
                default => $this->createRepositoryStub([]),
            };
        });

        return $em;
    }

    /**
     * Test 1: Fallback resiliente offline (Ley de Postel).
     * Sin API de Chatwoot configurada, calcula FRT, tasa de cierre y volumen desde EspoCRM.
     */
    public function testNativeResilientFallbackWithoutChatwootConfig(): void
    {
        $configStub = $this->createStub(Config::class);
        $configStub->method('get')->willReturn(null);

        // 2 Asesores
        $userCarlos = $this->createEntityStub('usr-carlos', ['name' => 'Carlos Asesor', 'isActive' => true, 'deleted' => false]);
        $userMariana = $this->createEntityStub('usr-mariana', ['name' => 'Mariana Asesora', 'isActive' => true, 'deleted' => false]);

        // Oportunidades: Carlos tiene 3 (1 ganada), Mariana tiene 2 (2 ganadas)
        $opps = [
            $this->createEntityStub('opp-c1', ['assignedUserId' => 'usr-carlos', 'stage' => 'Closed Won', 'deleted' => false]),
            $this->createEntityStub('opp-c2', ['assignedUserId' => 'usr-carlos', 'stage' => 'Negotiation', 'deleted' => false]),
            $this->createEntityStub('opp-c3', ['assignedUserId' => 'usr-carlos', 'stage' => 'Closed Lost', 'deleted' => false]),

            $this->createEntityStub('opp-m1', ['assignedUserId' => 'usr-mariana', 'stage' => 'Closed Won', 'deleted' => false]),
            $this->createEntityStub('opp-m2', ['assignedUserId' => 'usr-mariana', 'stage' => 'Closed Won', 'deleted' => false]),
        ];

        // Tiempos de primera respuesta en etapa 'Prospecting' (Miércoles 11:00 am - horario laboral)
        $history = [
            // Carlos: 300s (5m), 600s (10m), 900s (15m) -> Mediana = 10m, P90 = 14m
            $this->createEntityStub('h-c1', ['assignedUserId' => 'usr-carlos', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 11:00:00', 'durationSeconds' => 300, 'deleted' => false]),
            $this->createEntityStub('h-c2', ['assignedUserId' => 'usr-carlos', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 11:10:00', 'durationSeconds' => 600, 'deleted' => false]),
            $this->createEntityStub('h-c3', ['assignedUserId' => 'usr-carlos', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 11:20:00', 'durationSeconds' => 900, 'deleted' => false]),

            // Mariana: 120s (2m), 240s (4m) -> Mediana = 3m, P90 = 3.8m
            $this->createEntityStub('h-m1', ['assignedUserId' => 'usr-mariana', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 14:00:00', 'durationSeconds' => 120, 'deleted' => false]),
            $this->createEntityStub('h-m2', ['assignedUserId' => 'usr-mariana', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 15:00:00', 'durationSeconds' => 240, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($opps, $history, [$userCarlos, $userMariana]);
        $service = new ChatwootReportingService($em, $configStub);

        $result = $service->getAgentPerformance();

        $this->assertArrayHasKey('agents', $result);
        $this->assertCount(2, $result['agents']);

        // Mariana tiene 2 ganadas, aparece primero por ordenamiento desc
        $mariana = $result['agents'][0];
        $this->assertEquals('usr-mariana', $mariana['userId']);
        $this->assertEquals('Mariana Asesora', $mariana['userName']);
        $this->assertEquals(2, $mariana['conversationsCount']);
        $this->assertEquals(2, $mariana['closedWonCount']);
        $this->assertEquals(100.00, $mariana['conversionRate']);
        $this->assertEquals(3.0, $mariana['frtMedianMinutes']);
        $this->assertEquals(3.8, $mariana['frtP90Minutes']);
        $this->assertEquals(100.0, $mariana['businessHoursCompliance']);

        // Carlos tiene 3 conversaciones, 1 ganada
        $carlos = $result['agents'][1];
        $this->assertEquals('usr-carlos', $carlos['userId']);
        $this->assertEquals('Carlos Asesor', $carlos['userName']);
        $this->assertEquals(3, $carlos['conversationsCount']);
        $this->assertEquals(1, $carlos['closedWonCount']);
        $this->assertEquals(33.33, $carlos['conversionRate']);
        $this->assertEquals(10.0, $carlos['frtMedianMinutes']);
        $this->assertEquals(14.0, $carlos['frtP90Minutes']);
        $this->assertEquals(100.0, $carlos['businessHoursCompliance']);
    }

    /**
     * Test 2: Enriquecimiento mediante respuesta simulada de la API de Chatwoot.
     */
    public function testChatwootApiEnrichment(): void
    {
        $configStub = $this->createStub(Config::class);
        $configStub->method('get')->willReturnCallback(fn($key) => match ($key) {
            'chatwootBaseUrl' => 'https://chat.agencia.com',
            'chatwootAccountId' => '1',
            'chatwootApiToken' => 'test_token_chatwoot_123',
            default => null,
        });

        $user = $this->createEntityStub('usr-001', ['name' => 'Carlos Asesor', 'isActive' => true, 'deleted' => false]);
        $opps = [
            $this->createEntityStub('opp-1', ['assignedUserId' => 'usr-001', 'stage' => 'Closed Won', 'deleted' => false]),
        ];
        $history = [
            $this->createEntityStub('h-1', ['assignedUserId' => 'usr-001', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 10:00:00', 'durationSeconds' => 420, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($opps, $history, [$user]);

        // Crear subclase de prueba para simular la llamada HTTP a Chatwoot sin curl real
        $service = new class($em, $configStub) extends ChatwootReportingService {
            protected function fetchChatwootReport(string $url, string $apiToken): ?array
            {
                return [
                    [
                        'id' => 10,
                        'name' => 'Carlos Asesor',
                        'email' => 'carlos@agencia.com',
                        'metric' => [
                            'conversations_count' => 85,
                            'avg_first_response_time' => 390, // 6.5 minutos
                        ],
                    ],
                ];
            }
        };

        $result = $service->getAgentPerformance();

        $this->assertCount(1, $result['agents']);
        $agent = $result['agents'][0];
        $this->assertEquals('Carlos Asesor', $agent['userName']);
        $this->assertEquals(6.5, $agent['frtMedianMinutes']);
        $this->assertEquals(9.8, $agent['frtP90Minutes']);
    }

    /**
     * Test 3: Cumplimiento de Horario de Oficina (09:00 - 18:00 L-V) y Filtro onlyBusinessHours.
     */
    public function testBusinessHoursComplianceAndFiltering(): void
    {
        $configStub = $this->createStub(Config::class);
        $user = $this->createEntityStub('usr-horarios', ['name' => 'Asesor Turno', 'isActive' => true, 'deleted' => false]);

        $opps = [
            $this->createEntityStub('opp-h1', ['assignedUserId' => 'usr-horarios', 'stage' => 'Prospecting', 'deleted' => false]),
            $this->createEntityStub('opp-h2', ['assignedUserId' => 'usr-horarios', 'stage' => 'Prospecting', 'deleted' => false]),
        ];

        $history = [
            // Contacto 1: Miércoles 14:00 (Dentro de horario) -> 180s (3m)
            $this->createEntityStub('h1', ['assignedUserId' => 'usr-horarios', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 14:00:00', 'durationSeconds' => 180, 'deleted' => false]),
            // Contacto 2: Sábado 23:00 (Fuera de horario) -> 36000s (600m)
            $this->createEntityStub('h2', ['assignedUserId' => 'usr-horarios', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-19 23:00:00', 'durationSeconds' => 36000, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($opps, $history, [$user]);
        $service = new ChatwootReportingService($em, $configStub);

        // Sin filtro de horario: 1 de 2 en horario -> 50% compliance
        $resAll = $service->getAgentPerformance();
        $this->assertEquals(50.0, $resAll['agents'][0]['businessHoursCompliance']);

        // Con filtro onlyBusinessHours = true: Excluye el mensaje nocturno del cálculo de FRT
        $resBusiness = $service->getAgentPerformance(['onlyBusinessHours' => true]);
        // Solo cuenta el contacto de 180s (3m)
        $this->assertEquals(3.0, $resBusiness['agents'][0]['frtMedianMinutes']);
        $this->assertEquals(3.0, $resBusiness['agents'][0]['frtP90Minutes']);
    }

    /**
     * Test 4: Exportación de métricas de asesores a CSV con BOM UTF-8.
     */
    public function testExportCsvWithBOM(): void
    {
        $configStub = $this->createStub(Config::class);
        $user = $this->createEntityStub('usr-1', ['name' => 'Carlos Asesor', 'isActive' => true, 'deleted' => false]);
        $opps = [
            $this->createEntityStub('opp-1', ['assignedUserId' => 'usr-1', 'stage' => 'Closed Won', 'deleted' => false]),
        ];
        $history = [
            $this->createEntityStub('h-1', ['assignedUserId' => 'usr-1', 'stage' => 'Prospecting', 'enteredAt' => '2026-09-16 10:00:00', 'durationSeconds' => 390, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($opps, $history, [$user]);
        $service = new ChatwootReportingService($em, $configStub);

        $csv = $service->exportCsv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('REPORTE DE RENDIMIENTO Y VELOCIDAD DE ATENCIÓN POR ASESOR', $csv);
        $this->assertStringContainsString('Carlos Asesor', $csv);
        $this->assertStringContainsString('6.5', $csv);
    }
}
