<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Services\DestinationQualityService;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

/**
 * Suite de Pruebas Unitarias para DestinationQualityService (HU-04, TASK-052).
 */
class DestinationQualityServiceTest extends TestCase
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
        array $feedbacks,
        array $incidents,
        array $tasks
    ): EntityManager {
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use (
            $feedbacks,
            $incidents,
            $tasks
        ) {
            return match ($type) {
                'Feedback' => $this->createRepositoryStub($feedbacks),
                'Incident' => $this->createRepositoryStub($incidents),
                'Task' => $this->createRepositoryStub($tasks),
                default => $this->createRepositoryStub([]),
            };
        });

        return $em;
    }

    /**
     * Test 1: Cálculo exacto de Net Promoter Score (NPS) y segmentación.
     */
    public function testNpsCalculationAndSentimentSegmentation(): void
    {
        // 10 Encuestas: 6 Promotores (9-10), 2 Pasivos (7-8), 2 Detractores (1-6)
        // NPS = ((6 - 2) / 10) * 100 = 40.0%
        $feedbacks = [
            $this->createEntityStub('fb1', ['npsScore' => 10, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb2', ['npsScore' => 10, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb3', ['npsScore' => 9, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb4', ['npsScore' => 9, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb5', ['npsScore' => 9, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb6', ['npsScore' => 9, 'sentiment' => 'Promoter', 'deleted' => false]),
            $this->createEntityStub('fb7', ['npsScore' => 8, 'sentiment' => 'Passive', 'deleted' => false]),
            $this->createEntityStub('fb8', ['npsScore' => 7, 'sentiment' => 'Passive', 'deleted' => false]),
            $this->createEntityStub('fb9', ['npsScore' => 5, 'sentiment' => 'Detractor', 'deleted' => false]),
            $this->createEntityStub('fb10', ['npsScore' => 2, 'sentiment' => 'Detractor', 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($feedbacks, [], []);
        $service = new DestinationQualityService($em);

        $result = $service->getDestinationQuality();

        $this->assertEquals(10, $result['nps']['totalResponses']);
        $this->assertEquals(6, $result['nps']['promoters']);
        $this->assertEquals(2, $result['nps']['passives']);
        $this->assertEquals(2, $result['nps']['detractors']);
        $this->assertEquals(40.00, $result['nps']['npsScore']);
    }

    /**
     * Test 2: Impacto económico total y promedio de contingencias en viaje.
     */
    public function testContingenciesCostImpactAggregation(): void
    {
        $incidents = [
            $this->createEntityStub('inc1', ['costImpact' => 150.0, 'deleted' => false]),
            $this->createEntityStub('inc2', ['costImpact' => 250.0, 'deleted' => false]),
            $this->createEntityStub('inc3', ['costImpact' => 50.0, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock([], $incidents, []);
        $service = new DestinationQualityService($em);

        $result = $service->getDestinationQuality();

        $this->assertEquals(3, $result['contingency']['incidentsCount']);
        $this->assertEquals(450.00, $result['contingency']['totalCostImpact']);
        $this->assertEquals(150.00, $result['contingency']['averageCostPerIncident']);
    }

    /**
     * Test 3: Cumplimiento de SLA en la atención de detractores (< 2 horas).
     */
    public function testDetractorSlaComplianceUnder2Hours(): void
    {
        $tasks = [
            // Tarea 1: Resuelta en 45 minutos (2700s) -> Cumple SLA
            $this->createEntityStub('t1', [
                'name' => 'Atención Urgente Detractor: Juan',
                'priority' => 'Urgent',
                'status' => 'Completed',
                'createdAt' => '2026-09-10 10:00:00',
                'dateCompleted' => '2026-09-10 10:45:00',
                'deleted' => false,
            ]),
            // Tarea 2: Resuelta en 1 hora y 30 minutos (5400s) -> Cumple SLA
            $this->createEntityStub('t2', [
                'name' => 'Atención Urgente Detractor: Maria',
                'priority' => 'Urgent',
                'status' => 'Completed',
                'createdAt' => '2026-09-10 11:00:00',
                'dateCompleted' => '2026-09-10 12:30:00',
                'deleted' => false,
            ]),
            // Tarea 3: Resuelta en 5 horas (18000s) -> NO cumple SLA
            $this->createEntityStub('t3', [
                'name' => 'Atención Urgente Detractor: Pedro',
                'priority' => 'Urgent',
                'status' => 'Completed',
                'createdAt' => '2026-09-10 09:00:00',
                'dateCompleted' => '2026-09-10 14:00:00',
                'deleted' => false,
            ]),
            // Tarea 4: No urgente o no detractor -> Ignorar
            $this->createEntityStub('t4', [
                'name' => 'Llamar a proveedor regular',
                'priority' => 'Normal',
                'status' => 'Completed',
                'deleted' => false,
            ]),
        ];

        $em = $this->createEntityManagerMock([], [], $tasks);
        $service = new DestinationQualityService($em);

        $result = $service->getDestinationQuality();

        // 3 Tareas de detractor: 2 resueltas en < 2h -> 66.7% compliance
        $this->assertEquals(3, $result['detractorSla']['totalDetractorTasks']);
        $this->assertEquals(2, $result['detractorSla']['resolvedUnder2Hours']);
        $this->assertEquals(66.7, $result['detractorSla']['slaComplianceRate']);
    }

    /**
     * Test 4: Exportación de informe de calidad a CSV con BOM UTF-8.
     */
    public function testExportCsvWithBOM(): void
    {
        $feedbacks = [
            $this->createEntityStub('fb1', ['npsScore' => 10, 'sentiment' => 'Promoter', 'deleted' => false]),
        ];
        $incidents = [
            $this->createEntityStub('inc1', ['costImpact' => 120.0, 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($feedbacks, $incidents, []);
        $service = new DestinationQualityService($em);

        $csv = $service->exportCsv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('REPORTE DE CALIDAD Y CONTINGENCIAS EN DESTINO', $csv);
        $this->assertStringContainsString('1. NET PROMOTER SCORE (NPS)', $csv);
        $this->assertStringContainsString('2. IMPACTO ECONÓMICO DE CONTINGENCIAS', $csv);
        $this->assertStringContainsString('3. SLA DE ATENCIÓN A DETRACTORES (< 2 HORAS)', $csv);
        $this->assertStringContainsString('120.00', $csv);
    }
}
