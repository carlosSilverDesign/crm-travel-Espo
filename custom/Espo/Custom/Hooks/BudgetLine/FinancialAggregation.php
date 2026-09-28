<?php

namespace Espo\Custom\Hooks\BudgetLine;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Acl\BudgetLine as BudgetLineAcl;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class FinancialAggregation
{
    protected EntityManager $entityManager;
    protected User $user;

    public function __construct(EntityManager $entityManager, User $user)
    {
        $this->entityManager = $entityManager;
        $this->user = $user;
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    protected function getUser(): User
    {
        return $this->user;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        $acl = new BudgetLineAcl($this->entityManager);

        if ($entity->isAttributeChanged('costPrice')) {
            if ($acl->checkReadOnlyField($entity, 'costPrice', $this->user)) {
                throw new BadRequest(
                    "El campo 'costPrice' es de solo lectura porque el itinerario asociado ya no está en estado 'Cotización'."
                );
            }
        }

        if ($entity->isAttributeChanged('marginRate')) {
            if ($acl->checkReadOnlyField($entity, 'marginRate', $this->user)) {
                throw new BadRequest(
                    "El campo 'marginRate' es de solo lectura porque el itinerario asociado ya no está en estado 'Cotización'."
                );
            }
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        $this->recalculateItinerarioTotals($entity);
    }

    public function afterRemove(Entity $entity, array $options = []): void
    {
        $this->recalculateItinerarioTotals($entity);
    }

    protected function recalculateItinerarioTotals(Entity $entity): void
    {
        $itinerarioId = $entity->get('itinerarioId');

        if (empty($itinerarioId)) {
            return;
        }

        $itinerario = $this->getEntityManager()->getEntity('Itinerario', $itinerarioId);

        if (!$itinerario) {
            return;
        }

        $budgetLines = $this->getEntityManager()->getRDBRepository('BudgetLine')
            ->where(['itinerarioId' => $itinerarioId])
            ->find();

        $totalCost = 0.0;
        $totalSelling = 0.0;

        foreach ($budgetLines as $line) {
            $totalCost += (float) ($line->get('costPrice') ?? 0.0);
            $totalSelling += (float) ($line->get('sellingPrice') ?? 0.0);
        }

        $grossProfit = $totalSelling - $totalCost;

        $itinerario->set([
            'totalCost' => round($totalCost, 2),
            'totalSelling' => round($totalSelling, 2),
            'grossProfit' => round($grossProfit, 2)
        ]);

        $this->getEntityManager()->saveEntity($itinerario);
    }
}
