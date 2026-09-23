<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Controllers\PaymentController;
use Espo\Custom\Controllers\Payment as PaymentAliasController;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Core\Acl;
use Espo\ORM\Entity;

class PaymentControllerTest extends TestCase
{
    private function createMockUser(string $id, bool $isAdmin = false, array $roles = []): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($isAdmin);

        $roleEntities = [];
        foreach ($roles as $roleName) {
            $roleEntity = $this->createStub(Entity::class);
            $roleEntity->method('get')->willReturnCallback(function (string $field) use ($roleName) {
                return $field === 'name' ? $roleName : null;
            });
            $roleEntities[] = $roleEntity;
        }

        $user->method('get')->willReturnCallback(function (string $field) use ($roleEntities) {
            if ($field === 'roles') {
                return $roleEntities;
            }
            return null;
        });

        return $user;
    }

    private function createPaymentEntity(
        string $id,
        string $status = 'UnderReview',
        float $amount = 500.0,
        string $currency = 'USD'
    ): Entity {
        $payment = $this->createStub(Entity::class);
        $payment->method('getId')->willReturn($id);

        $attributes = [
            'id' => $id,
            'name' => 'PAY-2026-001',
            'status' => $status,
            'paymentReference' => 'PAY-TEST01',
            'amount' => $amount,
            'currency' => $currency,
            'opportunityId' => 'opp-100',
            'paymentScheduleId' => 'sched-200',
            'verifiedById' => null,
            'verifiedAt' => null,
            'rejectionReason' => null,
            'verificationNotes' => null,
        ];

        $payment->method('get')->willReturnCallback(function (string $f) use (&$attributes) {
            return $attributes[$f] ?? null;
        });

        $payment->method('set')->willReturnCallback(function ($field, $value = null) use (&$attributes, $payment) {
            if (is_array($field)) {
                foreach ($field as $k => $v) {
                    $attributes[$k] = $v;
                }
            } else {
                $attributes[$field] = $value;
            }
            return $payment;
        });

        return $payment;
    }

    /**
     * 1. Confirmación exitosa de un pago 'UnderReview' por usuario Administrador.
     */
    public function testConfirmPaymentSuccessAsAdmin(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createMock(EntityManager::class);
        $user = $this->createMockUser('admin-user-01', true);

        $payment = $this->createPaymentEntity('pay-001', 'UnderReview', 750.00, 'USD');

        $em->expects($this->once())
            ->method('getEntity')
            ->with('Payment', 'pay-001')
            ->willReturn($payment);

        $em->expects($this->once())
            ->method('saveEntity')
            ->with($payment);

        $controller = new PaymentController($container, $em, $config, $user);

        $result = $controller->postActionConfirm([], ['id' => 'pay-001']);

        $this->assertIsArray($result);
        $this->assertEquals('pay-001', $result['id']);
        $this->assertEquals('Confirmed', $result['status']);
        $this->assertEquals('admin-user-01', $result['verifiedById']);
        $this->assertNotEmpty($result['verifiedAt']);
        $this->assertStringContainsString('confirmado exitosamente', $result['message']);
    }

    /**
     * 2. Confirmación exitosa por usuario con rol 'Cajero' / 'Finanzas'.
     */
    public function testConfirmPaymentSuccessAsCashier(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createMock(EntityManager::class);
        $cashierUser = $this->createMockUser('cajero-02', false, ['Cajero Central', 'Operaciones']);

        $payment = $this->createPaymentEntity('pay-002', 'UnderReview', 1200.00, 'PEN');

        $em->expects($this->once())->method('getEntity')->with('Payment', 'pay-002')->willReturn($payment);
        $em->expects($this->once())->method('saveEntity')->with($payment);

        $controller = new PaymentController($container, $em, $config, $cashierUser);

        $result = $controller->actionConfirm([], ['id' => 'pay-002']);

        $this->assertEquals('Confirmed', $result['status']);
        $this->assertEquals('cajero-02', $result['verifiedById']);
        $this->assertEquals(1200.00, $result['amount']);
    }

    /**
     * 3. Rechazo de confirmación por usuario sin permisos de auditoría (403 Forbidden).
     */
    public function testConfirmPaymentForbiddenForUnauthorizedUser(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $unauthorizedUser = $this->createMockUser('advisor-03', false, ['Asesor Junior de Ventas']);

        $controller = new PaymentController($container, $em, $config, $unauthorizedUser);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('Acceso denegado');

        $controller->postActionConfirm([], ['id' => 'pay-001']);
    }

    /**
     * 4. Error 400 BadRequest si no se proporciona el ID del cobro.
     */
    public function testConfirmPaymentBadRequestIfMissingId(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El identificador 'id' del cobro es obligatorio");

        $controller->postActionConfirm([], []);
    }

    /**
     * 5. Error 404 NotFound si el cobro no existe en la base de datos.
     */
    public function testConfirmPaymentNotFoundIfPaymentDoesNotExist(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $em->method('getEntity')->willReturn(null);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage("no fue encontrado");

        $controller->postActionConfirm([], ['id' => 'pay-inexistente']);
    }

    /**
     * 6. Error 400 BadRequest si el cobro ya se encuentra en estado 'Confirmed'.
     */
    public function testConfirmPaymentBadRequestIfAlreadyConfirmed(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $payment = $this->createPaymentEntity('pay-confirmed', 'Confirmed');
        $em->method('getEntity')->willReturn($payment);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El cobro ya se encuentra confirmado");

        $controller->postActionConfirm([], ['id' => 'pay-confirmed']);
    }

    /**
     * 7. Rechazo exitoso con motivo válido y notas de verificación.
     */
    public function testRejectPaymentSuccessWithValidReasonAndNotes(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createMock(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $payment = $this->createPaymentEntity('pay-reject-01', 'UnderReview');

        $em->expects($this->once())->method('getEntity')->with('Payment', 'pay-reject-01')->willReturn($payment);
        $em->expects($this->once())->method('saveEntity')->with($payment);

        $controller = new PaymentController($container, $em, $config, $user);

        $payload = [
            'id' => 'pay-reject-01',
            'rejectionReason' => 'unreadable_voucher',
            'verificationNotes' => 'Comprobante borroso y cortado en el número de cuenta.',
        ];

        $result = $controller->postActionReject([], $payload);

        $this->assertIsArray($result);
        $this->assertEquals('pay-reject-01', $result['id']);
        $this->assertEquals('Rejected', $result['status']);
        $this->assertEquals('unreadable_voucher', $result['rejectionReason']);
        $this->assertEquals('Comprobante borroso y cortado en el número de cuenta.', $result['verificationNotes']);
        $this->assertEquals('admin-01', $result['verifiedById']);
        $this->assertNotEmpty($result['verifiedAt']);
    }

    /**
     * 8. Error 400 BadRequest si falta el motivo estructurado en el rechazo (Heurísticas 5 y 9).
     */
    public function testRejectPaymentBadRequestIfMissingReason(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Debe especificar un motivo de rechazo válido ('rejectionReason')");

        $controller->postActionReject([], ['id' => 'pay-001', 'rejectionReason' => '']);
    }

    /**
     * 9. Error 400 BadRequest si el motivo de rechazo no está en el catálogo enum permitido.
     */
    public function testRejectPaymentBadRequestIfInvalidEnumReason(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Debe especificar un motivo de rechazo válido ('rejectionReason')");

        $controller->postActionReject([], ['id' => 'pay-001', 'rejectionReason' => 'motivo_inventado']);
    }

    /**
     * 10. Error 403 Forbidden al intentar rechazar un cobro que ya ha sido confirmado (Inmutabilidad contable).
     */
    public function testRejectPaymentForbiddenIfAlreadyConfirmed(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $payment = $this->createPaymentEntity('pay-confirmed-immutable', 'Confirmed');
        $em->method('getEntity')->willReturn($payment);

        $controller = new PaymentController($container, $em, $config, $user);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage("Inmutabilidad contable");

        $controller->postActionReject([], [
            'id' => 'pay-confirmed-immutable',
            'rejectionReason' => 'wrong_amount'
        ]);
    }

    /**
     * 11. Valida que el alias Payment extienda PaymentController y herede todos los métodos.
     */
    public function testPaymentAliasExtendsPaymentController(): void
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        $em = $this->createStub(EntityManager::class);
        $user = $this->createMockUser('admin-01', true);

        $alias = new PaymentAliasController($container, $em, $config, $user);

        $this->assertInstanceOf(PaymentController::class, $alias);
        $this->assertTrue(method_exists($alias, 'postActionConfirm'));
        $this->assertTrue(method_exists($alias, 'postActionReject'));
        $this->assertTrue(method_exists($alias, 'actionConfirm'));
        $this->assertTrue(method_exists($alias, 'actionReject'));
    }
}
