<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Payment\FinancialReconciliation as FinancialReconciliationHook;
use Espo\Custom\Services\FinancialReconciliationService;
use Espo\Custom\Controllers\PublicPaymentController;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;

/**
 * Suite de Pruebas Unitarias para TASK-042:
 * 1. Filtrado estricto de cuentas bancarias por moneda (USD vs PEN).
 * 2. Inmutabilidad contable (Heurística 5 - ForbiddenException).
 * 3. Validación de motivo obligatorio en rechazos (Heurísticas 5 y 9 - BadRequestException).
 * 4. Conciliación atómica y recálculo de acumuladores contables.
 */
class FinancialReconciliationTest extends TestCase
{
    /**
     * Caso de prueba 1: Filtrado estricto de cuentas bancarias por divisa (USD vs PEN).
     * a) Cuentas en BD: BCP USD, BBVA USD, BCP PEN, Interbank PEN.
     * b) Cobro en USD -> Retorna únicamente 2 cuentas en USD, 0 en PEN.
     * c) Cobro en PEN -> Retorna únicamente 2 cuentas en PEN, 0 en USD.
     */
    public function testStrictCurrencyFilteringUSDAndPEN(): void
    {
        $containerStub = $this->createStub(Container::class);
        $configStub = $this->createStub(Config::class);
        $emMock = $this->createStub(EntityManager::class);

        // Itinerary mock con token válido
        $itineraryStub = $this->createStub(Entity::class);
        $itineraryStub->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'id' => 'itin-01',
                'opportunityId' => 'opp-01',
                default => null,
            };
        });

        $itineraryRepo = $this->createStub(RDBRepository::class);
        $itineraryBuilder = $this->createStub(RDBSelectBuilder::class);
        $itineraryBuilder->method('findOne')->willReturn($itineraryStub);
        $itineraryRepo->method('where')->willReturn($itineraryBuilder);

        // 4 cuentas bancarias en catálogo
        $accBcpUsd = $this->createStub(Entity::class);
        $accBcpUsd->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'bcp-usd', 'name' => 'BCP Dólares', 'currency' => 'USD', 'bankName' => 'BCP', 'deleted' => false, 'isActive' => true, default => null
        });

        $accBbvaUsd = $this->createStub(Entity::class);
        $accBbvaUsd->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'bbva-usd', 'name' => 'BBVA Dólares', 'currency' => 'USD', 'bankName' => 'BBVA', 'deleted' => false, 'isActive' => true, default => null
        });

        $accBcpPen = $this->createStub(Entity::class);
        $accBcpPen->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'bcp-pen', 'name' => 'BCP Soles', 'currency' => 'PEN', 'bankName' => 'BCP', 'deleted' => false, 'isActive' => true, default => null
        });

        $accIbPen = $this->createStub(Entity::class);
        $accIbPen->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'ib-pen', 'name' => 'Interbank Soles', 'currency' => 'PEN', 'bankName' => 'Interbank', 'deleted' => false, 'isActive' => true, default => null
        });

        $selectedCurrency = 'USD';
        $bankRepo = $this->createStub(RDBRepository::class);
        $bankBuilder = $this->createStub(RDBSelectBuilder::class);
        $bankBuilder->method('order')->willReturnSelf();

        $bankRepo->method('where')->willReturnCallback(function (array $clause) use (&$selectedCurrency, $bankBuilder) {
            $selectedCurrency = $clause['currency'] ?? null;
            return $bankBuilder;
        });

        $bankBuilder->method('find')->willReturnCallback(function () use (
            &$selectedCurrency, $accBcpUsd, $accBbvaUsd, $accBcpPen, $accIbPen
        ) {
            if ($selectedCurrency === 'USD') {
                return new EntityCollection([$accBcpUsd, $accBbvaUsd]);
            }
            if ($selectedCurrency === 'PEN') {
                return new EntityCollection([$accBcpPen, $accIbPen]);
            }
            return new EntityCollection([]);
        });

        // Pagos en USD y PEN
        $paymentUsd = $this->createStub(Entity::class);
        $paymentUsd->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'pay-usd', 'opportunityId' => 'opp-01', 'currency' => 'USD', 'amount' => 500.0, 'deleted' => false, default => null
        });

        $paymentPen = $this->createStub(Entity::class);
        $paymentPen->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'pay-pen', 'opportunityId' => 'opp-01', 'currency' => 'PEN', 'amount' => 1800.0, 'deleted' => false, default => null
        });

        $emMock->method('getRDBRepository')->willReturnCallback(function (string $entityType) use (
            $itineraryRepo, $bankRepo
        ) {
            if ($entityType === 'Itinerario') return $itineraryRepo;
            if ($entityType === 'BankAccount') return $bankRepo;
            return null;
        });

        $emMock->method('getEntity')->willReturnCallback(function (string $type, string $id) use (
            $paymentUsd, $paymentPen
        ) {
            if ($type === 'Payment' && $id === 'pay-usd') return $paymentUsd;
            if ($type === 'Payment' && $id === 'pay-pen') return $paymentPen;
            return null;
        });

        $controller = new PublicPaymentController($containerStub, $emMock, $configStub);

        // 1. Consulta para cobro USD
        $resUsd = $controller->actionGetOptions(['publicAccessToken' => 'token-uuid', 'paymentId' => 'pay-usd'], null, null);
        $this->assertEquals('USD', $resUsd['currency']);
        $this->assertCount(2, $resUsd['accounts']);
        foreach ($resUsd['accounts'] as $account) {
            $this->assertEquals('USD', $account['currency'], 'Las cuentas para cobros en USD deben ser exclusivamente USD.');
            $this->assertNotEquals('PEN', $account['currency']);
        }

        // 2. Consulta para cobro PEN
        $resPen = $controller->actionGetOptions(['publicAccessToken' => 'token-uuid', 'paymentId' => 'pay-pen'], null, null);
        $this->assertEquals('PEN', $resPen['currency']);
        $this->assertCount(2, $resPen['accounts']);
        foreach ($resPen['accounts'] as $account) {
            $this->assertEquals('PEN', $account['currency'], 'Las cuentas para cobros en PEN deben ser exclusivamente PEN.');
            $this->assertNotEquals('USD', $account['currency']);
        }
    }

    /**
     * Caso de prueba 2: Inmutabilidad contable y ForbiddenException (Heurística 5 Nielsen).
     * Un pago en estado 'Confirmed' bloquea cualquier intento de alterar campos financieros.
     */
    public function testAccountingImmutabilityForbiddenExceptionOnConfirmedPayment(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($emStub, $serviceStub);

        // Sub-caso 2a: Intento de cambiar amount en un pago ya confirmado
        $entityAmountMutated = $this->createStub(Entity::class);
        $entityAmountMutated->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'Confirmed' : null);
        $entityAmountMutated->method('isAttributeChanged')->willReturnCallback(fn(string $f) => $f === 'amount');

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('No es posible modificar un pago que ya ha sido confirmado');
        $hook->beforeSave($entityAmountMutated);
    }

    /**
     * Sub-caso 2b: Intento de cambiar divisa (currency) en un pago confirmado.
     */
    public function testAccountingImmutabilityForbiddenOnCurrencyMutation(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($emStub, $serviceStub);

        $entityCurrencyMutated = $this->createStub(Entity::class);
        $entityCurrencyMutated->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'Confirmed' : null);
        $entityCurrencyMutated->method('isAttributeChanged')->willReturnCallback(fn(string $f) => $f === 'currency');

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('No es posible modificar un pago que ya ha sido confirmado');
        $hook->beforeSave($entityCurrencyMutated);
    }

    /**
     * Sub-caso 2c: Intento de cambiar la oportunidad vinculada (opportunityId) en un pago confirmado.
     */
    public function testAccountingImmutabilityForbiddenOnOpportunityMutation(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($emStub, $serviceStub);

        $entityOppMutated = $this->createStub(Entity::class);
        $entityOppMutated->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'Confirmed' : null);
        $entityOppMutated->method('isAttributeChanged')->willReturnCallback(fn(string $f) => $f === 'opportunityId');

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('No es posible modificar un pago que ya ha sido confirmado');
        $hook->beforeSave($entityOppMutated);
    }

    /**
     * Caso de prueba 3: Validación de motivo de rechazo (Heurísticas 5 y 9 Nielsen).
     * Transicionar a 'Rejected' sin especificar rejectionReason arroja BadRequest.
     */
    public function testRejectionValidationBadRequestOnEmptyReason(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($emStub, $serviceStub);

        $entityRejectedNoReason = $this->createStub(Entity::class);
        $entityRejectedNoReason->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'UnderReview' : null);
        $entityRejectedNoReason->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'status' => 'Rejected',
                'rejectionReason' => '',
                default => null,
            };
        });

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Debe especificar un motivo de rechazo válido');
        $hook->beforeSave($entityRejectedNoReason);
    }

    /**
     * Caso de prueba 4: Rechazo con motivo válido pasa la validación sin arrojar excepción.
     */
    public function testRejectionValidationPassesWithValidReason(): void
    {
        $emStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($emStub, $serviceStub);

        $entityRejectedValid = $this->createStub(Entity::class);
        $entityRejectedValid->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'UnderReview' : null);
        $entityRejectedValid->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'status' => 'Rejected',
                'rejectionReason' => 'unreadable_voucher',
                default => null,
            };
        });
        $entityRejectedValid->method('isAttributeChanged')->willReturn(false);

        // No debe arrojar excepción
        $hook->beforeSave($entityRejectedValid);
        $this->assertTrue(true, 'Un rechazo con motivo válido debe superar beforeSave sin errores.');
    }

    /**
     * Caso de prueba 5: Conciliación financiera atómica y recálculo de acumuladores contables.
     */
    public function testAcidReconciliationCalculatesCorrectBalances(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $txMock = $this->createMock(TransactionManager::class);

        $txMock->expects($this->once())->method('start');
        $txMock->expects($this->once())->method('commit');

        $emMock->expects($this->once())->method('getTransactionManager')->willReturn($txMock);

        // Oportunidad de $1,000 USD
        $opportunityMock = $this->createStub(Entity::class);
        $oppValues = [
            'id' => 'opp-test-100',
            'amount' => 1000.00,
            'amountPaid' => 0.0,
            'pendingBalance' => 1000.00,
            'financialStatus' => 'Pending',
            'stage' => 'Proposal',
        ];
        $opportunityMock->method('get')->willReturnCallback(fn(string $f) => $oppValues[$f] ?? null);
        $opportunityMock->method('set')->willReturnCallback(function ($field, $value = null) use (&$oppValues, $opportunityMock) {
            if (is_array($field)) {
                foreach ($field as $k => $v) $oppValues[$k] = $v;
            } else {
                $oppValues[$field] = $value;
            }
            return $opportunityMock;
        });

        $emMock->expects($this->once())->method('getEntity')->willReturnCallback(function (string $type, string $id) use ($opportunityMock) {
            if ($type === 'Opportunity' && $id === 'opp-test-100') return $opportunityMock;
            return null;
        });

        // 1 cobro confirmado de $500.00 USD
        $payment1 = $this->createStub(Entity::class);
        $payment1->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'id' => 'p1', 'amount' => 500.00, 'status' => 'Confirmed', default => null
        });

        $paymentRepo = $this->createStub(RDBRepository::class);
        $paymentBuilder = $this->createStub(RDBSelectBuilder::class);
        $paymentBuilder->method('find')->willReturn(new EntityCollection([$payment1]));
        $paymentRepo->method('where')->willReturn($paymentBuilder);

        $emMock->expects($this->once())->method('getRDBRepository')->with('Payment')->willReturn($paymentRepo);
        $emMock->expects($this->once())->method('saveEntity')->with($opportunityMock, ['skipHooks' => true]);

        $service = new FinancialReconciliationService($emMock);

        $paymentTrigger = $this->createStub(Entity::class);
        $paymentTrigger->method('get')->willReturnCallback(fn(string $f) => match($f) {
            'opportunityId' => 'opp-test-100',
            'paymentScheduleId' => null,
            default => null
        });

        $service->reconcileConfirmedPayment($paymentTrigger);

        $this->assertEquals(500.00, $oppValues['amountPaid']);
        $this->assertEquals(500.00, $oppValues['pendingBalance']);
        $this->assertEquals('PartiallyPaid', $oppValues['financialStatus']);
        $this->assertEquals('Proposal', $oppValues['stage']);
    }
}
