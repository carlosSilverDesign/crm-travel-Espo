<?php

namespace Espo\Custom\Hooks\Payment;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Services\FinancialReconciliationService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class FinancialReconciliation
{
    private FinancialReconciliationService $service;

    public function __construct(
        private EntityManager $entityManager,
        ?FinancialReconciliationService $service = null
    ) {
        $this->service = $service ?? new FinancialReconciliationService($this->entityManager);
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        // 1. Generación automática de paymentReference ("PAY-" + 6 alfanuméricos en mayúscula)
        if (!$entity->get('paymentReference')) {
            $ref = 'PAY-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $entity->set('paymentReference', $ref);
        }

        if (!$entity->get('name')) {
            $entity->set('name', $entity->get('paymentReference'));
        }

        // 2. Regla de Inmutabilidad Contable (Heurística 5)
        // Si el pago ya estaba confirmado, se prohíbe cualquier mutación en campos financieros
        if ($entity->getFetched('status') === 'Confirmed') {
            if (
                $entity->isAttributeChanged('status') ||
                $entity->isAttributeChanged('amount') ||
                $entity->isAttributeChanged('currency') ||
                $entity->isAttributeChanged('opportunityId')
            ) {
                throw new Forbidden("No es posible modificar un pago que ya ha sido confirmado.");
            }
        }

        // 3. Validación de Rechazo (Heurística 9)
        // El estado 'Rejected' exige obligatoriamente registrar un motivo estructurado
        if ($entity->get('status') === 'Rejected') {
            $rejectionReason = $entity->get('rejectionReason');
            if (empty($rejectionReason)) {
                throw new BadRequest("Debe especificar un motivo de rechazo válido.");
            }
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        // Conciliación tras transición a 'Confirmed'
        if ($entity->get('status') === 'Confirmed' && $entity->getFetched('status') !== 'Confirmed') {
            $this->service->reconcileConfirmedPayment($entity);
        }
    }
}
