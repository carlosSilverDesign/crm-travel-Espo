<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Itinerario\GeneratePublicToken;
use Espo\ORM\Entity;

class ItinerarioTokenHookTest extends TestCase
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Valida que ante una nueva entidad con publicAccessToken nulo,
     * el hook genere y asigne automáticamente un UUIDv4 conforme a RFC 4122.
     */
    public function testGeneratesUuidV4WhenTokenIsNull(): void
    {
        $entityMock = $this->createMock(Entity::class);

        $entityMock->expects($this->once())
            ->method('get')
            ->with('publicAccessToken')
            ->willReturn(null);

        $assignedToken = null;
        $entityMock->expects($this->once())
            ->method('set')
            ->with('publicAccessToken', $this->callback(function (string $token) use (&$assignedToken) {
                $assignedToken = $token;
                return (bool) preg_match(self::UUID_V4_REGEX, $token);
            }));

        $hook = new GeneratePublicToken();
        $hook->beforeSave($entityMock);

        $this->assertNotNull($assignedToken, 'El token no debe ser nulo.');
        $this->assertEquals(36, strlen($assignedToken), 'El UUIDv4 debe tener exactamente 36 caracteres.');
    }

    /**
     * Valida que si el publicAccessToken viene como string vacío,
     * el hook genere igualmente un nuevo UUIDv4.
     */
    public function testGeneratesUuidV4WhenTokenIsEmptyString(): void
    {
        $entityMock = $this->createMock(Entity::class);

        $entityMock->expects($this->once())
            ->method('get')
            ->with('publicAccessToken')
            ->willReturn('');

        $entityMock->expects($this->once())
            ->method('set')
            ->with('publicAccessToken', $this->matchesRegularExpression(self::UUID_V4_REGEX));

        $hook = new GeneratePublicToken();
        $hook->beforeSave($entityMock);
    }

    /**
     * Valida que ante una entidad existente que ya posee un token asignado,
     * el hook preserve estrictamente el valor para no romper URLs compartidas.
     */
    public function testPreservesExistingTokenOnUpdate(): void
    {
        $existingToken = 'a1b2c3d4-e5f6-4a7b-8c9d-0123456789ab';

        $entityMock = $this->createMock(Entity::class);

        $entityMock->expects($this->once())
            ->method('get')
            ->with('publicAccessToken')
            ->willReturn($existingToken);

        // No debe invocarse set() para sobrescribir
        $entityMock->expects($this->never())
            ->method('set');

        $hook = new GeneratePublicToken();
        $hook->beforeSave($entityMock);
    }
}
