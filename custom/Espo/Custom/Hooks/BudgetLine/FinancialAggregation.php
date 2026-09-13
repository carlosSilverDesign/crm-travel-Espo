<?php

namespace Espo\Custom\Hooks\BudgetLine;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class FinancialAggregation
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

        $this->getEntityManager()->saveEntity($itinerario, ['skipHooks' => true]);
    }
}
