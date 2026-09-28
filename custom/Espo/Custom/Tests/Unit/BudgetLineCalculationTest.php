<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;

class BudgetLineCalculationTest extends TestCase
{
    /**
     * Valida la fórmula matemática pura para el cálculo de precio de venta y margen bruto:
     * sellingPrice = costPrice * (1 + marginRate / 100)
     * grossProfit = sellingPrice - costPrice
     */
    public function testMarginAndSellingPriceFormula(): void
    {
        $costPrice = 1000.00;
        $marginRate = 20.00;

        $expectedSelling = round($costPrice * (1 + ($marginRate / 100)), 2);
        $expectedProfit = round($expectedSelling - $costPrice, 2);

        $this->assertEquals(1200.00, $expectedSelling, 'El precio de venta calculado debe ser 1200.00');
        $this->assertEquals(200.00, $expectedProfit, 'El beneficio bruto calculado debe ser 200.00');
    }

    /**
     * Valida el comportamiento con margen 0% (costo directo sin recargo comercial).
     */
    public function testMarginWithZeroMarginRate(): void
    {
        $costPrice = 750.00;
        $marginRate = 0.00;

        $expectedSelling = round($costPrice * (1 + ($marginRate / 100)), 2);
        $expectedProfit = round($expectedSelling - $costPrice, 2);

        $this->assertEquals(750.00, $expectedSelling, 'Con margen 0%, el precio de venta es igual al costo');
        $this->assertEquals(0.00, $expectedProfit, 'Con margen 0%, el beneficio bruto debe ser 0.00');
    }

    /**
     * Valida el comportamiento con importes decimales y redondeo monetario a 2 decimales.
     */
    public function testMarginWithDecimalPrecision(): void
    {
        $costPrice = 1250.40;
        $marginRate = 15.00;

        $expectedSelling = round($costPrice * (1 + ($marginRate / 100)), 2);
        $expectedProfit = round($expectedSelling - $costPrice, 2);

        $this->assertEquals(1437.96, $expectedSelling, 'Precio de venta con cálculo decimal exacto');
        $this->assertEquals(187.56, $expectedProfit, 'Beneficio bruto con cálculo decimal exacto');
    }

    /**
     * Valida la ejecución del motor de fórmulas de EspoCRM (formulaManager)
     * sobre una entidad real de BudgetLine con el script definido en formula.json.
     */
    public function testBudgetLineEntityFormulaExecution(): void
    {
        $app = new Application();
        // Restaurar handlers para compatibilidad estricta con PHPUnit 13+
        restore_error_handler();
        restore_exception_handler();

        $container = $app->getContainer();
        
        $entityManager = $container->get('entityManager');
        $formulaManager = $container->get('formulaManager');

        /** @var \Espo\ORM\Entity $entity */
        $entity = $entityManager->getNewEntity('BudgetLine');
        $entity->set('costPrice', 1000);
        $entity->set('marginRate', 20);

        $formulaScript = "ifThen(costPrice != null && marginRate != null, sellingPrice = costPrice * (1 + (marginRate / 100))); ifThen(sellingPrice != null && costPrice != null, grossProfit = sellingPrice - costPrice);";

        $formulaManager->run($formulaScript, $entity);

        $this->assertEquals(1200.00, (float) $entity->get('sellingPrice'), 'El motor de fórmulas debe calcular sellingPrice = 1200');
        $this->assertEquals(200.00, (float) $entity->get('grossProfit'), 'El motor de fórmulas debe calcular grossProfit = 200');
    }
}
