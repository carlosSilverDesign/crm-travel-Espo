<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\SelectManagers\Itinerario as ItinerarioSelectManager;
use Espo\Custom\Classes\Select\Itinerario\BoolFilters\CurrentInDestination as BoolCurrentInDestination;
use Espo\Custom\Classes\Select\Itinerario\PrimaryFilters\CurrentInDestination as PrimaryCurrentInDestination;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Where\AndGroup;

class ItinerarioSelectManagerTest extends TestCase
{
    /**
     * Valida que el SelectManager clásico inyecte las cláusulas SQL esperadas
     * (status = 'Confirmado', startDate <= CURDATE(), endDate >= CURDATE()).
     */
    public function testSelectManagerBoolFilterCurrentInDestination(): void
    {
        $selectManager = new ItinerarioSelectManager();
        $queryResult = ['whereClause' => []];

        $selectManager->boolFilterCurrentInDestination($queryResult);

        $this->assertNotEmpty($queryResult['whereClause']);
        $clause = $queryResult['whereClause'][0];

        $this->assertEquals('Confirmado', $clause['status']);
        $this->assertArrayHasKey('startDate<=', $clause);
        $this->assertArrayHasKey('endDate>=', $clause);
        $this->assertEquals(date('Y-m-d'), $clause['startDate<=']);
        $this->assertEquals(date('Y-m-d'), $clause['endDate>=']);
    }

    /**
     * Valida que el BoolFilter moderno para SelectBuilder aplique las condiciones
     * con la fecha actual del sistema.
     */
    public function testModernBoolFilterCurrentInDestinationAppliesConditions(): void
    {
        $filter = new BoolCurrentInDestination();
        $queryBuilderMock = $this->createMock(SelectBuilder::class);
        $orGroupBuilder = new OrGroupBuilder();

        $filter->apply($queryBuilderMock, $orGroupBuilder);

        $builtGroup = $orGroupBuilder->build();
        $this->assertNotNull($builtGroup);
        $raw = json_encode($builtGroup->getRawValue());

        $this->assertStringContainsString('Confirmado', $raw);
        $this->assertStringContainsString(date('Y-m-d'), $raw);
    }

    /**
     * Valida que el PrimaryFilter moderno aplique las condiciones sobre el SelectBuilder.
     */
    public function testModernPrimaryFilterCurrentInDestinationAppliesConditions(): void
    {
        $filter = new PrimaryCurrentInDestination();
        $queryBuilderMock = $this->createMock(SelectBuilder::class);

        $queryBuilderMock->expects($this->once())
            ->method('where')
            ->with($this->callback(function ($condition) {
                if ($condition instanceof AndGroup) {
                    $raw = json_encode($condition->getRawValue());
                    return str_contains($raw, 'Confirmado') && str_contains($raw, date('Y-m-d'));
                }
                return false;
            }));

        $filter->apply($queryBuilderMock);
    }

    /**
     * Valida que clientDefs de Itinerario contenga el filtro currentInDestination
     * y la estructura de tarjeta operativa con todos los campos esenciales (Heurística 6 y Ley de Miller).
     */
    public function testItinerarioClientDefsMetadata(): void
    {
        $path = dirname(__DIR__, 2) . '/Resources/metadata/clientDefs/Itinerario.json';
        $this->assertFileExists($path);

        $clientDefs = json_decode(file_get_contents($path), true);
        $this->assertIsArray($clientDefs);

        // 1. Filtro booleano disponible en barra rápida
        $this->assertArrayHasKey('boolFilterList', $clientDefs);
        $this->assertContains('currentInDestination', $clientDefs['boolFilterList']);

        // 2. Tablero operativo con los 6 datos esenciales requeridos
        $this->assertArrayHasKey('operationalBoard', $clientDefs);
        $this->assertTrue($clientDefs['operationalBoard']['enabled']);

        $expectedCardFields = [
            'leadPassengerName',
            'paxCount',
            'todaysActiveService',
            'assignedSupplierName',
            'emergencyPhone',
            'chatwootChatUrl'
        ];
        $this->assertEquals($expectedCardFields, $clientDefs['operationalBoard']['cardFields']);
    }

    /**
     * Valida que selectDefs registre los mapeos de clase para resolver el filtro en el motor ORM.
     */
    public function testItinerarioSelectDefsMetadata(): void
    {
        $path = dirname(__DIR__, 2) . '/Resources/metadata/selectDefs/Itinerario.json';
        $this->assertFileExists($path);

        $selectDefs = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('boolFilterClassNameMap', $selectDefs);
        $this->assertEquals(
            'Espo\Custom\Classes\Select\Itinerario\BoolFilters\CurrentInDestination',
            $selectDefs['boolFilterClassNameMap']['currentInDestination']
        );

        $this->assertArrayHasKey('primaryFilterClassNameMap', $selectDefs);
        $this->assertEquals(
            'Espo\Custom\Classes\Select\Itinerario\PrimaryFilters\CurrentInDestination',
            $selectDefs['primaryFilterClassNameMap']['currentInDestination']
        );
    }

    /**
     * Valida que los campos temporales y de estado de Itinerario cuenten con índice
     * para asegurar el Umbral de Doherty (<400ms en UI).
     */
    public function testItinerarioDatabaseIndexesForDohertyThreshold(): void
    {
        $path = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Itinerario.json';
        $entityDefs = json_decode(file_get_contents($path), true);

        $fields = $entityDefs['fields'];
        $this->assertTrue($fields['status']['index'], 'El campo status debe estar indexado.');
        $this->assertTrue($fields['startDate']['index'], 'El campo startDate debe estar indexado.');
        $this->assertTrue($fields['endDate']['index'], 'El campo endDate debe estar indexado.');
    }

    /**
     * Valida que el script frontend de Itinerario esté registrado en metadata/app/client.json.
     */
    public function testItinerarioClientScriptRegistered(): void
    {
        $path = dirname(__DIR__, 2) . '/Resources/metadata/app/client.json';
        $client = json_decode(file_get_contents($path), true);

        $this->assertContains('client/custom/modules/travel/itinerario.js', $client['scriptList']);
    }
}
