<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DashletsMetadataTest extends TestCase
{
    private string $dashletsDir;

    protected function setUp(): void
    {
        $this->dashletsDir = __DIR__ . '/../../Resources/metadata/dashlets';
    }

    public function testExpectedDashletsExistAndAreValidJson(): void
    {
        $expectedDashlets = [
            'PipelineFunnel',
            'RealProfitability',
            'AgentPerformance',
            'DestinationQuality',
        ];

        foreach ($expectedDashlets as $name) {
            $path = $this->dashletsDir . '/' . $name . '.json';
            $this->assertFileExists($path, "El archivo de dashlet {$name}.json debe existir.");

            $rawContent = file_get_contents($path);
            $this->assertNotFalse($rawContent, "No se pudo leer el archivo {$name}.json.");

            $data = json_decode($rawContent, true);
            $this->assertNotNull($data, "El archivo {$name}.json debe contener JSON válido: " . json_last_error_msg());

            $this->assertArrayHasKey('view', $data, "El dashlet {$name} debe tener una vista definida ('view').");
            $this->assertStringStartsWith('custom:views/dashlets/', $data['view'], "La vista del dashlet {$name} debe residir en 'custom:views/dashlets/'.");
            $this->assertArrayHasKey('aclScope', $data, "El dashlet {$name} debe declarar 'aclScope' para RBAC.");
            $this->assertArrayHasKey('options', $data, "El dashlet {$name} debe definir la estructura de opciones.");
        }
    }

    public function testDefaultDashboardLayoutsIntegratesAnalytics(): void
    {
        $path = __DIR__ . '/../../Resources/metadata/app/defaultDashboardLayouts.json';
        $this->assertFileExists($path);

        $data = json_decode(file_get_contents($path), true);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('Standard', $data);

        $tabNames = array_column($data['Standard'], 'name');
        $this->assertContains('Analítica de Negocio', $tabNames, "El dashboard por defecto debe contener la pestaña 'Analítica de Negocio'.");
    }

    public function testDashletViewsExistInClientCustom(): void
    {
        $clientDashletsDir = __DIR__ . '/../../../../../client/custom/modules/travel/views/dashlets';
        
        $views = [
            'pipeline-funnel.js',
            'real-profitability.js',
            'agent-performance.js',
            'destination-quality.js',
        ];

        foreach ($views as $view) {
            $path = $clientDashletsDir . '/' . $view;
            $this->assertFileExists($path, "El archivo de vista cliente {$view} debe existir.");
            $content = file_get_contents($path);
            $this->assertStringContainsString('define(', $content, "La vista {$view} debe usar definición AMD.");
            $this->assertStringContainsString('download-csv-btn', $content, "La vista {$view} debe incluir el botón de descarga CSV.");
        }
    }
}
