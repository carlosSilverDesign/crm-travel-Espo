<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Services\PipelineConversionService;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

/**
 * Suite de Pruebas Unitarias para TASK-051:
 * PipelineConversionService - Conversión de embudo, percentiles de permanencia y drop-off.
 */
class PipelineConversionServiceTest extends TestCase
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

    private function createEntityManagerMock(array $stageHistories, array $opportunities): EntityManager
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use ($stageHistories, $opportunities) {
            return match ($type) {
                'OpportunityStageHistory' => $this->createRepositoryStub($stageHistories),
                'Opportunity' => $this->createRepositoryStub($opportunities),
                default => $this->createRepositoryStub([]),
            };
        });

        return $em;
    }

    /**
     * Test 1: Embudo completo de ventas y cálculo de tasas de avance y caída.
     */
    public function testFullPipelineFunnelConversionAndDropOff(): void
    {
        // 4 Oportunidades:
        // opp-1: Llega hasta Closed Won (pasa por las 6 etapas)
        // opp-2: Llega hasta Negotiation (Prospecting -> Qualification -> Proposal -> Negotiation)
        // opp-3: Llega hasta Proposal (Prospecting -> Qualification -> Proposal)
        // opp-4: Se queda en Prospecting (Prospecting)
        $history = [
            // opp-1
            $this->createEntityStub('h1_1', ['opportunityId' => 'opp-1', 'stage' => 'Prospecting', 'durationSeconds' => 7200, 'deleted' => false]), // 2h
            $this->createEntityStub('h1_2', ['opportunityId' => 'opp-1', 'stage' => 'Qualification', 'durationSeconds' => 14400, 'deleted' => false]), // 4h
            $this->createEntityStub('h1_3', ['opportunityId' => 'opp-1', 'stage' => 'Proposal', 'durationSeconds' => 28800, 'deleted' => false]), // 8h
            $this->createEntityStub('h1_4', ['opportunityId' => 'opp-1', 'stage' => 'Negotiation', 'durationSeconds' => 36000, 'deleted' => false]), // 10h
            $this->createEntityStub('h1_5', ['opportunityId' => 'opp-1', 'stage' => 'PaymentPending', 'durationSeconds' => 18000, 'deleted' => false]), // 5h
            $this->createEntityStub('h1_6', ['opportunityId' => 'opp-1', 'stage' => 'Closed Won', 'durationSeconds' => 0, 'deleted' => false]),

            // opp-2
            $this->createEntityStub('h2_1', ['opportunityId' => 'opp-2', 'stage' => 'Prospecting', 'durationSeconds' => 10800, 'deleted' => false]), // 3h
            $this->createEntityStub('h2_2', ['opportunityId' => 'opp-2', 'stage' => 'Qualification', 'durationSeconds' => 21600, 'deleted' => false]), // 6h
            $this->createEntityStub('h2_3', ['opportunityId' => 'opp-2', 'stage' => 'Proposal', 'durationSeconds' => 43200, 'deleted' => false]), // 12h
            $this->createEntityStub('h2_4', ['opportunityId' => 'opp-2', 'stage' => 'Negotiation', 'durationSeconds' => 72000, 'deleted' => false]), // 20h

            // opp-3
            $this->createEntityStub('h3_1', ['opportunityId' => 'opp-3', 'stage' => 'Prospecting', 'durationSeconds' => 14400, 'deleted' => false]), // 4h
            $this->createEntityStub('h3_2', ['opportunityId' => 'opp-3', 'stage' => 'Qualification', 'durationSeconds' => 28800, 'deleted' => false]), // 8h
            $this->createEntityStub('h3_3', ['opportunityId' => 'opp-3', 'stage' => 'Proposal', 'durationSeconds' => 57600, 'deleted' => false]), // 16h

            // opp-4
            $this->createEntityStub('h4_1', ['opportunityId' => 'opp-4', 'stage' => 'Prospecting', 'durationSeconds' => 18000, 'deleted' => false]), // 5h
        ];

        $opps = [
            $this->createEntityStub('opp-1', ['stage' => 'Closed Won', 'deleted' => false]),
            $this->createEntityStub('opp-2', ['stage' => 'Negotiation', 'deleted' => false]),
            $this->createEntityStub('opp-3', ['stage' => 'Closed Lost', 'lostReason' => 'Precio / Presupuesto Alto', 'deleted' => false]),
            $this->createEntityStub('opp-4', ['stage' => 'Closed Lost', 'lostReason' => 'Sin Respuesta / Fantasma', 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($history, $opps);
        $service = new PipelineConversionService($em);

        $result = $service->getPipelineConversion();

        // 1. Resumen Global
        $this->assertEquals(4, $result['summary']['totalLeads']);
        $this->assertEquals(1, $result['summary']['totalWon']);
        $this->assertEquals(25.00, $result['summary']['overallConversionRate']);

        // 2. Etapa por Etapa
        // Prospecting: 4 opps -> 3 avanzaron a Qualification (75.0%), 1 drop-off
        $stageProspecting = $result['stages'][0];
        $this->assertEquals('Prospecting', $stageProspecting['stage']);
        $this->assertEquals(4, $stageProspecting['count']);
        $this->assertEquals(75.00, $stageProspecting['conversionToNext']);
        $this->assertEquals(1, $stageProspecting['dropOffCount']);
        // Duraciones Prospecting: [2h, 3h, 4h, 5h] -> Mediana = (3+4)/2 = 3.5h, P90 = 4.7h
        $this->assertEquals(3.5, $stageProspecting['medianDurationHours']);
        $this->assertEquals(4.7, $stageProspecting['p90DurationHours']);

        // Qualification: 3 opps -> 3 avanzaron a Proposal (100.0%), 0 drop-off
        $stageQualification = $result['stages'][1];
        $this->assertEquals('Qualification', $stageQualification['stage']);
        $this->assertEquals(3, $stageQualification['count']);
        $this->assertEquals(100.00, $stageQualification['conversionToNext']);
        $this->assertEquals(0, $stageQualification['dropOffCount']);

        // Proposal: 3 opps -> 2 avanzaron a Negotiation (66.67%), 1 drop-off
        $stageProposal = $result['stages'][2];
        $this->assertEquals('Proposal', $stageProposal['stage']);
        $this->assertEquals(3, $stageProposal['count']);
        $this->assertEquals(66.67, $stageProposal['conversionToNext']);
        $this->assertEquals(1, $stageProposal['dropOffCount']);

        // Closed Won: 1 opp -> final
        $stageWon = $result['stages'][5];
        $this->assertEquals('Closed Won', $stageWon['stage']);
        $this->assertEquals(1, $stageWon['count']);
        $this->assertEquals(100.00, $stageWon['conversionToNext']);
        $this->assertEquals(0, $stageWon['dropOffCount']);

        // 3. Drop-off reasons: 2 cerradas perdidas (Precio: 1 (50%), Sin Respuesta: 1 (50%))
        $this->assertCount(2, $result['dropOffReasons']);
        $this->assertEquals(50.00, $result['dropOffReasons'][0]['percentage']);
        $this->assertEquals(50.00, $result['dropOffReasons'][1]['percentage']);
    }

    /**
     * Test 2: Verificación matemática de algoritmo de Percentil (Mediana y P90).
     */
    public function testExactPercentileCalculationAlgorithm(): void
    {
        $service = new PipelineConversionService($this->createStub(EntityManager::class));

        // Arreglo impar ordenado: [10, 20, 30, 40, 50]
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];
        $this->assertEquals(30.0, $service->calculatePercentile($values, 50.0));
        // P90: index = 0.9 * 4 = 3.6 -> 40*0.4 + 50*0.6 = 46.0
        $this->assertEquals(46.0, $service->calculatePercentile($values, 90.0));

        // Arreglo vacío y unitario
        $this->assertEquals(0.0, $service->calculatePercentile([], 50.0));
        $this->assertEquals(15.5, $service->calculatePercentile([15.5], 50.0));
        $this->assertEquals(15.5, $service->calculatePercentile([15.5], 90.0));
    }

    /**
     * Test 3: Filtros por Canal (leadSource) y Asesor (assignedUserId).
     */
    public function testPipelineConversionFilters(): void
    {
        $history = [
            $this->createEntityStub('h1', [
                'opportunityId' => 'opp-wa-1',
                'stage' => 'Prospecting',
                'leadSource' => 'WhatsApp',
                'assignedUserId' => 'user-carlos',
                'enteredAt' => '2026-09-10 10:00:00',
                'durationSeconds' => 3600,
                'deleted' => false,
            ]),
            $this->createEntityStub('h2', [
                'opportunityId' => 'opp-wa-1',
                'stage' => 'Qualification',
                'leadSource' => 'WhatsApp',
                'assignedUserId' => 'user-carlos',
                'enteredAt' => '2026-09-10 11:00:00',
                'durationSeconds' => 7200,
                'deleted' => false,
            ]),
            $this->createEntityStub('h3', [
                'opportunityId' => 'opp-web-1',
                'stage' => 'Prospecting',
                'leadSource' => 'Web',
                'assignedUserId' => 'user-mariana',
                'enteredAt' => '2026-09-15 10:00:00',
                'durationSeconds' => 1800,
                'deleted' => false,
            ]),
        ];

        $em = $this->createEntityManagerMock($history, []);
        $service = new PipelineConversionService($em);

        // A) Filtrar por canal WhatsApp: Solo opp-wa-1
        $resWhatsApp = $service->getPipelineConversion(['leadSource' => 'WhatsApp']);
        $this->assertEquals(1, $resWhatsApp['summary']['totalLeads']);
        $this->assertEquals(1, $resWhatsApp['stages'][0]['count']);
        $this->assertEquals(1, $resWhatsApp['stages'][1]['count']);

        // B) Filtrar por Asesor Mariana: Solo opp-web-1
        $resMariana = $service->getPipelineConversion(['assignedUserId' => 'user-mariana']);
        $this->assertEquals(1, $resMariana['summary']['totalLeads']);
        $this->assertEquals(0, $resMariana['stages'][1]['count']); // no avanzó a Qualification
    }

    /**
     * Test 4: Exportación a CSV con formato UTF-8 BOM.
     */
    public function testPipelineConversionCsvExport(): void
    {
        $history = [
            $this->createEntityStub('h1', [
                'opportunityId' => 'opp-1',
                'stage' => 'Prospecting',
                'durationSeconds' => 3600,
                'deleted' => false,
            ]),
            $this->createEntityStub('h2', [
                'opportunityId' => 'opp-1',
                'stage' => 'Closed Won',
                'durationSeconds' => 0,
                'deleted' => false,
            ]),
        ];

        $opps = [
            $this->createEntityStub('opp-1', ['stage' => 'Closed Won', 'deleted' => false]),
        ];

        $em = $this->createEntityManagerMock($history, $opps);
        $service = new PipelineConversionService($em);

        $csv = $service->exportCsv(['leadSource' => 'WhatsApp']);

        // 1. BOM UTF-8
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // 2. Secciones del reporte
        $this->assertStringContainsString('REPORTE DE CONVERSIÓN DE PIPELINE Y TIEMPOS DE ETAPA', $csv);
        $this->assertStringContainsString('1. RESUMEN GLOBAL DEL EMBUDO', $csv);
        $this->assertStringContainsString('2. CONVERSIÓN Y DURACIÓN POR ETAPA', $csv);
        $this->assertStringContainsString('3. MOTIVOS DE DESCARTE (DROP-OFF REASONS)', $csv);

        // 3. Contenido de etapas
        $this->assertStringContainsString('Prospecting', $csv);
        $this->assertStringContainsString('Closed Won', $csv);
    }
}
