<?php

namespace Espo\Custom\Classes\Select\Itinerario\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

class CurrentInDestination implements Filter
{
    public const NAME = 'currentInDestination';

    public function apply(SelectBuilder $queryBuilder): void
    {
        $today = date('Y-m-d');

        $queryBuilder->where(
            Cond::and(
                Cond::equal(Cond::column('status'), 'Confirmado'),
                Cond::lessOrEqual(Cond::column('startDate'), $today),
                Cond::greaterOrEqual(Cond::column('endDate'), $today)
            )
        );
    }
}
