<?php

namespace Espo\Custom\Hooks\Passenger;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class DocumentValidation
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $itinerarioId = $entity->get('itinerarioId');

        if (!$itinerarioId) {
            return;
        }

        $itinerario = $this->getEntityManager()->getEntity('Itinerario', $itinerarioId);

        if (!$itinerario) {
            return;
        }

        $endDate = $itinerario->get('endDate');
        $docExp = $entity->get('documentExpiration');

        if (!empty($endDate) && !empty($docExp)) {
            if (strtotime($docExp) <= strtotime($endDate)) {
                throw new BadRequest(
                    "El documento del pasajero expira antes del término del itinerario ({$endDate}). Ingrese un documento vigente."
                );
            }
        }
    }
}
