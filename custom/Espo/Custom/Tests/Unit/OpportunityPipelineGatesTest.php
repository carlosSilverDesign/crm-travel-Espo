<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Opportunity\PaymentPendingGate;
use Espo\Custom\Hooks\Opportunity\LostReasonGuard;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Core\Templates\Repositories\Base as RDBRepository;

class OpportunityPipelineGatesTest extends TestCase
{
    // =========================================================================
    // 1. PRUEBAS PARA PaymentPendingGate
    // =========================================================================

    /**
     * Valida que mover una Opportunity a PaymentPending sin un Itinerario
     * en estado 'Cotización' arroje una excepción HTTP 400 BadRequest.
     */
    public function testPaymentPendingWithoutQuotedItineraryThrowsBadRequest(): void
    {
        $selectBuilderStub = $this->createStub(RDBSelectBuilder::class);
        $selectBuilderStub->method('findOne')
            ->willReturn(null);

        $repositoryMock = $this->createMock(RDBRepository::class);
        $repositoryMock->expects($this->once())
            ->method('where')
            ->with([
                'opportunityId' => 'opp-001',
                'status' => 'Cotización'
            ])
            ->willReturn($selectBuilderStub);

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->once())
            ->method('getRDBRepository')
            ->with('Itinerario')
            ->willReturn($repositoryMock);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(fn(string $field) => $field === 'stage' ? 'PaymentPending' : null);
        $oppStub->method('hasId')
            ->willReturn(true);
        $oppStub->method('getId')
            ->willReturn('opp-001');

        $hook = new PaymentPendingGate($entityManagerMock);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage(
            'No es posible pasar a Espera de Pago sin un Itinerario en cotización vinculado. Cree o asocie un itinerario primero.'
        );

        $hook->beforeSave($oppStub);
    }

    /**
     * Valida que si existe al menos un Itinerario con status = 'Cotización',
     * la compuerta permita la persistencia de PaymentPending limpiamente.
     */
    public function testPaymentPendingWithQuotedItineraryAllowsSave(): void
    {
        $itineraryStub = $this->createStub(Entity::class);

        $selectBuilderStub = $this->createStub(RDBSelectBuilder::class);
        $selectBuilderStub->method('findOne')
            ->willReturn($itineraryStub);

        $repositoryMock = $this->createMock(RDBRepository::class);
        $repositoryMock->expects($this->once())
            ->method('where')
            ->with([
                'opportunityId' => 'opp-001',
                'status' => 'Cotización'
            ])
            ->willReturn($selectBuilderStub);

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->once())
            ->method('getRDBRepository')
            ->with('Itinerario')
            ->willReturn($repositoryMock);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(fn(string $field) => $field === 'stage' ? 'PaymentPending' : null);
        $oppStub->method('hasId')
            ->willReturn(true);
        $oppStub->method('getId')
            ->willReturn('opp-001');

        $hook = new PaymentPendingGate($entityManagerMock);

        $hook->beforeSave($oppStub);

        $this->assertTrue(true, 'La transición a PaymentPending debe permitirse con itinerario cotizado');
    }

    /**
     * Valida que una transición a otra etapa (ej. 'Proposal') no active
     * la consulta al repositorio ni arroje excepción.
     */
    public function testOtherStageTransitionDoesNotTriggerValidation(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->never())
            ->method('getRDBRepository');

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(fn(string $field) => $field === 'stage' ? 'Proposal' : null);

        $hook = new PaymentPendingGate($entityManagerMock);

        $hook->beforeSave($oppStub);

        $this->assertTrue(true, 'Transición a Proposal no debe ejecutar validación de PaymentPending');
    }

    /**
     * Valida que la opción skipHooks permita ignorar la compuerta PaymentPendingGate
     * durante procesos internos automatizados.
     */
    public function testPaymentPendingWithSkipHooksOptionBypassesValidation(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->never())
            ->method('getRDBRepository');

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(fn(string $field) => $field === 'stage' ? 'PaymentPending' : null);

        $hook = new PaymentPendingGate($entityManagerMock);

        $hook->beforeSave($oppStub, ['skipHooks' => true]);

        $this->assertTrue(true, 'Opción skipHooks debe omitir validación');
    }

    // =========================================================================
    // 2. PRUEBAS PARA LostReasonGuard
    // =========================================================================

    /**
     * Valida que intentar cerrar una oportunidad como 'Closed Lost' sin
     * especificar un motivo (lostReason vacío o nulo) arroje HTTP 400 BadRequest.
     */
    public function testClosedLostWithoutReasonThrowsBadRequest(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'stage' => 'Closed Lost',
                    'lostReason' => '',
                    default => null,
                };
            });

        $hook = new LostReasonGuard($entityManagerStub);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage(
            'Debe especificar el motivo de pérdida (lostReason) para cerrar la oportunidad como descartada o perdida.'
        );

        $hook->beforeSave($oppStub);
    }

    /**
     * Valida que intentar cerrar como 'Closed Lost' con motivo en blanco (espacios)
     * también sea rechazado.
     */
    public function testClosedLostWithWhitespaceReasonThrowsBadRequest(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'stage' => 'Closed Lost',
                    'lostReason' => '   ',
                    default => null,
                };
            });

        $hook = new LostReasonGuard($entityManagerStub);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage(
            'Debe especificar el motivo de pérdida (lostReason) para cerrar la oportunidad como descartada o perdida.'
        );

        $hook->beforeSave($oppStub);
    }

    /**
     * Valida que cerrar como 'Closed Lost' con un motivo válido persista sin excepciones.
     */
    public function testClosedLostWithValidReasonAllowsSave(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'stage' => 'Closed Lost',
                    'lostReason' => 'Precio / Presupuesto Alto',
                    default => null,
                };
            });

        $hook = new LostReasonGuard($entityManagerStub);

        $hook->beforeSave($oppStub);

        $this->assertTrue(true, 'Closed Lost con motivo válido debe persistirse limpiamente');
    }

    /**
     * Valida que la opción skipHooks permita ignorar LostReasonGuard en automatizaciones internas.
     */
    public function testClosedLostWithSkipHooksBypassesValidation(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);

        $oppStub = $this->createStub(Entity::class);
        $oppStub->method('isAttributeChanged')
            ->willReturnCallback(fn(string $field) => $field === 'stage');
        $oppStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'stage' => 'Closed Lost',
                    'lostReason' => null,
                    default => null,
                };
            });

        $hook = new LostReasonGuard($entityManagerStub);

        $hook->beforeSave($oppStub, ['skipHooks' => true]);

        $this->assertTrue(true, 'skipHooks debe omitir validación de motivo de pérdida');
    }
}
