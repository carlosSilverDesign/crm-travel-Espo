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
        // Comprobar si el atributo 'status' fue modificado
        if (!$entity->isAttributeChanged('status')) {
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

        // CASO 1: Itinerario pasa a 'Confirmado'
        if ($entity->get('status') === 'Confirmado') {
            $updateData = [
                'stage' => 'Closed Won',
                'amount' => (float) ($entity->get('totalSelling') ?? 0.0)
            ];

            if ($entity->has('totalSellingCurrency') && $entity->get('totalSellingCurrency')) {
                $updateData['amountCurrency'] = $entity->get('totalSellingCurrency');
            }

            $opportunity->set($updateData);
            $this->getEntityManager()->saveEntity($opportunity, ['skipHooks' => true]);

            return;
        }

        // CASO 2: Itinerario pasa a 'Cancelado'
        if ($entity->get('status') === 'Cancelado') {
            // Verificar si existen otros itinerarios activos vinculados a esta oportunidad
            $activeItineraries = $this->getEntityManager()->getRDBRepository('Itinerario')
                ->where([
                    'opportunityId' => $opportunityId,
                    'id!=' => $entity->getId(),
                    'status!=' => 'Cancelado'
                ])
                ->find();

            // Si no quedan itinerarios activos, marcar la oportunidad como perdida
            if (count($activeItineraries) === 0) {
                $opportunity->set('stage', 'Closed Lost');
                $this->getEntityManager()->saveEntity($opportunity, ['skipHooks' => true]);
            }
        }
    }
}
