<?php

namespace Espo\Custom\Acl;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Opportunity
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
        // El administrador siempre conserva permisos de edición total
        if ($user->isAdmin()) {
            return false;
        }

        // 'projectedGrossProfit' es siempre de solo lectura
        if ($field === 'projectedGrossProfit') {
            return true;
        }

        // 'amount' es de solo lectura si ya existe al menos un itinerario vinculado
        if ($field === 'amount') {
            $opportunityId = $entity->getId();
            if (!$opportunityId) {
                return false; // Al crear un registro nuevo sin ID persistido aún, permitir edición
            }

            // Verificar si existen itinerarios vinculados
            $hasItineraries = (bool) $this->getEntityManager()
                ->getRDBRepository('Itinerario')
                ->where(['opportunityId' => $opportunityId])
                ->findOne();

            if ($hasItineraries) {
                return true; // Bloqueado, la autoridad financiera pertenece a los itinerarios
            }

            return false; // Sin itinerarios, asesor puede ingresar presupuesto estimado
        }

        // Para cualquier otro campo
        return false;
    }
}
