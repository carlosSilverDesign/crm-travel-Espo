<?php

namespace Espo\Custom\Hooks\Opportunity;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class LostReasonGuard
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
        // Verificar si 'stage' está mutando o estableciéndose en 'Closed Lost'
        if (!$entity->isAttributeChanged('stage') || $entity->get('stage') !== 'Closed Lost') {
            return;
        }

        // Si las opciones incluyen skipHooks, permitir la persistencia interna
        if (!empty($options['skipHooks'])) {
            return;
        }

        $lostReason = trim((string) ($entity->get('lostReason') ?? ''));

        // Si lostReason está vacío, abortar la persistencia
        if ($lostReason === '') {
            throw new BadRequest(
                "Debe especificar el motivo de pérdida (lostReason) para cerrar la oportunidad como descartada o perdida."
            );
        }

        // Asegurar que la probabilidad sea 0% al cerrar como perdida
        $entity->set('probability', 0);
    }
}
