<?php

namespace Espo\Custom\Acl;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BudgetLine
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

    public function checkReadOnlyField(Entity $entity, string $field, User $user): bool
    {
        // El administrador siempre tiene permisos de edición y corrección
        if ($user->isAdmin()) {
            return false;
        }

        // 'sellingPrice' y 'grossProfit' son campos calculados de solo lectura
        if ($field === 'sellingPrice' || $field === 'grossProfit') {
            return true;
        }

        // 'costPrice' y 'marginRate' pasan a solo lectura si el itinerario no está en 'Cotización'
        if ($field === 'costPrice' || $field === 'marginRate') {
            $itinerarioId = $entity->get('itinerarioId');

            if (!$itinerarioId) {
                return false;
            }

            $itinerario = $this->getEntityManager()->getEntity('Itinerario', $itinerarioId);

            if ($itinerario && $itinerario->get('status') !== 'Cotización') {
                return true;
            }
        }

        return false;
    }
}
