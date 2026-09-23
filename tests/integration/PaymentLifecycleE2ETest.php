<?php

namespace Espo\Custom\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Custom\Controllers\PaymentController;
use Espo\Core\Exceptions\Forbidden;

/**
 * Suite de Integración E2E del Ciclo de Vida y Transaccionalidad ACID (TASK-042).
 *
 * Valida el flujo completo de cobranza bancaria para agencias de viajes:
 * 1. Oportunidad inicial de $1,000 USD y cronograma de 2 cuotas de $500 USD cada una.
 * 2. Emisión de Cobro 1 y subida de voucher por cliente:
 *    - Pasa estrictamente a 'UnderReview'.
 *    - Comprobante vinculado como Attachment.
 *    - Oportunidad mantiene saldo en $1,000 USD y etapa 'Proposal' (Fail-Safe / Nielsen 1).
 * 3. Confirmación humana de Cobro 1 vía PaymentController:
 *    - Mutación atómica ACID a 'Confirmed'.
 *    - Cuota 1 pasa a 'Pagado'.
 *    - Oportunidad actualiza amountPaid = $500 USD, pendingBalance = $500 USD, financialStatus = 'PartiallyPaid'.
 *    - Etapa permanece en 'Proposal' (no se cierra anticipadamente).
 * 4. Emisión y confirmación de Cobro 2:
 *    - Sumatoria acumulada alcanza $1,000 USD.
 *    - pendingBalance = 0.00 USD, financialStatus = 'PaidInFull'.
 *    - Transición automática de Opportunity.stage a 'Closed Won'.
 * 5. Blindaje de inmutabilidad contable ante intentos de mutación posterior.
 */
class PaymentLifecycleE2ETest extends TestCase
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
        $this->container->set('user', $systemUser);
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
                } catch (\Throwable $e) {
                    // Silenciar fallos de limpieza para no enmascarar aserciones
                }
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
     * Test principal: Flujo completo de pagos parciales, conciliación manual y cierre comercial.
     */
    public function testPaymentLifecycleAndAcidReconciliation(): void
    {
        $systemUser = $this->entityManager->getEntity('User', 'system');

        // =========================================================================
        // PASO A: Crear Opportunity por $1,000 USD y 2 cuotas de $500 USD en PaymentSchedule
        // =========================================================================
        /** @var Entity $opportunity */
        $opportunity = $this->entityManager->getNewEntity('Opportunity');
        $opportunity->set([
            'name' => 'E2E Reserva Aventura Safari 2026',
            'stage' => 'Proposal',
            'amount' => 1000.00,
            'amountCurrency' => 'USD',
            'amountPaid' => 0.00,
            'pendingBalance' => 1000.00,
            'financialStatus' => 'Pending',
        ]);
        $this->entityManager->saveEntity($opportunity);
        $this->trackForCleanup($opportunity);

        /** @var Entity $itinerario */
        $itinerario = $this->entityManager->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'EXP-E2E-SAFARI',
            'status' => 'Cotización',
            'destination' => 'Serengeti, Tanzania',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-10',
            'totalSelling' => 1000.00,
            'totalSellingCurrency' => 'USD',
            'opportunityId' => $opportunity->getId(),
        ]);
        $this->entityManager->saveEntity($itinerario);
        $this->trackForCleanup($itinerario);

        /** @var Entity $cuota1 */
        $cuota1 = $this->entityManager->getNewEntity('PaymentSchedule');
        $cuota1->set([
            'name' => 'Cuota 1 - Reserva (50%)',
            'dueDate' => '2026-10-15',
            'amount' => 500.00,
            'status' => 'Pendiente',
            'itinerarioId' => $itinerario->getId(),
        ]);
        $this->entityManager->saveEntity($cuota1);
        $this->trackForCleanup($cuota1);

        /** @var Entity $cuota2 */
        $cuota2 = $this->entityManager->getNewEntity('PaymentSchedule');
        $cuota2->set([
            'name' => 'Cuota 2 - Saldo Final (50%)',
            'dueDate' => '2026-10-30',
            'amount' => 500.00,
            'status' => 'Pendiente',
            'itinerarioId' => $itinerario->getId(),
        ]);
        $this->entityManager->saveEntity($cuota2);
        $this->trackForCleanup($cuota2);

        $this->assertEquals(1000.00, (float) $opportunity->get('amount'));
        $this->assertEquals('Proposal', $opportunity->get('stage'));

        // =========================================================================
        // PASO B: Emitir Cobro 1 ($500.00 USD) en estado 'Pending'
        // =========================================================================
        /** @var Entity $payment1 */
        $payment1 = $this->entityManager->getNewEntity('Payment');
        $payment1->set([
            'opportunityId' => $opportunity->getId(),
            'paymentScheduleId' => $cuota1->getId(),
            'amount' => 500.00,
            'currency' => 'USD',
            'status' => 'Pending',
            'method' => 'bank_transfer',
        ]);
        $this->entityManager->saveEntity($payment1);
        $this->trackForCleanup($payment1);

        $this->assertNotEmpty($payment1->get('paymentReference'), 'El hook debe auto-asignar paymentReference.');
        $this->assertStringStartsWith('PAY-', $payment1->get('paymentReference'));
        $this->assertEquals('Pending', $payment1->get('status'));

        // =========================================================================
        // PASO C: Simular reporte público del cliente enviando voucher JPG
        // =========================================================================
        /** @var Entity $voucherAttachment */
        $voucherAttachment = $this->entityManager->getNewEntity('Attachment');
        $voucherAttachment->set([
            'name' => 'voucher_bcp_cuota1.jpg',
            'type' => 'image/jpeg',
            'size' => 1048576, // 1 MB
            'role' => 'Attachment',
            'relatedType' => 'Payment',
            'relatedId' => $payment1->getId(),
            'field' => 'proofAttachment',
        ]);
        $this->entityManager->saveEntity($voucherAttachment);
        $this->trackForCleanup($voucherAttachment);

        // Cliente declara datos y adjunta voucher
        $payment1->set([
            'status' => 'UnderReview',
            'clientDeclaredAmount' => 500.00,
            'clientDeclaredDate' => date('Y-m-d'),
            'clientOperationNumber' => 'OP-BCP-992144',
            'proofAttachmentId' => $voucherAttachment->getId(),
        ]);
        $this->entityManager->saveEntity($payment1);

        // Aserciones de Seguridad y Fail-Safe (Heurística 1 Nielsen)
        $payment1Reloaded = $this->entityManager->getEntity('Payment', $payment1->getId());
        $this->assertEquals('UnderReview', $payment1Reloaded->get('status'), 'El pago debe pasar exclusivamente a UnderReview.');
        $this->assertEquals($voucherAttachment->getId(), $payment1Reloaded->get('proofAttachmentId'));

        $oppAfterVoucher = $this->entityManager->getEntity('Opportunity', $opportunity->getId());
        $this->assertEquals(1000.00, (float) $oppAfterVoucher->get('pendingBalance'), 'pendingBalance debe seguir en 1000 USD antes de validar.');
        $this->assertEquals(0.00, (float) $oppAfterVoucher->get('amountPaid'), 'amountPaid debe seguir en 0 USD.');
        $this->assertEquals('Proposal', $oppAfterVoucher->get('stage'), 'La etapa debe mantenerse en Proposal sin cierre prematuro.');

        $cuota1Reloaded = $this->entityManager->getEntity('PaymentSchedule', $cuota1->getId());
        $this->assertEquals('Pendiente', $cuota1Reloaded->get('status'), 'Cuota 1 debe seguir Pendiente hasta confirmación humana.');

        // =========================================================================
        // PASO D: Confirmación humana de Cobro 1 vía PaymentController (Transacción ACID)
        // =========================================================================
        $controller = new PaymentController(
            $this->container,
            $this->entityManager,
            $this->container->get('config'),
            $systemUser
        );

        $confirmResult1 = $controller->actionConfirm([], ['id' => $payment1->getId()]);
        $this->assertEquals('Confirmed', $confirmResult1['status']);
        $this->assertEquals($systemUser->getId(), $confirmResult1['verifiedById']);

        // Verificar estado de entidades tras confirmación de Cuota 1
        $payment1Confirmed = $this->entityManager->getEntity('Payment', $payment1->getId());
        $this->assertEquals('Confirmed', $payment1Confirmed->get('status'));
        $this->assertEquals($systemUser->getId(), $payment1Confirmed->get('verifiedById'));
        $this->assertNotEmpty($payment1Confirmed->get('verifiedAt'));

        $cuota1Confirmed = $this->entityManager->getEntity('PaymentSchedule', $cuota1->getId());
        $this->assertEquals('Pagado', $cuota1Confirmed->get('status'), 'Cuota 1 debe mutar a Pagado tras confirmación atómica.');

        $oppAfterConfirm1 = $this->entityManager->getEntity('Opportunity', $opportunity->getId());
        $this->assertEquals(500.00, (float) $oppAfterConfirm1->get('amountPaid'), 'amountPaid debe sumar 500.00 USD.');
        $this->assertEquals(500.00, (float) $oppAfterConfirm1->get('pendingBalance'), 'pendingBalance debe reducirse a 500.00 USD.');
        $this->assertEquals('PartiallyPaid', $oppAfterConfirm1->get('financialStatus'), 'financialStatus debe ser PartiallyPaid.');
        $this->assertEquals('Proposal', $oppAfterConfirm1->get('stage'), 'Opportunity debe continuar en negociación (Proposal).');

        // =========================================================================
        // PASO E: Emitir y Confirmar Cobro 2 ($500.00 USD) - Liquidación Total y Cierre
        // =========================================================================
        /** @var Entity $payment2 */
        $payment2 = $this->entityManager->getNewEntity('Payment');
        $payment2->set([
            'opportunityId' => $opportunity->getId(),
            'paymentScheduleId' => $cuota2->getId(),
            'amount' => 500.00,
            'currency' => 'USD',
            'status' => 'UnderReview',
            'clientDeclaredAmount' => 500.00,
            'clientDeclaredDate' => date('Y-m-d'),
            'clientOperationNumber' => 'OP-BCP-992199',
            'method' => 'bank_transfer',
        ]);
        $this->entityManager->saveEntity($payment2);
        $this->trackForCleanup($payment2);

        $confirmResult2 = $controller->actionConfirm([], ['id' => $payment2->getId()]);
        $this->assertEquals('Confirmed', $confirmResult2['status']);

        // Aserciones finales de liquidación total y cierre comercial
        $cuota2Confirmed = $this->entityManager->getEntity('PaymentSchedule', $cuota2->getId());
        $this->assertEquals('Pagado', $cuota2Confirmed->get('status'), 'Cuota 2 debe mutar a Pagado.');

        $oppFinal = $this->entityManager->getEntity('Opportunity', $opportunity->getId());
        $this->assertEquals(1000.00, (float) $oppFinal->get('amountPaid'), 'amountPaid debe alcanzar exactamente 1000.00 USD.');
        $this->assertEquals(0.00, (float) $oppFinal->get('pendingBalance'), 'pendingBalance debe liquidarse en 0.00 USD.');
        $this->assertEquals('PaidInFull', $oppFinal->get('financialStatus'), 'financialStatus debe mutar a PaidInFull.');
        $this->assertEquals('Closed Won', $oppFinal->get('stage'), 'Opportunity debe transicionar automáticamente a Closed Won al liquidar 100%.');

        // =========================================================================
        // PASO F: Inmutabilidad Contable (Heurística 5 Nielsen)
        // =========================================================================
        // Intentar alterar el monto del cobro confirmado debe ser bloqueado con Forbidden
        $payment1Confirmed->set('amount', 999.00);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('No es posible modificar un pago que ya ha sido confirmado');

        $this->entityManager->saveEntity($payment1Confirmed);
    }
}
