<?php

namespace Espo\Custom\Hooks\Itinerario;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class OpportunitySync
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if (!empty($options['skipHooks'])) {
            return;
        }

        $opportunityId = $entity->get('opportunityId');
        if (empty($opportunityId)) {
            return;
        }

        $opportunity = $this->getEntityManager()->getEntity('Opportunity', $opportunityId);
        if (!$opportunity) {
            return;
        }

        // 1. Sincronización Financiera y de Fechas
        $updates = [
            'amount' => (float) ($entity->get('totalSelling') ?? 0.0),
            'projectedGrossProfit' => (float) ($entity->get('grossProfit') ?? 0.0),
            'travelStartDate' => $entity->get('startDate'),
            'travelEndDate' => $entity->get('endDate'),
        ];

        if ($entity->has('totalSellingCurrency') && $entity->get('totalSellingCurrency')) {
            $updates['amountCurrency'] = $entity->get('totalSellingCurrency');
            $updates['projectedGrossProfitCurrency'] = $entity->get('totalSellingCurrency');
        }

        // Asignar destino si la oportunidad no tiene uno establecido
        if (empty($opportunity->get('destination')) && !empty($entity->get('destination'))) {
            $updates['destination'] = $entity->get('destination');
        }

        // 2. Sincronización de Etapas Comerciales (Lógica previa TASK-006)
        if ($entity->isAttributeChanged('status')) {
            if ($entity->get('status') === 'Confirmado') {
                $updates['stage'] = 'Closed Won';
                $updates['probability'] = 100;
            } elseif ($entity->get('status') === 'Cancelado') {
                $activeItineraries = $this->getEntityManager()->getRDBRepository('Itinerario')
                    ->where([
                        'opportunityId' => $opportunityId,
                        'id!=' => $entity->getId(),
                        'status!=' => 'Cancelado'
                    ])
                    ->find();

                if (count($activeItineraries) === 0) {
                    $updates['stage'] = 'Closed Lost';
                    $updates['probability'] = 0;
                    if (empty($opportunity->get('lostReason'))) {
                        $updates['lostReason'] = 'Canceló Viaje';
                    }
                }
            }
        }

        // 3. Persistencia Segura evitando bucles de hooks
        $opportunity->set($updates);
        $this->getEntityManager()->saveEntity($opportunity, ['skipHooks' => true]);
    }
}
