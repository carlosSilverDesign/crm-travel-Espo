<?php

namespace Espo\Core\Controllers;

if (!class_exists(\Espo\Core\Controllers\Base::class)) {
    abstract class Base
    {
        public function __construct(
            protected \Espo\Core\Container $container,
            protected \Espo\Core\ORM\EntityManager $entityManager,
            protected \Espo\Core\Utils\Config $config
        ) {}

        public function getContainer(): \Espo\Core\Container
        {
            return $this->container;
        }

        public function getEntityManager(): \Espo\Core\ORM\EntityManager
        {
            return $this->entityManager;
        }

        public function getConfig(): \Espo\Core\Utils\Config
        {
            return $this->config;
        }
    }
}

namespace Espo\Custom\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Custom\Services\ItinerarioPdfService;

/**
 * Controlador de API para la generación y descarga del expediente de viaje en PDF.
 * Mapeado a la ruta POST /Itinerario/action/generatePdf
 */
class ItinerarioPdfController extends Base
{
    private ?ItinerarioPdfService $pdfService = null;

    public function __construct(
        Container $container,
        EntityManager $entityManager,
        Config $config,
        ?ItinerarioPdfService $pdfService = null
    ) {
        parent::__construct($container, $entityManager, $config);
        $this->pdfService = $pdfService;
    }

    public function getPdfService(): ItinerarioPdfService
    {
        if ($this->pdfService === null) {
            $container = $this->getContainer();
            $acl = $container->has('acl') ? $container->get('acl') : null;
            $user = $container->has('user') ? $container->get('user') : null;

            $this->pdfService = new ItinerarioPdfService(
                $this->getEntityManager(),
                $this->getConfig(),
                $acl,
                $user,
                $container
            );
        }
        return $this->pdfService;
    }

    /**
     * Acción POST para generar o retornar desde caché el PDF del expediente.
     * Soporta diferentes convenciones de inyección de parámetros según el kernel de EspoCRM.
     *
     * @param mixed ...$args Parámetros variables del despachador ($params, $data, $request, $response)
     * @return mixed Stream binario o respuesta HTTP formateada
     */
    public function postActionGeneratePdf(...$args)
    {
        return $this->handleGeneratePdf($args);
    }

    /**
     * Alias de compatibilidad para enrutadores que invocan 'actionGeneratePdf'.
     */
    public function actionGeneratePdf(...$args)
    {
        return $this->handleGeneratePdf($args);
    }

    /**
     * Procesa la solicitud, delega al servicio y establece los encabezados de respuesta adecuados.
     */
    private function handleGeneratePdf(array $args)
    {
        $itinerarioId = $this->extractItinerarioId($args);

        if (empty($itinerarioId)) {
            throw new BadRequest("El parámetro 'itinerarioId' (o 'id') es requerido para generar el PDF.");
        }

        $result = $this->getPdfService()->generatePdf($itinerarioId);

        $filename = $result['filename'] ?? ("Expediente-{$itinerarioId}.pdf");
        $content = $result['content'] ?? '';

        // Encabezados HTTP defensivos
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, must-revalidate, max-age=0');
            header('Pragma: public');
        }

        // Si se recibió un objeto de respuesta PSR-7 / Slim
        foreach ($args as $arg) {
            if (is_object($arg) && method_exists($arg, 'getBody') && method_exists($arg, 'withHeader')) {
                $arg->getBody()->write($content);
                return $arg
                    ->withHeader('Content-Type', 'application/pdf')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Content-Length', (string) strlen($content));
            }
        }

        return $content;
    }

    /**
     * Extrae de forma agnóstica el identificador del itinerario desde argumentos de ruta, cuerpo o query.
     */
    private function extractItinerarioId(array $args): ?string
    {
        foreach ($args as $arg) {
            if (is_array($arg)) {
                if (!empty($arg['itinerarioId'])) return (string) $arg['itinerarioId'];
                if (!empty($arg['id'])) return (string) $arg['id'];
            }
            if (is_object($arg)) {
                if (method_exists($arg, 'getParsedBody')) {
                    $body = $arg->getParsedBody();
                    if (is_array($body)) {
                        if (!empty($body['itinerarioId'])) return (string) $body['itinerarioId'];
                        if (!empty($body['id'])) return (string) $body['id'];
                    }
                }
                if (method_exists($arg, 'getQueryParams')) {
                    $params = $arg->getQueryParams();
                    if (is_array($params)) {
                        if (!empty($params['itinerarioId'])) return (string) $params['itinerarioId'];
                        if (!empty($params['id'])) return (string) $params['id'];
                    }
                }
                if (isset($arg->itinerarioId)) return (string) $arg->itinerarioId;
                if (isset($arg->id)) return (string) $arg->id;
            }
        }

        if (!empty($_POST['itinerarioId'])) return (string) $_POST['itinerarioId'];
        if (!empty($_POST['id'])) return (string) $_POST['id'];
        if (!empty($_GET['itinerarioId'])) return (string) $_GET['itinerarioId'];
        if (!empty($_GET['id'])) return (string) $_GET['id'];

        return null;
    }
}
