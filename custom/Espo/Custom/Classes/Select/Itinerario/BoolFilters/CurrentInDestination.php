<?php

namespace Espo\Custom\Classes\Select\Itinerario\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

class CurrentInDestination implements Filter
{
    public const NAME = 'currentInDestination';

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $today = date('Y-m-d');

        $orGroupBuilder->add(
            Cond::and(
                Cond::equal(Cond::column('status'), 'Confirmado'),
                Cond::lessOrEqual(Cond::column('startDate'), $today),
                Cond::greaterOrEqual(Cond::column('endDate'), $today)
            )
        );
    }
}
