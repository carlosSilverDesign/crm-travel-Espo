<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Hooks\Feedback\ClassifyNpsScore;
use Espo\ORM\Entity;

/**
 * Suite de Pruebas Unitarias de Clasificación de NPS y Aislamiento Contable (TASK-048).
 *
 * Principios y Heurísticas Validadas:
 * - Peak-End Rule: Clasificación matemática exacta de satisfacción al final del viaje.
 * - Heurística 5 (Prevención de Errores):
 *     a) Rechazo defensivo de notas fuera de rango (menores a 1 o mayores a 10).
 *     b) Aislamiento contable estricto: Incident (costImpact) no muta saldos en Opportunity ni Payment.
 * - Heurística 9 (Ayudar a reconocer, diagnosticar y recuperarse de errores):
 *     Creación automática y atómica de entidad Task urgente asignada al equipo de calidad ante Detractores.
 */
class FeedbackClassificationTest extends TestCase
{
    private ?Container $container = null;
    private ?EntityManager $entityManager = null;
    private array $cleanupStack = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(Application::class)) {
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

    // =========================================================================
    // CASO DE PRUEBA 1: Clasificación Matemática de NPS (Peak-End Rule)
    // =========================================================================

    /**
     * Valida la clasificación matemática de satisfacción:
     * a) score = 10 -> Promoter, followUpRequired = false, followUpStatus = 'NotNeeded'
     * b) score = 8  -> Passive, followUpRequired = false, followUpStatus = 'NotNeeded'
     * c) score = 4  -> Detractor, followUpRequired = true, followUpStatus = 'Pending'
     */
    public function testNpsMathematicalClassificationPromoterPassiveDetractor(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        // Sub-caso A: Promotor (score = 10)
        $promoterAssigned = [];
        $promoterMock = $this->createStub(Entity::class);
        $promoterMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 10 : null);
        $promoterMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$promoterAssigned, $promoterMock) {
            $promoterAssigned[$k] = $v;
            return $promoterMock;
        });

        $hook->beforeSave($promoterMock);

        $this->assertEquals('Promoter', $promoterAssigned['sentiment'], 'Score 10 debe clasificarse como Promoter.');
        $this->assertFalse($promoterAssigned['followUpRequired'], 'Promoter no debe requerir seguimiento asistencial.');
        $this->assertEquals('NotNeeded', $promoterAssigned['followUpStatus'], 'followUpStatus debe ser NotNeeded.');
        $this->assertEquals(10, $promoterAssigned['npsScore']);

        // Sub-caso B: Pasivo (score = 8)
        $passiveAssigned = [];
        $passiveMock = $this->createStub(Entity::class);
        $passiveMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 8 : null);
        $passiveMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$passiveAssigned, $passiveMock) {
            $passiveAssigned[$k] = $v;
            return $passiveMock;
        });

        $hook->beforeSave($passiveMock);

        $this->assertEquals('Passive', $passiveAssigned['sentiment'], 'Score 8 debe clasificarse como Passive.');
        $this->assertFalse($passiveAssigned['followUpRequired'], 'Passive no debe requerir seguimiento.');
        $this->assertEquals('NotNeeded', $passiveAssigned['followUpStatus'], 'followUpStatus debe ser NotNeeded.');
        $this->assertEquals(8, $passiveAssigned['npsScore']);

        // Sub-caso C: Detractor (score = 4)
        $detractorAssigned = [];
        $detractorMock = $this->createStub(Entity::class);
        $detractorMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 4 : null);
        $detractorMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$detractorAssigned, $detractorMock) {
            $detractorAssigned[$k] = $v;
            return $detractorMock;
        });

        $hook->beforeSave($detractorMock);

        $this->assertEquals('Detractor', $detractorAssigned['sentiment'], 'Score 4 debe clasificarse como Detractor.');
        $this->assertTrue($detractorAssigned['followUpRequired'], 'Detractor debe marcar followUpRequired = true.');
        $this->assertEquals('Pending', $detractorAssigned['followUpStatus'], 'followUpStatus debe marcarse Pending.');
        $this->assertEquals(4, $detractorAssigned['npsScore']);
    }

    // =========================================================================
    // CASO DE PRUEBA 2: Validación Defensiva de Rango (Heurística 5)
    // =========================================================================

    /**
     * Valida que notas menores a 1 (ej: 0) arrojen excepción BadRequest en beforeSave.
     */
    public function testDefensiveRangeValidationRejectsScoreLowerThanOne(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El NPS Score debe ubicarse estrictamente entre 1 y 10.");

        $emStub = $this->createStub(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        $entityMock = $this->createStub(Entity::class);
        $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 0 : null);

        $hook->beforeSave($entityMock);
    }

    /**
     * Valida que notas mayores a 10 (ej: 11) arrojen excepción BadRequest en beforeSave.
     */
    public function testDefensiveRangeValidationRejectsScoreGreaterThanTen(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El NPS Score debe ubicarse estrictamente entre 1 y 10.");

        $emStub = $this->createStub(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        $entityMock = $this->createStub(Entity::class);
        $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 11 : null);

        $hook->beforeSave($entityMock);
    }

    // =========================================================================
    // CASO DE PRUEBA 3: Generación Automática de Tarea Urgente (Heurística 9)
    // =========================================================================

    /**
     * Valida que persistir un Feedback con calificación de detractor (score <= 6)
     * instancie y guarde automáticamente una entidad Task con prioridad 'Urgent',
     * vinculada como parentType = 'Feedback' y con el reclamo en la descripción.
     */
    public function testAutomaticUrgentTaskGenerationForDetractors(): void
    {
        $taskValues = [];
        $taskMock = $this->createStub(Entity::class);
        $taskMock->method('set')->willReturnCallback(function ($data, $val = null) use (&$taskValues, $taskMock) {
            if (is_array($data)) {
                $taskValues = array_merge($taskValues, $data);
            } else {
                $taskValues[$data] = $val;
            }
            return $taskMock;
        });

        $emMock = $this->createMock(EntityManager::class);
        $emMock->expects($this->once())
            ->method('getNewEntity')
            ->with('Task')
            ->willReturn($taskMock);

        $emMock->expects($this->once())
            ->method('saveEntity')
            ->with($taskMock);

        $hook = new ClassifyNpsScore($emMock);

        $feedbackMock = $this->createStub(Entity::class);
        $feedbackMock->method('getId')->willReturn('feed-qa-408');
        $feedbackMock->method('isNew')->willReturn(true);
        $feedbackMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'           => 'Encuesta Arequipa - Juan Pérez',
            'npsScore'       => 4,
            'sentiment'      => 'Detractor',
            'comments'       => 'El transfer nunca llegó al aeropuerto y perdimos la reserva.',
            'assignedUserId' => 'user-qa-01',
            default          => null,
        });

        $hook->afterSave($feedbackMock, ['isNew' => true]);

        // Aserciones estrictas del DoD
        $this->assertEquals('Atención Urgente Detractor: Encuesta Arequipa - Juan Pérez', $taskValues['name']);
        $this->assertEquals('Urgent', $taskValues['priority'], 'La prioridad debe ser estrictamente Urgent.');
        $this->assertEquals('Not Started', $taskValues['status'], 'El estado inicial debe ser Not Started.');
        $this->assertEquals('Feedback', $taskValues['parentType'], 'parentType debe ser Feedback.');
        $this->assertEquals('feed-qa-408', $taskValues['parentId'], 'parentId debe coincidir con el ID del Feedback.');
        $this->assertStringContainsString('Calificación: 4', $taskValues['description']);
        $this->assertStringContainsString('Comentarios: El transfer nunca llegó', $taskValues['description']);
        $this->assertEquals('user-qa-01', $taskValues['assignedUserId']);
    }

    // =========================================================================
    // CASO DE PRUEBA 4: Aislamiento Contable de Incident (Heurística 5)
    // =========================================================================

    /**
     * Valida que registrar una disrupción mediante Incident con costImpact = $150.00 USD
     * sobre una Opportunity cerrada con Payment confirmado de $1,200.00 USD mantenga
     * aislamiento contable estricto:
     * - Opportunity: amount, amountPaid y pendingBalance permanecen 100% inalterados.
     * - Payment: status permanece 'Confirmed' inalterado.
     */
    public function testAccountingIsolationOfIncidentFromOpportunityAndPayment(): void
    {
        $this->assertNotNull($this->entityManager, 'EntityManager requerido para prueba de aislamiento contable.');

        // 1. Crear Opportunity inicial
        /** @var Entity $opportunity */
        $opportunity = $this->entityManager->getNewEntity('Opportunity');
        $opportunity->set([
            'name' => 'QA Opportunity Aislamiento Contable',
            'stage' => 'Proposal',
            'amount' => 1200.00,
            'amountCurrency' => 'USD',
            'amountPaid' => 1200.00,
            'pendingBalance' => 0.00,
            'financialStatus' => 'PaidInFull',
        ]);
        $this->entityManager->saveEntity($opportunity, ['skipHooks' => true]);
        $this->trackForCleanup($opportunity);

        // Crear Itinerario confirmado que respalda la operación turística (ClosedWonGuard)
        /** @var Entity $itinerario */
        $itinerario = $this->entityManager->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'EXP-QA-COLCA-2026',
            'status' => 'Confirmado',
            'destination' => 'Arequipa & Colca',
            'totalSelling' => 1200.00,
            'totalSellingCurrency' => 'USD',
            'opportunityId' => $opportunity->getId(),
        ]);
        $this->entityManager->saveEntity($itinerario, ['skipHooks' => true]);
        $this->trackForCleanup($itinerario);

        // Marcar Oportunidad en Closed Won y sincronizar acumuladores
        $opportunity->set([
            'stage' => 'Closed Won',
            'amount' => 1200.00,
            'amountPaid' => 1200.00,
            'pendingBalance' => 0.00,
            'financialStatus' => 'PaidInFull',
        ]);
        $this->entityManager->saveEntity($opportunity);

        // 2. Crear Payment confirmado de $1,200 USD
        /** @var Entity $payment */
        $payment = $this->entityManager->getNewEntity('Payment');
        $payment->set([
            'name' => 'PAY-QA-1200',
            'opportunityId' => $opportunity->getId(),
            'amount' => 1200.00,
            'currency' => 'USD',
            'status' => 'Confirmed',
            'method' => 'bank_transfer',
        ]);
        $this->entityManager->saveEntity($payment, ['skipHooks' => true]);
        $this->trackForCleanup($payment);

        $oppId = $opportunity->getId();
        $paymentId = $payment->getId();

        // 3. Registrar un Incident asociado con costImpact = $150.00 USD y severidad 'Critical'
        /** @var Entity $incident */
        $incident = $this->entityManager->getNewEntity('Incident');
        $incident->set([
            'name' => 'INC-QA: Falla Mecánica en Minivan Traslado',
            'opportunityId' => $oppId,
            'severity' => 'Critical',
            'category' => 'SupplierFailure',
            'status' => 'Reported',
            'costImpact' => 150.00,
            'costImpactCurrency' => 'USD',
            'resolutionPlan' => 'Se contrató taxi privado de contingencia cubierto por la agencia.',
        ]);
        $this->entityManager->saveEntity($incident);
        $this->trackForCleanup($incident);

        $this->assertNotEmpty($incident->getId(), 'El Incident debe haberse persistido en BD.');

        // 4. Recargar Opportunity y Payment desde base de datos
        $reloadedOpportunity = $this->entityManager->getEntity('Opportunity', $oppId);
        $reloadedPayment = $this->entityManager->getEntity('Payment', $paymentId);

        // 5. ASERCIONES ESTRICTAS DE AISLAMIENTO CONTABLE (Heurística 5)
        $this->assertEquals(
            1200.00,
            (float) $reloadedOpportunity->get('amount'),
            'El monto comercial (amount) de Opportunity no debe mutar por incidentes.'
        );
        $this->assertEquals(
            1200.00,
            (float) $reloadedOpportunity->get('amountPaid'),
            'El monto cobrado (amountPaid) de Opportunity no debe mutar por incidentes.'
        );
        $this->assertEquals(
            0.00,
            (float) $reloadedOpportunity->get('pendingBalance'),
            'El saldo pendiente (pendingBalance) de Opportunity no debe mutar por incidentes.'
        );
        $this->assertEquals(
            'Closed Won',
            $reloadedOpportunity->get('stage'),
            'La etapa (stage) de Opportunity debe seguir en Closed Won.'
        );
        $this->assertEquals(
            'PaidInFull',
            $reloadedOpportunity->get('financialStatus'),
            'El estado financiero (financialStatus) de Opportunity debe mantenerse PaidInFull.'
        );

        $this->assertEquals(
            'Confirmed',
            $reloadedPayment->get('status'),
            'El estado del cobro bancario (Payment.status) no debe mutar por incidentes.'
        );
        $this->assertEquals(
            1200.00,
            (float) $reloadedPayment->get('amount'),
            'El monto del pago bancario (Payment.amount) debe mantenerse en 1,200.00 USD.'
        );

        $this->assertEquals(
            150.00,
            (float) $incident->get('costImpact'),
            'El costo de contingencia permanece auditado exclusivamente dentro de Incident.costImpact.'
        );
    }
}
