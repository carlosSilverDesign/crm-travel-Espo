<?php

namespace Espo\Custom\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class FinancialReconciliationService
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Ejecuta la conciliación contable y financiera de un cobro confirmado
     * bajo una transacción atómica ACID en MySQL.
     *
     * @throws Throwable
     */
    public function reconcileConfirmedPayment(Entity $payment): void
    {
        $transactionManager = $this->entityManager->getTransactionManager();
        $transactionManager->start();

        try {
            $opportunityId = $payment->get('opportunityId');
            if (!$opportunityId) {
                $transactionManager->commit();
                return;
            }

            /** @var Entity|null $opportunity */
            $opportunity = $this->entityManager->getEntity('Opportunity', $opportunityId);
            if (!$opportunity) {
                $transactionManager->commit();
                return;
            }

            // 1. Consultar todos los pagos confirmados vinculados a la Opportunity
            $confirmedPayments = $this->entityManager->getRDBRepository('Payment')
                ->where([
                    'opportunityId' => $opportunityId,
                    'status'        => 'Confirmed',
                    'deleted'       => false,
                ])
                ->find();

            $totalAmountPaid = 0.0;
            foreach ($confirmedPayments as $p) {
                $totalAmountPaid += (float) ($p->get('amount') ?? 0.0);
            }
            $totalAmountPaid = round($totalAmountPaid, 2);

            // 2. Calcular balance restante de la reserva/oportunidad
            $oppTotalAmount = (float) ($opportunity->get('amount') ?? 0.0);
            $pendingBalance = round($oppTotalAmount - $totalAmountPaid, 2);

            $opportunity->set('amountPaid', $totalAmountPaid);
            $opportunity->set('pendingBalance', max(0.0, $pendingBalance));

            // 3. Evaluar transiciones automáticas de estado comercial
            if ($pendingBalance <= 0.0) {
                $opportunity->set('financialStatus', 'PaidInFull');
                $opportunity->set('stage', 'Closed Won');
            } else {
                $opportunity->set('financialStatus', 'PartiallyPaid');
            }

            $this->entityManager->saveEntity($opportunity, ['skipHooks' => true]);

            // 4. Si el pago liquida una cuota específica de cronograma, marcarla como Pagada
            $paymentScheduleId = $payment->get('paymentScheduleId');
            if ($paymentScheduleId) {
                /** @var Entity|null $schedule */
                $schedule = $this->entityManager->getEntity('PaymentSchedule', $paymentScheduleId);
                if ($schedule) {
                    $schedule->set('status', 'Pagado');
                    $this->entityManager->saveEntity($schedule, ['skipHooks' => true]);
                }
            }

            $transactionManager->commit();
        } catch (Throwable $e) {
            $transactionManager->rollback();
            throw $e;
        }
    }
}
