<?php

namespace Espo\Custom\Services;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Acl;
use Espo\Entities\User;
use Espo\Core\Container;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Ramsey\Uuid\Uuid;

/**
 * Servicio para la generación y gestión de caché del expediente digital en PDF.
 *
 * Principios aplicados:
 * - Umbral de Doherty: Caché en disco/ORM para responder en <200 ms tras la primera generación.
 * - Ley de Postel & Heurística 9: Degradación elegante ante caídas de microservicios con HTTP 503 claro.
 * - Ley de Tesler: Asume la orquestación criptográfica, comprobación de vigencia y almacenamiento binario.
 */
class ItinerarioPdfService
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ?Acl $acl = null,
        private ?User $user = null,
        private ?Container $container = null,
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    protected function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Genera o recupera desde caché el archivo binario del expediente en PDF.
     *
     * @param string $itinerarioId ID del registro Itinerario
     * @param bool $forceRegenerate Si se desea forzar la regeneración ignorando la caché
     * @return array{content: string, filename: string, mimeType: string, fromCache: bool, attachmentId: ?string}
     */
    public function generatePdf(string $itinerarioId, bool $forceRegenerate = false): array
    {
        // 1. Obtener entidad Itinerario
        $itinerario = $this->getEntityManager()->getEntity('Itinerario', $itinerarioId);
        if (!$itinerario) {
            throw new NotFound("El itinerario con identificador '{$itinerarioId}' no fue encontrado.");
        }

        // 2. Validación estricta de permisos de lectura (ACL)
        $this->assertCanRead($itinerario);

        // 3. Verificación de Caché (Doherty Threshold < 200 ms)
        if (!$forceRegenerate) {
            $cachedResult = $this->getCachedPdf($itinerario);
            if ($cachedResult !== null) {
                return $cachedResult;
            }
        }

        // 4. Asegurar existencia de publicAccessToken (UUIDv4)
        $token = (string) $itinerario->get('publicAccessToken');
        if (empty($token) || trim($token) === '') {
            $token = $this->generateUuid();
            $itinerario->set('publicAccessToken', $token);
            $this->getEntityManager()->saveEntity($itinerario, ['skipHooks' => true]);
        }

        // 5. Configurar URL interna de renderizado y secreto
        $travelWebUrl = rtrim((string) ($this->getConfig()->get('travelWebUrl') ?: getenv('TRAVEL_WEB_URL') ?: 'http://travel-web:8080'), '/');
        $renderUrl = "{$travelWebUrl}/p/{$token}?print=true";

        $pdfServiceUrl = (string) ($this->getConfig()->get('pdfServiceUrl') ?: getenv('PDF_SERVICE_URL') ?: 'http://pdf-service:3000/api/v1/generate');
        $secret = (string) ($this->getConfig()->get('pdfServiceSecret') ?: getenv('PDF_SERVICE_SECRET') ?: 'travel_pdf_secret_2026');

        // 6. Solicitud HTTP interna con manejo defensivo de fallos (Ley de Postel)
        $pdfBinary = $this->requestPdfFromMicroservice($pdfServiceUrl, $renderUrl, $secret);

        // 7. Persistencia del Attachment en el ORM nativo y actualización de caché
        $filename = 'Expediente-' . $this->sanitizeFilename((string) ($itinerario->get('name') ?: $itinerarioId)) . '.pdf';

        $attachment = $this->persistAttachment($itinerario, $filename, $pdfBinary);

        // Asociar ID de caché a la entidad Itinerario
        $itinerario->set('pdfCacheFileId', $attachment->getId());
        $this->getEntityManager()->saveEntity($itinerario, ['skipHooks' => true]);

        return [
            'content' => $pdfBinary,
            'filename' => $filename,
            'mimeType' => 'application/pdf',
            'fromCache' => false,
            'attachmentId' => $attachment->getId(),
        ];
    }

    /**
     * Valida permisos de lectura del usuario autenticado sobre el Itinerario.
     */
    protected function assertCanRead(Entity $itinerario): void
    {
        if ($this->acl === null) {
            return;
        }

        $canRead = true;
        if (method_exists($this->acl, 'check')) {
            $canRead = $this->acl->check($itinerario, 'read');
        } elseif (method_exists($this->acl, 'checkEntity')) {
            $canRead = $this->acl->checkEntity($itinerario, 'read');
        } elseif (method_exists($this->acl, 'checkScope')) {
            $canRead = $this->acl->checkScope('Itinerario', 'read');
        }

        if (!$canRead) {
            throw new Forbidden("No cuenta con permisos suficientes para acceder a este itinerario.");
        }
    }

    /**
     * Verifica si existe un archivo PDF válido en caché no modificado con posterioridad.
     */
    protected function getCachedPdf(Entity $itinerario): ?array
    {
        $cacheFileId = $itinerario->get('pdfCacheFileId');
        if (empty($cacheFileId)) {
            return null;
        }

        $attachment = $this->getEntityManager()->getEntity('Attachment', $cacheFileId);
        if (!$attachment) {
            return null;
        }

        // Comprobar vigencia temporal: si el itinerario fue editado luego de crear el PDF, la caché se invalida
        $itinerarioModifiedAt = $itinerario->get('modifiedAt');
        $attachmentCreatedAt = $attachment->get('createdAt');

        if (!empty($itinerarioModifiedAt) && !empty($attachmentCreatedAt)) {
            if (strtotime((string) $itinerarioModifiedAt) > strtotime((string) $attachmentCreatedAt)) {
                return null; // Caché obsoleta
            }
        }

        $content = $this->getAttachmentContents($attachment);
        if (empty($content)) {
            return null;
        }

        $filename = (string) ($attachment->get('name') ?: ('Expediente-' . $itinerario->getId() . '.pdf'));

        return [
            'content' => $content,
            'filename' => $filename,
            'mimeType' => 'application/pdf',
            'fromCache' => true,
            'attachmentId' => $attachment->getId(),
        ];
    }

    /**
     * Realiza la llamada HTTP al microservicio Puppeteer con timeout defensivo.
     * Heurística 9: Captura excepciones de red y responde HTTP 503 claro.
     */
    protected function requestPdfFromMicroservice(string $serviceUrl, string $renderUrl, string $secret): string
    {
        $payload = json_encode([
            'url' => $renderUrl,
            'secret' => $secret,
        ]);

        $ch = curl_init($serviceUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $secret,
                'X-PDF-Service-Secret: ' . $secret,
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Fallo a nivel de transporte o conexión de red
        if ($curlErrno !== 0) {
            throw new ServiceUnavailable(
                "El servicio de generación de PDF no está disponible temporalmente ({$curlError}). " .
                "Por favor, comparta el enlace web interactivo con el cliente mientras se restablece el motor de impresión."
            );
        }

        // Respuesta HTTP con error del microservicio
        if ($httpCode !== 200) {
            throw new ServiceUnavailable(
                "El motor de impresión retornó un código de respuesta HTTP {$httpCode}. " .
                "Sugerimos consultar el expediente directamente mediante el enlace web."
            );
        }

        // Validación de integridad básica del binario recibido
        if (!is_string($response) || strlen($response) < 100) {
            throw new BadRequest(
                "El documento generado por el microservicio está incompleto o corrupto. Verifique los datos del itinerario."
            );
        }

        return $response;
    }

    /**
     * Persiste la entidad Attachment en la base de datos y almacena el archivo en disco.
     */
    protected function persistAttachment(Entity $itinerario, string $filename, string $pdfBinary): Entity
    {
        /** @var Entity $attachment */
        $attachment = $this->getEntityManager()->getNewEntity('Attachment');
        $attachment->set([
            'name' => $filename,
            'type' => 'application/pdf',
            'size' => strlen($pdfBinary),
            'role' => 'Attachment',
            'relatedType' => 'Itinerario',
            'relatedId' => $itinerario->getId(),
            'field' => 'pdfCacheFile',
        ]);

        $this->getEntityManager()->saveEntity($attachment);

        // Guardar binario en data/upload/{attachmentId}
        $this->saveAttachmentFile($attachment, $pdfBinary);

        return $attachment;
    }

    /**
     * Escribe el archivo en disco respetando la estructura de almacenamiento de EspoCRM.
     */
    protected function saveAttachmentFile(Entity $attachment, string $content): void
    {
        $id = $attachment->getId();
        $candidates = [
            'data/upload/' . $id,
            '/var/www/html/data/upload/' . $id,
        ];

        foreach ($candidates as $targetPath) {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($targetPath, $content);
        }
    }

    /**
     * Lee el contenido binario del archivo asociado al Attachment.
     */
    protected function getAttachmentContents(Entity $attachment): ?string
    {
        $id = $attachment->getId();

        // Si el repositorio Attachment de EspoCRM tiene un método helper específico
        try {
            $repository = $this->getEntityManager()->getRepository('Attachment');
            if ($repository && method_exists($repository, 'getFilePath')) {
                $path = $repository->getFilePath($attachment);
                if ($path && file_exists($path) && is_readable($path)) {
                    $content = file_get_contents($path);
                    if ($content !== false && strlen($content) > 0) {
                        return $content;
                    }
                }
            }
        } catch (\Throwable) {
            // Continuar con rutas estándar
        }

        $candidates = [
            'data/upload/' . $id,
            '/var/www/html/data/upload/' . $id,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_readable($candidate)) {
                $content = file_get_contents($candidate);
                if ($content !== false && strlen($content) > 0) {
                    return $content;
                }
            }
        }

        return null;
    }

    /**
     * Limpia caracteres no alfanuméricos para generar un nombre de archivo seguro.
     */
    private function sanitizeFilename(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        return trim((string) preg_replace('/_+/', '_', $clean), '_');
    }

    /**
     * Generador seguro de UUIDv4 con fallback RFC 4122.
     */
    private function generateUuid(): string
    {
        if (class_exists(Uuid::class)) {
            return Uuid::uuid4()->toString();
        }

        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
