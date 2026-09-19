<?php

namespace Espo\Custom\Hooks\Opportunity;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class PaymentPendingGate
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

    public function beforeSave(Entity $entity, array $options = []): void
    {
        // Verificar si 'stage' está cambiando o siendo establecido como 'PaymentPending'
        if (!$entity->isAttributeChanged('stage') || $entity->get('stage') !== 'PaymentPending') {
            return;
        }

        // Si las opciones incluyen skipHooks, permitir la persistencia interna
        if (!empty($options['skipHooks'])) {
            return;
        }

        $opportunityId = $entity->hasId() ? $entity->getId() : null;

        $itinerary = null;
        if ($opportunityId) {
            $itinerary = $this->getEntityManager()
                ->getRDBRepository('Itinerario')
                ->where([
                    'opportunityId' => $opportunityId,
                    'status' => 'Cotización'
                ])
                ->findOne();
        }

        if (!$itinerary) {
            throw new BadRequest(
                "No es posible pasar a Espera de Pago sin un Itinerario en cotización vinculado. Cree o asocie un itinerario primero."
            );
        }
    }
}
