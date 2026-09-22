<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Services\ItinerarioPdfService;
use Espo\Custom\Controllers\ItinerarioPdfController;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Acl;
use Espo\Core\Container;
use Espo\ORM\Entity;

class ItinerarioPdfServiceTest extends TestCase
{
    /**
     * Valida que si el itinerario no existe en base de datos,
     * el servicio arroje una excepción NotFound (HTTP 404).
     */
    public function testNotFoundThrowsException(): void
    {
        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-999')
            ->willReturn(null);

        $configStub = $this->createStub(Config::class);

        $service = new ItinerarioPdfService($entityManagerMock, $configStub);

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage("El itinerario con identificador 'itin-999' no fue encontrado.");

        $service->generatePdf('itin-999');
    }

    /**
     * Valida que si el usuario no tiene permisos ACL de lectura sobre el itinerario,
     * el servicio arroje una excepción Forbidden (HTTP 403).
     */
    public function testAclForbiddenThrowsException(): void
    {
        $itinerarioStub = $this->createStub(Entity::class);

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->once())
            ->method('getEntity')
            ->with('Itinerario', 'itin-001')
            ->willReturn($itinerarioStub);

        $configStub = $this->createStub(Config::class);

        $aclMock = $this->createMock(Acl::class);
        $aclMock->expects($this->once())
            ->method('check')
            ->with($itinerarioStub, 'read')
            ->willReturn(false);

        $service = new ItinerarioPdfService($entityManagerMock, $configStub, $aclMock);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage("No cuenta con permisos suficientes para acceder a este itinerario.");

        $service->generatePdf('itin-001');
    }

    /**
     * Valida la estrategia de caché (Umbral de Doherty < 200 ms):
     * Si la entidad cuenta con un Attachment en pdfCacheFileId y el itinerario no ha sido
     * modificado con posterioridad, se retorna de inmediato el buffer binario en caché.
     */
    public function testReturnsCachedPdfWhenValid(): void
    {
        $itinerarioStub = $this->createStub(Entity::class);
        $itinerarioStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'id' => 'itin-001',
                    'pdfCacheFileId' => 'att-123',
                    'modifiedAt' => '2026-09-20 10:00:00',
                    default => null,
                };
            });
        $itinerarioStub->method('getId')->willReturn('itin-001');

        $attachmentStub = $this->createStub(Entity::class);
        $attachmentStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'id' => 'att-123',
                    'name' => 'Expediente-Cancun.pdf',
                    'createdAt' => '2026-09-21 15:00:00', // Posterior a modifiedAt -> Caché vigente
                    default => null,
                };
            });
        $attachmentStub->method('getId')->willReturn('att-123');

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->expects($this->exactly(2))
            ->method('getEntity')
            ->willReturnCallback(function (string $entityType, string $id) use ($itinerarioStub, $attachmentStub) {
                if ($entityType === 'Itinerario' && $id === 'itin-001') {
                    return $itinerarioStub;
                }
                if ($entityType === 'Attachment' && $id === 'att-123') {
                    return $attachmentStub;
                }
                return null;
            });

        $configStub = $this->createStub(Config::class);

        // Simulamos un servicio con getAttachmentContents sobreescrito para no requerir archivos físicos en el test
        $service = new class($entityManagerMock, $configStub) extends ItinerarioPdfService {
            protected function getAttachmentContents(Entity $attachment): ?string
            {
                return "%PDF-1.4 Mock Binary Buffer Content";
            }
        };

        $result = $service->generatePdf('itin-001');

        $this->assertTrue($result['fromCache'], 'El resultado debe provenir de la caché');
        $this->assertEquals('Expediente-Cancun.pdf', $result['filename']);
        $this->assertEquals('%PDF-1.4 Mock Binary Buffer Content', $result['content']);
        $this->assertEquals('att-123', $result['attachmentId']);
    }

    /**
     * Valida el Principio de Robustez (Ley de Postel) y Heurística 9:
     * Si el microservicio Node.js falla o no responde, se arroja ServiceUnavailable (503)
     * con mensaje amigable sin generar un Fatal Error en PHP.
     */
    public function testMicroserviceFailureThrowsServiceUnavailable(): void
    {
        $itinerarioStub = $this->createStub(Entity::class);
        $itinerarioStub->method('get')
            ->willReturnCallback(function (string $field) {
                return match ($field) {
                    'id' => 'itin-002',
                    'pdfCacheFileId' => null, // Sin caché
                    'publicAccessToken' => 'd8a4e8d3-5689-4d6f-9988-aabbccddeeff',
                    'name' => 'Viaje a Roma',
                    default => null,
                };
            });
        $itinerarioStub->method('getId')->willReturn('itin-002');

        $entityManagerStub = $this->createStub(EntityManager::class);
        $entityManagerStub->method('getEntity')->willReturn($itinerarioStub);

        $configStub = $this->createStub(Config::class);
        $configStub->method('get')
            ->willReturnCallback(function (string $key) {
                return match ($key) {
                    // Puerto ficticio no alcanzable para disparar error de conexión
                    'pdfServiceUrl' => 'http://127.0.0.1:49999/api/v1/generate',
                    'pdfServiceSecret' => 'secret_xyz',
                    default => null,
                };
            });

        $service = new ItinerarioPdfService($entityManagerStub, $configStub);

        $this->expectException(ServiceUnavailable::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('El servicio de generación de PDF no está disponible temporalmente');

        $service->generatePdf('itin-002');
    }

    /**
     * Valida que el controlador extraiga adecuadamente el itinerarioId
     * y delegue la generación al servicio.
     */
    public function testControllerDelegatesAndReturnsContent(): void
    {
        $containerMock = $this->createStub(Container::class);
        $entityManagerMock = $this->createStub(EntityManager::class);
        $configMock = $this->createStub(Config::class);

        $serviceMock = $this->createMock(ItinerarioPdfService::class);
        $serviceMock->expects($this->once())
            ->method('generatePdf')
            ->with('itin-100')
            ->willReturn([
                'content' => '%PDF-1.4 Fake PDF Content',
                'filename' => 'Expediente-itin-100.pdf',
                'mimeType' => 'application/pdf',
                'fromCache' => false,
                'attachmentId' => 'att-999',
            ]);

        $controller = new ItinerarioPdfController(
            $containerMock,
            $entityManagerMock,
            $configMock,
            $serviceMock
        );

        $output = $controller->postActionGeneratePdf(['itinerarioId' => 'itin-100']);

        $this->assertEquals('%PDF-1.4 Fake PDF Content', $output);
    }
}
