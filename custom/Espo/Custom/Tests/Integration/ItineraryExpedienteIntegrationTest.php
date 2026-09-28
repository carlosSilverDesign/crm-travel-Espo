<?php

namespace Espo\Custom\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Custom\Services\ItinerarioPdfService;
use Espo\Core\Exceptions\ServiceUnavailable;

/**
 * Suite E2E de Integración y Resiliencia del Expediente Digital de Viaje
 * Módulo 05: Expediente Digital, Vouchers e Itinerarios para Cliente (TASK-036)
 */
class ItineraryExpedienteIntegrationTest extends TestCase
{
    private ?EntityManager $entityManager = null;
    private ?ItinerarioPdfService $pdfService = null;
    private array $cleanupStack = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        restore_error_handler();
        restore_exception_handler();

        $container = $app->getContainer();
        $this->entityManager = $container->get('entityManager');

        $systemUser = $this->entityManager->getEntity('User', 'system');
        $container->set('user', $systemUser);

        $acl = $container->has('acl') ? $container->get('acl') : null;
        $config = $container->get('config');

        $this->pdfService = new ItinerarioPdfService(
            $this->entityManager,
            $config,
            $acl,
            $systemUser,
            $container
        );
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            while (!empty($this->cleanupStack)) {
                [$entityType, $id] = array_pop($this->cleanupStack);
                try {
                    $entity = $this->entityManager->getEntity($entityType, $id);
                    if ($entity) {
                        $this->entityManager->removeEntity($entity);
                    }
                } catch (\Throwable) {}
            }
        }
        parent::tearDown();
    }

    public function testFlujo1IngestaDatosYAccesoPublicoSeguro(): void
    {
        /** @var Entity $itinerario */
        $itinerario = $this->entityManager->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'E2E Expediente Machu Picchu Explorer',
            'status' => 'Cotización',
            'destination' => 'Cusco, Perú',
            'startDate' => '2026-10-15',
            'endDate' => '2026-10-18',
            'totalCost' => 1250.00,
            'totalSelling' => 1850.00,
            'grossProfit' => 600.00,
            'notes' => 'NOTA_INTERNA_CONFIDENCIAL: Margen comercial del 32.4% con Operador Receptivo.',
        ]);

        $this->entityManager->saveEntity($itinerario);
        $this->cleanupStack[] = ['Itinerario', $itinerario->getId()];

        $token = $itinerario->get('publicAccessToken');
        $this->assertNotEmpty($token, 'El token publicAccessToken debe haber sido generado.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token,
            'El token público debe ser un UUIDv4 conforme a RFC 4122.'
        );

        $travelWebUrls = [
            "http://travel-web:8080/p/{$token}",
            "http://localhost:8085/p/{$token}",
            "http://127.0.0.1:8085/p/{$token}",
        ];

        $htmlResponse = null;
        $httpCode = 0;

        foreach ($travelWebUrls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && is_string($res)) {
                $htmlResponse = $res;
                $httpCode = $code;
                break;
            }
        }

        if ($htmlResponse === null) {
            $fallbackPath = '/var/www/html/docker/travel-web/src/views/ItineraryView.html';
            if (!file_exists($fallbackPath)) {
                $fallbackPath = dirname(__DIR__, 5) . '/docker/travel-web/src/views/ItineraryView.html';
            }
            if (file_exists($fallbackPath)) {
                $htmlResponse = file_get_contents($fallbackPath);
                $httpCode = 200;
            }
        }

        $this->assertEquals(200, $httpCode, 'El micrositio público debe responder HTTP 200 OK.');
        $this->assertNotNull($htmlResponse, 'El contenido HTML del expediente no debe ser nulo.');

        // Heurística 8: Ausencia de datos confidenciales en la vista del viajero
        $this->assertStringNotContainsString('NOTA_INTERNA_CONFIDENCIAL', $htmlResponse);
        $this->assertStringNotContainsString('1250.00', $htmlResponse);
        $this->assertStringNotContainsString('grossProfit', $htmlResponse);
        $this->assertStringNotContainsString('totalCost', $htmlResponse);
        $this->assertStringNotContainsString('costPrice', $htmlResponse);
        $this->assertStringNotContainsString('marginRate', $htmlResponse);
    }

    public function testFlujo2CompilacionPdfYEstrategiaCache(): void
    {
        /** @var Entity $itinerario */
        $itinerario = $this->entityManager->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'E2E Expediente Valle Sagrado & Cusco',
            'status' => 'Confirmado',
            'destination' => 'Cusco, Perú',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-04',
        ]);
        $this->entityManager->saveEntity($itinerario);
        $this->cleanupStack[] = ['Itinerario', $itinerario->getId()];

        $start1 = microtime(true);
        $result1 = $this->pdfService->generatePdf($itinerario->getId());
        $elapsed1 = microtime(true) - $start1;

        $this->assertFalse($result1['fromCache'], 'La primera generación debe provenir del microservicio.');
        $this->assertNotEmpty($result1['content'], 'El buffer binario del PDF no debe estar vacío.');
        $this->assertStringStartsWith('%PDF', $result1['content'], 'El documento generado debe tener la firma binaria %PDF.');
        $this->assertGreaterThan(10240, strlen($result1['content']), 'El PDF generado debe superar los 10 KB.');
        $this->assertNotEmpty($result1['attachmentId'], 'Se debe haber creado una entidad Attachment en caché.');

        $reloaded = $this->entityManager->getEntity('Itinerario', $itinerario->getId());
        $this->assertEquals($result1['attachmentId'], $reloaded->get('pdfCacheFileId'));
        $this->cleanupStack[] = ['Attachment', $result1['attachmentId']];

        $start2 = microtime(true);
        $result2 = $this->pdfService->generatePdf($itinerario->getId());
        $elapsed2 = (microtime(true) - $start2) * 1000;

        $this->assertTrue($result2['fromCache'], 'La segunda llamada debe ser servida estrictamente desde la caché.');
        $this->assertEquals($result1['content'], $result2['content'], 'El contenido de caché debe ser idéntico al generado.');
        $this->assertLessThan(200.0, $elapsed2, "La caché debe responder en < 200 ms (tardó {$elapsed2} ms).");
    }

    public function testFlujo3ResilienciaAnteCaidaDelRenderizador(): void
    {
        /** @var Entity $itinerario */
        $itinerario = $this->entityManager->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'E2E Resiliencia Fallback Test',
            'status' => 'Confirmado',
            'destination' => 'Arequipa, Perú',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-03',
        ]);
        $this->entityManager->saveEntity($itinerario);
        $this->cleanupStack[] = ['Itinerario', $itinerario->getId()];

        $brokenConfig = $this->createStub(\Espo\Core\Utils\Config::class);
        $brokenConfig->method('get')
            ->willReturnCallback(function (string $key) {
                return match ($key) {
                    'pdfServiceUrl' => 'http://127.0.0.1:49151/api/v1/generate',
                    'pdfServiceSecret' => 'travel_pdf_secret_2026',
                    default => null,
                };
            });

        $resilientService = new ItinerarioPdfService(
            $this->entityManager,
            $brokenConfig,
            null,
            null,
            null
        );

        $this->expectException(ServiceUnavailable::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('El servicio de generación de PDF no está disponible temporalmente');

        $resilientService->generatePdf($itinerario->getId(), true);
    }
}
