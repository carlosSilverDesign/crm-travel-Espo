<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Controllers\PublicPaymentController;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

class PublicPaymentControllerTest extends TestCase
{
    private function createController(EntityManager $em): PublicPaymentController
    {
        $container = $this->createStub(Container::class);
        $config = $this->createStub(Config::class);
        return new PublicPaymentController($container, $em, $config);
    }

    /**
     * Valida que un cobro en USD retorne estricta y exclusivamente cuentas en USD,
     * excluyendo al 100% cualquier cuenta en PEN (Heurística 5 / Prevención de Errores).
     */
    public function testGetOptionsFiltersStrictlyByCurrencyUSD(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $itineraryRepo = $this->createStub(RDBRepository::class);
        $itineraryBuilder = $this->createStub(RDBSelectBuilder::class);
        $bankRepo = $this->createMock(RDBRepository::class);
        $bankBuilder = $this->createStub(RDBSelectBuilder::class);

        // 1. Mock de Itinerario con token público válido
        $itineraryStub = $this->createStub(Entity::class);
        $itineraryStub->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'id' => 'itin-001',
                'name' => 'EXP-2026-CUSCO',
                'opportunityId' => 'opp-100',
                'leadTravelerName' => 'Viajero VIP',
                default => null
            };
        });

        $itineraryBuilder->method('findOne')->willReturn($itineraryStub);
        $itineraryRepo->method('where')->willReturn($itineraryBuilder);

        // 2. Mock de Payment en USD
        $paymentStub = $this->createStub(Entity::class);
        $paymentStub->method('getId')->willReturn('pay-usd-01');
        $paymentStub->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'deleted' => false,
                'opportunityId' => 'opp-100',
                'currency' => 'USD',
                'amount' => 1250.00,
                'paymentReference' => 'RES-2026-00452-C1',
                'name' => 'Pago Cuota 1 USD',
                'status' => 'Pending',
                'dueDate' => '2026-10-15',
                default => null
            };
        });

        // 3. Mock de BankAccount: Cuenta en USD
        $accUsd1 = $this->createStub(Entity::class);
        $accUsd1->method('getId')->willReturn('acc-usd-1');
        $accUsd1->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'name' => 'BCP Dólares Corriente Empresa',
                'bankName' => 'BCP',
                'currency' => 'USD',
                'accountHolder' => 'DESTINOS VIAJES S.A.C.',
                'accountType' => 'Corriente',
                'accountNumber' => '194-98765432-1-89',
                'cci' => '00219400987654321890',
                'swiftBic' => 'BCPLPEPL',
                default => null
            };
        });

        $accUsd2 = $this->createStub(Entity::class);
        $accUsd2->method('getId')->willReturn('acc-usd-2');
        $accUsd2->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'name' => 'Interbank Dólares Corriente',
                'bankName' => 'Interbank',
                'currency' => 'USD',
                'accountHolder' => 'DESTINOS VIAJES S.A.C.',
                'accountType' => 'Corriente',
                'accountNumber' => '200-3001234567',
                'cci' => '00320000300123456789',
                'swiftBic' => 'BINSPEPL',
                default => null
            };
        });

        $bankAccountsCollection = new EntityCollection([$accUsd1, $accUsd2]);

        $bankBuilder->method('order')->willReturnSelf();
        $bankBuilder->method('find')->willReturn($bankAccountsCollection);

        $bankRepo->expects($this->once())
            ->method('where')
            ->with([
                'currency' => 'USD',
                'isActive' => true,
                'deleted'  => false,
            ])
            ->willReturn($bankBuilder);

        $emMock->expects($this->any())
            ->method('getRDBRepository')
            ->willReturnCallback(function (string $entityType) use ($itineraryRepo, $bankRepo) {
                return match($entityType) {
                    'Itinerario' => $itineraryRepo,
                    'BankAccount' => $bankRepo,
                    default => null
                };
            });

        $emMock->expects($this->any())
            ->method('getEntity')
            ->willReturnCallback(function ($type, $id) use ($paymentStub) {
                if ($type === 'Payment' && $id === 'pay-usd-01') return $paymentStub;
                return null;
            });

        $controller = $this->createController($emMock);

        $response = $controller->actionGetOptions(
            ['publicAccessToken' => 'token-uuid-1234', 'paymentId' => 'pay-usd-01'],
            [],
            null
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('USD', $response['currency']);
        $this->assertEquals(1250.00, $response['amount']);
        $this->assertEquals('RES-2026-00452-C1', $response['paymentReference']);
        $this->assertCount(2, $response['accounts']);

        foreach ($response['accounts'] as $acc) {
            $this->assertEquals('USD', $acc['currency'], 'Ninguna cuenta en moneda distinta de USD debe ser retornada.');
        }
    }

    /**
     * Valida que un token inválido o nulo arroje excepción NotFound o BadRequest.
     */
    public function testGetOptionsWithInvalidTokenThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $emMock = $this->createMock(EntityManager::class);
        $itineraryRepo = $this->createStub(RDBRepository::class);
        $itineraryBuilder = $this->createStub(RDBSelectBuilder::class);

        $itineraryBuilder->method('findOne')->willReturn(null);
        $itineraryRepo->method('where')->willReturn($itineraryBuilder);
        $emMock->expects($this->any())->method('getRDBRepository')->willReturn($itineraryRepo);

        $controller = $this->createController($emMock);
        $controller->actionGetOptions(['publicAccessToken' => 'token-invalido', 'paymentId' => 'pay-001'], [], null);
    }

    /**
     * Valida que postReport cree un Attachment nativo y mute el cobro estrictamente a UnderReview
     * (NUNCA a Confirmed).
     */
    public function testPostReportTransitionsStrictlyToUnderReview(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $itineraryRepo = $this->createStub(RDBRepository::class);
        $itineraryBuilder = $this->createStub(RDBSelectBuilder::class);

        $itineraryStub = $this->createStub(Entity::class);
        $itineraryStub->method('get')->willReturnCallback(fn(string $f) => $f === 'opportunityId' ? 'opp-100' : null);
        $itineraryBuilder->method('findOne')->willReturn($itineraryStub);
        $itineraryRepo->method('where')->willReturn($itineraryBuilder);
        $emMock->expects($this->any())->method('getRDBRepository')->willReturn($itineraryRepo);

        $paymentMock = $this->createMock(Entity::class);
        $paymentData = [
            'id' => 'pay-001',
            'status' => 'Pending',
            'opportunityId' => 'opp-100',
            'amount' => 500.0,
            'paymentReference' => 'PAY-ABC123',
            'deleted' => false,
        ];
        $paymentMock->method('getId')->willReturn('pay-001');
        $paymentMock->method('get')->willReturnCallback(fn(string $f) => $paymentData[$f] ?? null);

        $paymentMock->expects($this->once())
            ->method('set')
            ->willReturnCallback(function (array $values) use (&$paymentData, $paymentMock) {
                foreach ($values as $k => $v) { $paymentData[$k] = $v; }
                return $paymentMock;
            });

        $attachmentStub = $this->createStub(Entity::class);
        $attachmentStub->method('getId')->willReturn('attach-999');

        $emMock->expects($this->any())
            ->method('getEntity')
            ->willReturnCallback(function ($type, $id) use ($paymentMock) {
                if ($type === 'Payment' && $id === 'pay-001') return $paymentMock;
                return null;
            });

        $emMock->expects($this->any())
            ->method('getNewEntity')
            ->willReturnCallback(function ($type) use ($attachmentStub) {
                if ($type === 'Attachment') return $attachmentStub;
                return null;
            });

        $emMock->expects($this->exactly(2))->method('saveEntity');

        $controller = $this->createController($emMock);

        // Simular payload con base64 para evitar depender de uploads reales en el test unitario
        $fakePdfContent = "%PDF-1.4 Fake PDF Content";
        $payload = [
            'clientOperationNumber' => 'OP-987654',
            'clientDeclaredAmount'  => 500.0,
            'clientDeclaredDate'    => '2026-09-23',
            'fileName'              => 'voucher_bancario.pdf',
            'fileType'              => 'application/pdf',
            'fileBase64'            => base64_encode($fakePdfContent),
        ];

        $response = $controller->actionPostReport(
            ['publicAccessToken' => 'valid-token', 'paymentId' => 'pay-001'],
            $payload,
            null
        );

        $this->assertTrue($response['success']);
        $this->assertEquals('UnderReview', $response['status'], 'El estado debe mutar estrictamente a UnderReview.');
        $this->assertEquals('UnderReview', $paymentData['status']);
        $this->assertEquals('attach-999', $paymentData['proofAttachmentId']);
        $this->assertEquals('OP-987654', $paymentData['clientOperationNumber']);
    }

    /**
     * Valida que no se pueda enviar constancia a un cobro ya confirmado.
     */
    public function testPostReportOnConfirmedPaymentThrowsForbidden(): void
    {
        $this->expectException(Forbidden::class);

        $emMock = $this->createMock(EntityManager::class);
        $itineraryRepo = $this->createStub(RDBRepository::class);
        $itineraryBuilder = $this->createStub(RDBSelectBuilder::class);

        $itineraryStub = $this->createStub(Entity::class);
        $itineraryStub->method('get')->willReturnCallback(fn(string $f) => $f === 'opportunityId' ? 'opp-100' : null);
        $itineraryBuilder->method('findOne')->willReturn($itineraryStub);
        $itineraryRepo->method('where')->willReturn($itineraryBuilder);
        $emMock->expects($this->any())->method('getRDBRepository')->willReturn($itineraryRepo);

        $paymentStub = $this->createStub(Entity::class);
        $paymentStub->method('get')->willReturnCallback(function (string $f) {
            return match($f) {
                'deleted' => false,
                'opportunityId' => 'opp-100',
                'status' => 'Confirmed',
                default => null
            };
        });

        $emMock->expects($this->any())->method('getEntity')->willReturn($paymentStub);

        $controller = $this->createController($emMock);
        $controller->actionPostReport(
            ['publicAccessToken' => 'token-ok', 'paymentId' => 'pay-confirmed'],
            [],
            null
        );
    }
}
