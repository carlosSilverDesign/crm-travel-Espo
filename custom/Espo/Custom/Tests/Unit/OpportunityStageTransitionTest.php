<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\ORM\Entity;

/**
 * Suite de Pruebas Unitarias de Transición de Etapas y Auditoría de Pipeline (TASK-049).
 *
 * Valida:
 * 1. Registro automático de la etapa inicial al crear una Opportunity.
 * 2. Cierre determinista de la etapa anterior (exitedAt y durationSeconds) al transicionar de etapa.
 * 3. Secuencia temporal ordenada ante múltiples transiciones consecutivas.
 * 4. Atributo clientType con valor por defecto 'B2C_Direct' y soporte de 'B2B_Corporate'.
 */
class OpportunityStageTransitionTest extends TestCase
{
    private ?Container $container = null;
    private ?EntityManager $entityManager = null;
    private array $cleanupStack = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        restore_error_handler();
        restore_exception_handler();

        $this->container = $app->getContainer();
        $this->entityManager = $this->container->get('entityManager');

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
     * Valida que al crear una nueva Opportunity, el hook inserte automáticamente
     * el primer registro en OpportunityStageHistory en estado activo (exitedAt = null).
     */
    public function testInitialStageRecordedOnNewOpportunitySave(): void
    {
        $em = $this->entityManager;

        /** @var Entity $opp */
        $opp = $em->getNewEntity('Opportunity');
        $opp->set([
            'name' => 'Auditoría Pipeline: Test Inicial',
            'stage' => 'Prospecting',
            'leadSource' => 'WhatsApp',
            'amount' => 2500.00,
            'amountCurrency' => 'USD',
        ]);
        $em->saveEntity($opp);
        $this->trackForCleanup($opp);

        $oppId = $opp->getId();
        $this->assertNotEmpty($oppId);

        // Consultar el registro generado en OpportunityStageHistory
        $historyRecords = $em->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $oppId])
            ->find();

        $this->assertCount(1, $historyRecords, 'Debe existir exactamente 1 registro histórico para la oportunidad recién creada.');

        /** @var Entity $record */
        $record = $historyRecords[0];
        $this->trackForCleanup($record);

        $this->assertEquals('Prospecting', $record->get('stage'));
        $this->assertEquals($oppId, $record->get('opportunityId'));
        $this->assertEquals('WhatsApp', $record->get('leadSource'));
        $this->assertNotEmpty($record->get('enteredAt'));
        $this->assertNull($record->get('exitedAt'), 'La etapa activa debe tener exitedAt = null.');
        $this->assertNull($record->get('durationSeconds'), 'La etapa activa debe tener durationSeconds = null.');
    }

    /**
     * Valida que al cambiar de etapa, la etapa previa se cierre calculando durationSeconds
     * y se abra un nuevo registro para la etapa entrante.
     */
    public function testStageTransitionClosesPreviousStageWithDurationAndOpensNewStage(): void
    {
        $em = $this->entityManager;

        /** @var Entity $opp */
        $opp = $em->getNewEntity('Opportunity');
        $opp->set([
            'name' => 'Auditoría Pipeline: Test Transición',
            'stage' => 'Prospecting',
            'leadSource' => 'Web',
            'amount' => 3200.00,
            'amountCurrency' => 'USD',
        ]);
        $em->saveEntity($opp);
        $this->trackForCleanup($opp);

        $oppId = $opp->getId();

        // Simular que pasaron 120 segundos en Prospecting configurando enteredAt manual
        $firstRecord = $em->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $oppId, 'stage' => 'Prospecting'])
            ->findOne();
        $this->assertNotNull($firstRecord);
        $this->trackForCleanup($firstRecord);

        $simulatedPastTime = date('Y-m-d H:i:s', time() - 120);
        $firstRecord->set('enteredAt', $simulatedPastTime);
        $em->saveEntity($firstRecord, ['skipHooks' => true]);

        // Cambiar la etapa a 'Qualification'
        $opp->set('stage', 'Qualification');
        $em->saveEntity($opp);

        // Consultar el historial completo de la oportunidad
        $allRecords = $em->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $oppId])
            ->order('enteredAt', 'ASC')
            ->find();

        $this->assertCount(2, $allRecords, 'Deben existir 2 registros: el cerrado y el nuevo activo.');

        // 1. Validar el registro de Prospecting cerrado
        /** @var Entity $closedRecord */
        $closedRecord = $em->getEntity('OpportunityStageHistory', $firstRecord->getId());
        $this->assertEquals('Prospecting', $closedRecord->get('stage'));
        $this->assertNotNull($closedRecord->get('exitedAt'), 'La etapa previa debe tener exitedAt definido.');
        $this->assertGreaterThanOrEqual(120, (int) $closedRecord->get('durationSeconds'), 'durationSeconds debe registrar al menos 120 segundos.');

        // 2. Validar el nuevo registro de Qualification abierto
        /** @var Entity $activeRecord */
        $activeRecord = $allRecords[1];
        $this->trackForCleanup($activeRecord);

        $this->assertEquals('Qualification', $activeRecord->get('stage'));
        $this->assertNotNull($activeRecord->get('enteredAt'));
        $this->assertNull($activeRecord->get('exitedAt'), 'La nueva etapa activa debe tener exitedAt = null.');
        $this->assertNull($activeRecord->get('durationSeconds'), 'La nueva etapa activa debe tener durationSeconds = null.');
    }

    /**
     * Valida una secuencia de 3 etapas consecutivas (Prospecting -> Proposal -> Negotiation).
     */
    public function testMultipleStageTransitionsCreateOrderedAuditSequence(): void
    {
        $em = $this->entityManager;

        // 1. Crear en Prospecting
        /** @var Entity $opp */
        $opp = $em->getNewEntity('Opportunity');
        $opp->set([
            'name' => 'Auditoría Pipeline: Secuencia Múltiple',
            'stage' => 'Prospecting',
            'leadSource' => 'Referido',
            'amount' => 5000.00,
            'amountCurrency' => 'USD',
        ]);
        $em->saveEntity($opp);
        $this->trackForCleanup($opp);

        $oppId = $opp->getId();

        // Necesitamos asociar un itinerario cotizado para permitir pasar a PaymentPending o Negotiation según reglas previas
        /** @var Entity $itinerario */
        $itinerario = $em->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'EXP-PIPELINE-TEST',
            'status' => 'Cotización',
            'destination' => 'Cusco Clásico',
            'opportunityId' => $oppId,
        ]);
        $em->saveEntity($itinerario, ['skipHooks' => true]);
        $this->trackForCleanup($itinerario);

        // 2. Transición a Proposal
        $opp->set('stage', 'Proposal');
        $em->saveEntity($opp);

        // 3. Transición a Negotiation
        $opp->set('stage', 'Negotiation');
        $em->saveEntity($opp);

        $records = $em->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $oppId])
            ->order('enteredAt', 'ASC')
            ->find();

        $this->assertCount(3, $records);

        foreach ($records as $r) {
            $this->trackForCleanup($r);
        }

        // Registro 1: Prospecting cerrado
        $this->assertEquals('Prospecting', $records[0]->get('stage'));
        $this->assertNotNull($records[0]->get('exitedAt'));

        // Registro 2: Proposal cerrado
        $this->assertEquals('Proposal', $records[1]->get('stage'));
        $this->assertNotNull($records[1]->get('exitedAt'));

        // Registro 3: Negotiation activo
        $this->assertEquals('Negotiation', $records[2]->get('stage'));
        $this->assertNull($records[2]->get('exitedAt'));
    }

    /**
     * Valida que clientType por defecto sea 'B2C_Direct' y admita 'B2B_Corporate'.
     */
    public function testClientTypeDefaultsToB2CDirectAndAcceptsCorporate(): void
    {
        $em = $this->entityManager;

        // Caso B2C por defecto
        /** @var Entity $oppB2C */
        $oppB2C = $em->getNewEntity('Opportunity');
        $oppB2C->set([
            'name' => 'Reserva Turística B2C',
            'stage' => 'Prospecting',
            'amount' => 1800.00,
            'amountCurrency' => 'USD',
        ]);
        $em->saveEntity($oppB2C);
        $this->trackForCleanup($oppB2C);

        $this->assertEquals('B2C_Direct', $oppB2C->get('clientType'), 'clientType debe ser por defecto B2C_Direct.');

        // Caso B2B Corporativo explícito
        /** @var Entity $oppB2B */
        $oppB2B = $em->getNewEntity('Opportunity');
        $oppB2B->set([
            'name' => 'Convención Empresa Minera B2B',
            'stage' => 'Prospecting',
            'clientType' => 'B2B_Corporate',
            'amount' => 45000.00,
            'amountCurrency' => 'USD',
        ]);
        $em->saveEntity($oppB2B);
        $this->trackForCleanup($oppB2B);

        $reloadedB2B = $em->getEntity('Opportunity', $oppB2B->getId());
        $this->assertEquals('B2B_Corporate', $reloadedB2B->get('clientType'), 'clientType debe persistir B2B_Corporate.');
    }
}
