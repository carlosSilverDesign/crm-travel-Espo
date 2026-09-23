<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Payment\FinancialReconciliation as FinancialReconciliationHook;
use Espo\Custom\Services\FinancialReconciliationService;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;

class FinancialReconciliationHookTest extends TestCase
{
    /**
     * Valida que al guardar un nuevo Payment sin paymentReference,
     * el hook genere automáticamente la referencia en formato "PAY-" + 6 caracteres.
     */
    public function testBeforeSaveGeneratesPaymentReference(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceStub);

        $assignedValues = [];
        $entityStub = $this->createStub(Entity::class);

        $entityStub->method('get')
            ->willReturnCallback(function (string $name) use (&$assignedValues) {
                if (array_key_exists($name, $assignedValues)) {
                    return $assignedValues[$name];
                }
                return match($name) {
                    'paymentReference' => null,
                    'name'             => null,
                    'status'           => 'Pending',
                    default            => null
                };
            });

        $entityStub->method('set')
            ->willReturnCallback(function ($name, $val = null) use (&$assignedValues, $entityStub) {
                $assignedValues[$name] = $val;
                return $entityStub;
            });

        $entityStub->method('getFetched')->willReturn(null);

        $hook->beforeSave($entityStub);

        $this->assertArrayHasKey('paymentReference', $assignedValues);
        $this->assertMatchesRegularExpression('/^PAY-[A-Z0-9]{6}$/', $assignedValues['paymentReference']);
        $this->assertEquals($assignedValues['paymentReference'], $assignedValues['name']);
    }

    /**
     * Valida que un pago que ya posee paymentReference no sea sobrescrito.
     */
    public function testBeforeSavePreservesExistingPaymentReference(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceStub);

        $entityMock = $this->createMock(Entity::class);

        $entityMock->method('get')
            ->willReturnCallback(function (string $name) {
                return match($name) {
                    'paymentReference' => 'RES-2026-00452-C1',
                    'name'             => 'Pago Cuota 1',
                    'status'           => 'Pending',
                    default            => null
                };
            });

        $entityMock->expects($this->never())->method('set');
        $entityMock->method('getFetched')->willReturn(null);

        $hook->beforeSave($entityMock);
    }

    /**
     * Valida que el intento de modificar campos financieros en un cobro confirmado
     * sea bloqueado arrojando 403 Forbidden (Inmutabilidad Contable).
     */
    public function testBeforeSaveBlocksMutationOnConfirmedPayment(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage("No es posible modificar un pago que ya ha sido confirmado.");

        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceStub);

        $entityStub = $this->createStub(Entity::class);
        $entityStub->method('get')->willReturnCallback(fn(string $f) => $f === 'paymentReference' ? 'PAY-123456' : ($f === 'name' ? 'PAY-123456' : null));
        $entityStub->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'Confirmed' : null);
        $entityStub->method('isAttributeChanged')->willReturnCallback(fn(string $f) => $f === 'amount');

        $hook->beforeSave($entityStub);
    }

    /**
     * Valida que transicionar a Rejected sin rejectionReason arroje BadRequestException (Heurística 9).
     */
    public function testBeforeSaveRequiresRejectionReasonWhenRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Debe especificar un motivo de rechazo válido.");

        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceStub);

        $entityStub = $this->createStub(Entity::class);
        $entityStub->method('get')->willReturnCallback(function (string $name) {
            return match($name) {
                'paymentReference' => 'PAY-XYZ987',
                'name'             => 'PAY-XYZ987',
                'status'           => 'Rejected',
                'rejectionReason'  => null,
                default            => null
            };
        });
        $entityStub->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'UnderReview' : null);
        $entityStub->method('isAttributeChanged')->willReturn(false);

        $hook->beforeSave($entityStub);
    }

    /**
     * Valida que pasar a Rejected con motivo válido sea admitido.
     */
    public function testBeforeSaveAllowsRejectedWithValidReason(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceStub = $this->createStub(FinancialReconciliationService::class);
        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceStub);

        $entityStub = $this->createStub(Entity::class);
        $entityStub->method('get')->willReturnCallback(function (string $name) {
            return match($name) {
                'paymentReference' => 'PAY-XYZ987',
                'name'             => 'PAY-XYZ987',
                'status'           => 'Rejected',
                'rejectionReason'  => 'wrong_amount',
                default            => null
            };
        });
        $entityStub->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'UnderReview' : null);
        $entityStub->method('isAttributeChanged')->willReturn(false);

        $hook->beforeSave($entityStub);
        $this->assertTrue(true);
    }

    /**
     * Valida que al transicionar a 'Confirmed', afterSave invoque la conciliación financiera atómica.
     */
    public function testAfterSaveTriggersReconciliationWhenConfirmed(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $serviceMock = $this->createMock(FinancialReconciliationService::class);

        $entityStub = $this->createStub(Entity::class);
        $entityStub->method('get')->willReturnCallback(fn(string $f) => $f === 'status' ? 'Confirmed' : null);
        $entityStub->method('getFetched')->willReturnCallback(fn(string $f) => $f === 'status' ? 'UnderReview' : null);

        $serviceMock->expects($this->once())
            ->method('reconcileConfirmedPayment')
            ->with($entityStub);

        $hook = new FinancialReconciliationHook($entityManagerStub, $serviceMock);
        $hook->afterSave($entityStub);
    }

    /**
     * Valida la lógica de conciliación del FinancialReconciliationService con cálculo de saldo
     * y cierre automático en Closed Won al liquidar el 100%.
     */
    public function testServiceReconciliationCalculatesBalanceAndClosesWon(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $transactionManagerMock = $this->createMock(TransactionManager::class);
        $repositoryStub = $this->createStub(RDBRepository::class);
        $selectBuilderStub = $this->createStub(RDBSelectBuilder::class);

        $entityManagerMock->method('getTransactionManager')->willReturn($transactionManagerMock);
        $transactionManagerMock->expects($this->once())->method('start');
        $transactionManagerMock->expects($this->once())->method('commit');

        // Payment confirmado
        $paymentStub = $this->createStub(Entity::class);
        $paymentStub->method('get')->willReturnCallback(function (string $name) {
            return match($name) {
                'opportunityId'     => 'opp-100',
                'paymentScheduleId' => 'sched-200',
                'amount'            => 500.0,
                'status'            => 'Confirmed',
                default             => null
            };
        });

        // Opportunity de $1,000 USD
        $oppData = [
            'amount'          => 1000.0,
            'amountPaid'      => 0.0,
            'pendingBalance'  => 1000.0,
            'financialStatus' => 'Unpaid',
            'stage'           => 'PaymentPending'
        ];
        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('get')->willReturnCallback(fn(string $f) => $oppData[$f] ?? null);
        $oppStub->method('set')->willReturnCallback(function ($k, $v = null) use (&$oppData, $oppStub) {
            $oppData[$k] = $v;
            return $oppStub;
        });

        // PaymentSchedule asociado
        $schedData = ['status' => 'Pendiente'];
        $schedStub = $this->createStub(Entity::class);
        $schedStub->method('get')->willReturnCallback(fn(string $f) => $schedData[$f] ?? null);
        $schedStub->method('set')->willReturnCallback(function ($k, $v = null) use (&$schedData, $schedStub) {
            $schedData[$k] = $v;
            return $schedStub;
        });

        $entityManagerMock->method('getEntity')->willReturnCallback(function ($type, $id) use ($oppStub, $schedStub) {
            if ($type === 'Opportunity' && $id === 'opp-100') return $oppStub;
            if ($type === 'PaymentSchedule' && $id === 'sched-200') return $schedStub;
            return null;
        });

        // Simular 2 pagos confirmados de $500 cada uno (Total $1,000)
        $p1 = $this->createStub(Entity::class);
        $p1->method('get')->willReturnCallback(fn(string $f) => $f === 'amount' ? 500.0 : null);
        $p2 = $this->createStub(Entity::class);
        $p2->method('get')->willReturnCallback(fn(string $f) => $f === 'amount' ? 500.0 : null);

        $entityCollection = new EntityCollection([$p1, $p2]);

        $entityManagerMock->expects($this->once())
            ->method('getRDBRepository')
            ->with('Payment')
            ->willReturn($repositoryStub);

        $repositoryStub->method('where')->willReturn($selectBuilderStub);
        $selectBuilderStub->method('find')->willReturn($entityCollection);

        $service = new FinancialReconciliationService($entityManagerMock);
        $service->reconcileConfirmedPayment($paymentStub);

        // Aserciones de cuadre contable
        $this->assertEquals(1000.0, $oppData['amountPaid']);
        $this->assertEquals(0.0, $oppData['pendingBalance']);
        $this->assertEquals('PaidInFull', $oppData['financialStatus']);
        $this->assertEquals('Closed Won', $oppData['stage'], 'La oportunidad debe transicionar a Closed Won al liquidar el 100%.');
        $this->assertEquals('Pagado', $schedData['status'], 'La cuota de cronograma debe marcarse como Pagado.');
    }
}
