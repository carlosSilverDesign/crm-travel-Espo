<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Passenger\DocumentValidation;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;

class PassengerDocumentValidationTest extends TestCase
{
    /**
     * Valida que si la fecha de expiración del documento es anterior a la fecha
     * de finalización del itinerario, se lance una excepción BadRequest.
     */
    public function testPassengerDocumentExpiresBeforeEndDateThrowsException(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $itinerarioStub = $this->createStub(Entity::class);
        $passengerStub = $this->createStub(Entity::class);

        $itinerarioStub->method('get')
            ->willReturn('2026-12-15');

        $passengerStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'itinerarioId' => 'itin-01',
                    'documentExpiration' => '2026-12-10',
                    default => null,
                };
            });

        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-01')
            ->willReturn($itinerarioStub);

        $hook = new DocumentValidation($entityManagerMock);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('El documento del pasajero expira antes del término del itinerario (2026-12-15). Ingrese un documento vigente.');

        $hook->beforeSave($passengerStub);
    }

    /**
     * Valida el caso límite donde la expiración del documento coincide exactamente
     * con la fecha de término del itinerario (<= endDate debe rechazar).
     */
    public function testPassengerDocumentExpiresOnEndDateThrowsException(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $itinerarioStub = $this->createStub(Entity::class);
        $passengerStub = $this->createStub(Entity::class);

        $itinerarioStub->method('get')
            ->willReturn('2026-12-15');

        $passengerStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'itinerarioId' => 'itin-01',
                    'documentExpiration' => '2026-12-15',
                    default => null,
                };
            });

        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-01')
            ->willReturn($itinerarioStub);

        $hook = new DocumentValidation($entityManagerMock);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('El documento del pasajero expira antes del término del itinerario (2026-12-15). Ingrese un documento vigente.');

        $hook->beforeSave($passengerStub);
    }

    /**
     * Valida que un documento con vigencia posterior al fin del itinerario sea aceptado exitosamente.
     */
    public function testPassengerDocumentValidAllowsSave(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $itinerarioStub = $this->createStub(Entity::class);
        $passengerStub = $this->createStub(Entity::class);

        $itinerarioStub->method('get')
            ->willReturn('2026-12-15');

        $passengerStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'itinerarioId' => 'itin-01',
                    'documentExpiration' => '2027-06-01',
                    default => null,
                };
            });

        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-01')
            ->willReturn($itinerarioStub);

        $hook = new DocumentValidation($entityManagerMock);

        $hook->beforeSave($passengerStub);
        $this->assertTrue(true, 'La validación permitió guardar el documento vigente sin arrojar excepciones');
    }

    /**
     * Valida que un pasajero no vinculado a un itinerario aún no genere errores al guardarse.
     */
    public function testPassengerWithoutItinerarioAllowsSave(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $passengerStub = $this->createStub(Entity::class);

        $passengerStub->method('get')
            ->willReturn(null);

        $entityManagerMock->expects($this->never())
            ->method('getEntity');

        $hook = new DocumentValidation($entityManagerMock);

        $hook->beforeSave($passengerStub);
        $this->assertTrue(true, 'Pasajero sin itinerario omitió la validación sin errores');
    }

    /**
     * Valida que si el pasajero no tiene fecha de expiración registrada, no se lance excepción.
     */
    public function testPassengerWithoutExpirationAllowsSave(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $itinerarioStub = $this->createStub(Entity::class);
        $passengerStub = $this->createStub(Entity::class);

        $itinerarioStub->method('get')
            ->willReturn('2026-12-15');

        $passengerStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'itinerarioId' => 'itin-01',
                    'documentExpiration' => null,
                    default => null,
                };
            });

        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-01')
            ->willReturn($itinerarioStub);

        $hook = new DocumentValidation($entityManagerMock);

        $hook->beforeSave($passengerStub);
        $this->assertTrue(true, 'Pasajero sin expiración no bloquea el guardado prematuramente');
    }
}
