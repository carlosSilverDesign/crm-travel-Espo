<?php

namespace Espo\Core\Controllers;

if (!class_exists(\Espo\Core\Controllers\Base::class)) {
    abstract class Base
    {
        public function __construct(
            protected ?\Espo\Core\Container $container = null,
            protected ?\Espo\Core\ORM\EntityManager $entityManager = null,
            protected ?\Espo\Core\Utils\Config $config = null
        ) {}

        public function getContainer(): ?\Espo\Core\Container
        {
            return $this->container;
        }

        public function getEntityManager(): ?\Espo\Core\ORM\EntityManager
        {
            return $this->entityManager;
        }

        public function getConfig(): ?\Espo\Core\Utils\Config
        {
            return $this->config;
        }
    }
}

namespace Espo\Custom\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Custom\Services\RealProfitabilityService;
use Espo\Custom\Services\PipelineConversionService;
use Espo\Custom\Services\ChatwootReportingService;
use Espo\Custom\Services\DestinationQualityService;

/**
 * Analytics REST Controller (TASK-052)
 *
 * Expone la API REST analítica interna para cuadros de mando y exportaciones:
 * 1. GET /api/v1/Analytics/pipeline-conversion
 * 2. GET /api/v1/Analytics/real-profitability
 * 3. GET /api/v1/Analytics/agent-performance
 * 4. GET /api/v1/Analytics/destination-quality
 *
 * Soporta descargas directas en CSV mediante ?format=csv o endpoints dedicados export-*.
 */
class Analytics extends Base
{
    private ?User $user = null;
    private ?Acl $acl = null;

    private ?RealProfitabilityService $profitabilityService = null;
    private ?PipelineConversionService $conversionService = null;
    private ?ChatwootReportingService $chatwootService = null;
    private ?DestinationQualityService $destinationQualityService = null;

    public function __construct(
        ?Container $container = null,
        ?EntityManager $entityManager = null,
        ?Config $config = null,
        ?User $user = null,
        ?Acl $acl = null
    ) {
        parent::__construct($container, $entityManager, $config);
        $this->user = $user;
        $this->acl = $acl;
    }

    public function getUser(): User
    {
        if ($this->user === null) {
            $container = $this->getContainer();
            if ($container && $container->has('user')) {
                $this->user = $container->get('user');
            }
        }

        if ($this->user === null) {
            throw new Forbidden("No hay sesión de usuario activa para consultar la analítica.");
        }

        return $this->user;
    }

    public function getAcl(): ?Acl
    {
        if ($this->acl === null) {
            $container = $this->getContainer();
            if ($container && $container->has('acl')) {
                $this->acl = $container->get('acl');
            }
        }

        return $this->acl;
    }

    /**
     * Valida control de acceso RBAC para roles administrativos y de gerencia.
     */
    public function checkPermission(): void
    {
        $user = $this->getUser();

        if ($user->isAdmin()) {
            return;
        }

        $roles = $user->get('roles');
        if ($roles) {
            foreach ($roles as $role) {
                $roleName = strtolower((string) ($role->get('name') ?? ''));
                if (
                    str_contains($roleName, 'admin') ||
                    str_contains($roleName, 'geren') ||
                    str_contains($roleName, 'manager') ||
                    str_contains($roleName, 'director') ||
                    str_contains($roleName, 'finan')
                ) {
                    return;
                }
            }
        }

        $acl = $this->getAcl();
        if ($acl && $acl->check('Opportunity', 'read')) {
            return;
        }

        throw new Forbidden("Acceso denegado. Se requieren permisos de Administración, Dirección o Gerencia para consultar la analítica.");
    }

    // =========================================================================
    // Lazy Loaders de Servicios Analíticos
    // =========================================================================

    public function getProfitabilityService(): RealProfitabilityService
    {
        if ($this->profitabilityService === null) {
            $this->profitabilityService = new RealProfitabilityService($this->getEntityManager());
        }
        return $this->profitabilityService;
    }

    public function setProfitabilityService(RealProfitabilityService $service): void
    {
        $this->profitabilityService = $service;
    }

    public function getConversionService(): PipelineConversionService
    {
        if ($this->conversionService === null) {
            $this->conversionService = new PipelineConversionService($this->getEntityManager());
        }
        return $this->conversionService;
    }

    public function setConversionService(PipelineConversionService $service): void
    {
        $this->conversionService = $service;
    }

    public function getChatwootService(): ChatwootReportingService
    {
        if ($this->chatwootService === null) {
            $this->chatwootService = new ChatwootReportingService($this->getEntityManager(), $this->getConfig());
        }
        return $this->chatwootService;
    }

    public function setChatwootService(ChatwootReportingService $service): void
    {
        $this->chatwootService = $service;
    }

    public function getDestinationQualityService(): DestinationQualityService
    {
        if ($this->destinationQualityService === null) {
            $this->destinationQualityService = new DestinationQualityService($this->getEntityManager());
        }
        return $this->destinationQualityService;
    }

    public function setDestinationQualityService(DestinationQualityService $service): void
    {
        $this->destinationQualityService = $service;
    }

    // =========================================================================
    // Endpoints REST de Analítica
    // =========================================================================

    /**
     * 1. GET /api/v1/Analytics/pipeline-conversion
     */
    public function getActionPipelineConversion(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);

        if (($params['format'] ?? null) === 'csv') {
            return $this->outputCsv(
                $this->getConversionService()->exportCsv($params),
                'reporte-conversion-pipeline.csv'
            );
        }

        return $this->getConversionService()->getPipelineConversion($params);
    }

    public function actionPipelineConversion(...$args): mixed
    {
        return $this->getActionPipelineConversion(...$args);
    }

    /**
     * 2. GET /api/v1/Analytics/real-profitability
     */
    public function getActionRealProfitability(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);

        if (($params['format'] ?? null) === 'csv') {
            return $this->outputCsv(
                $this->getProfitabilityService()->exportCsv($params),
                'reporte-rentabilidad-real.csv'
            );
        }

        return $this->getProfitabilityService()->getRealProfitability($params);
    }

    public function actionRealProfitability(...$args): mixed
    {
        return $this->getActionRealProfitability(...$args);
    }

    /**
     * 3. GET /api/v1/Analytics/agent-performance
     */
    public function getActionAgentPerformance(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);

        if (($params['format'] ?? null) === 'csv') {
            return $this->outputCsv(
                $this->getChatwootService()->exportCsv($params),
                'reporte-rendimiento-asesores.csv'
            );
        }

        return $this->getChatwootService()->getAgentPerformance($params);
    }

    public function actionAgentPerformance(...$args): mixed
    {
        return $this->getActionAgentPerformance(...$args);
    }

    /**
     * 4. GET /api/v1/Analytics/destination-quality
     */
    public function getActionDestinationQuality(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);

        if (($params['format'] ?? null) === 'csv') {
            return $this->outputCsv(
                $this->getDestinationQualityService()->exportCsv($params),
                'reporte-calidad-contingencias.csv'
            );
        }

        return $this->getDestinationQualityService()->getDestinationQuality($params);
    }

    public function actionDestinationQuality(...$args): mixed
    {
        return $this->getActionDestinationQuality(...$args);
    }

    // =========================================================================
    // Endpoints dedicados para descarga de reportes CSV
    // =========================================================================

    public function getActionExportRealProfitability(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);
        return $this->outputCsv(
            $this->getProfitabilityService()->exportCsv($params),
            'reporte-rentabilidad-real.csv'
        );
    }

    public function actionExportRealProfitability(...$args): mixed
    {
        return $this->getActionExportRealProfitability(...$args);
    }

    public function getActionExportPipelineConversion(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);
        return $this->outputCsv(
            $this->getConversionService()->exportCsv($params),
            'reporte-conversion-pipeline.csv'
        );
    }

    public function actionExportPipelineConversion(...$args): mixed
    {
        return $this->getActionExportPipelineConversion(...$args);
    }

    public function getActionExportAgentPerformance(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);
        return $this->outputCsv(
            $this->getChatwootService()->exportCsv($params),
            'reporte-rendimiento-asesores.csv'
        );
    }

    public function actionExportAgentPerformance(...$args): mixed
    {
        return $this->getActionExportAgentPerformance(...$args);
    }

    public function getActionExportDestinationQuality(...$args): mixed
    {
        $this->checkPermission();
        $params = $this->extractQueryParams($args);
        return $this->outputCsv(
            $this->getDestinationQualityService()->exportCsv($params),
            'reporte-calidad-contingencias.csv'
        );
    }

    public function actionExportDestinationQuality(...$args): mixed
    {
        return $this->getActionExportDestinationQuality(...$args);
    }

    // =========================================================================
    // Helpers de Solicitud y Descarga
    // =========================================================================

    protected function extractQueryParams(array $args): array
    {
        $params = [];

        foreach ($args as $arg) {
            if ($arg instanceof Request) {
                $qp = $arg->getQueryParams();
                if (is_array($qp)) {
                    $params = array_merge($params, $qp);
                }
            } elseif (is_array($arg)) {
                $params = array_merge($params, $arg);
            }
        }

        if (empty($params) && !empty($_GET)) {
            $params = $_GET;
        }

        return $params;
    }

    protected function outputCsv(string $csv, string $filename): mixed
    {
        if (!headers_sent() && php_sapi_name() !== 'cli') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($csv));
            header('Cache-Control: private, must-revalidate, max-age=0');
            header('Pragma: public');
            echo $csv;
            exit;
        }

        return [
            'filename' => $filename,
            'contentType' => 'text/csv; charset=UTF-8',
            'csv' => $csv,
        ];
    }
}
