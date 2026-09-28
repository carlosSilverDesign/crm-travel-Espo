<?php

namespace Espo\Custom\Hooks\Opportunity;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClosedWonGuard
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
        // Verificar si 'stage' está cambiando o siendo establecido como 'Closed Won'
        if (!$entity->isAttributeChanged('stage') || $entity->get('stage') !== 'Closed Won') {
            return;
        }

        // Si las opciones incluyen skipHooks, permitir la persistencia interna
        if (!empty($options['skipHooks'])) {
            return;
        }

        $opportunityId = $entity->hasId() ? $entity->getId() : null;

        $confirmedItinerary = null;
        if ($opportunityId) {
            $confirmedItinerary = $this->getEntityManager()
                ->getRDBRepository('Itinerario')
                ->where([
                    'opportunityId' => $opportunityId,
                    'status' => 'Confirmado'
                ])
                ->findOne();
        }

        if (!$confirmedItinerary) {
            throw new BadRequest(
                "No es posible cerrar como ganada una oportunidad sin un itinerario confirmado que respalde la operación turística."
            );
        }
    }
}
