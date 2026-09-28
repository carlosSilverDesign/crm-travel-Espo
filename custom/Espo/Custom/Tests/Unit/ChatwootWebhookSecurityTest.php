<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Controllers\WebhookChatwoot;
use Espo\Custom\Services\LeadDistributionService;
use Espo\Core\Exceptions\Unauthorized;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Api\Request;
use Espo\ORM\Entity;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Core\Templates\Repositories\Base as RDBRepository;

/**
 * Suite de pruebas unitarias para TASK-024:
 * 1. Compuerta de seguridad y autenticación Bearer del Webhook de Chatwoot.
 * 2. Rotación determinista Round-Robin y fallback defensivo de asignación de leads.
 */
class ChatwootWebhookSecurityTest extends TestCase
{
    // =========================================================================
    // 1. PRUEBAS DE AUTENTICACIÓN DE WEBHOOK (WebhookChatwoot)
    // =========================================================================

    /**
     * Valida que una petición sin encabezado 'Authorization' arroje HTTP 401 Unauthorized.
     */
    public function testMissingAuthorizationHeaderThrowsUnauthorized(): void
    {
        $containerMock = $this->createMock(Container::class);
        $entityManagerMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $configMock->method('get')
            ->with('chatwootWebhookSecret', '')
            ->willReturn('valid_token_123');

        $requestMock = $this->createMock(Request::class);
        $requestMock->method('getHeader')
            ->with('Authorization')
            ->willReturn(null);

        $controller = new WebhookChatwoot($containerMock, $entityManagerMock, $configMock);

        $this->expectException(Unauthorized::class);
        $this->expectExceptionMessage('Unauthorized webhook access.');

        $controller->actionReceive([], [], $requestMock);
    }

    /**
     * Valida que una petición con Bearer token no coincidente sea rechazada mediante hash_equals con 401 Unauthorized.
     */
    public function testInvalidBearerTokenThrowsUnauthorized(): void
    {
        $containerMock = $this->createMock(Container::class);
        $entityManagerMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $configMock->method('get')
            ->with('chatwootWebhookSecret', '')
            ->willReturn('valid_token_123');

        $requestMock = $this->createMock(Request::class);
        $requestMock->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer token_invalido_xyz');

        $controller = new WebhookChatwoot($containerMock, $entityManagerMock, $configMock);

        $this->expectException(Unauthorized::class);
        $this->expectExceptionMessage('Unauthorized webhook access.');

        $controller->actionReceive([], [], $requestMock);
    }

    /**
     * Valida que un Bearer token legítimo permita el procesamiento y retorne HTTP 200 con status 'success'.
     */
    public function testValidBearerTokenProceeds(): void
    {
        $containerMock = $this->createMock(Container::class);
        $entityManagerMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $configMock->method('get')
            ->with('chatwootWebhookSecret', '')
            ->willReturn('valid_token_123');

        $requestMock = $this->createMock(Request::class);
        $requestMock->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer valid_token_123');

        // Mocks de repositorio y entidades para Contact y Opportunity
        $selectBuilderStub = $this->createStub(RDBSelectBuilder::class);
        $selectBuilderStub->method('findOne')->willReturn(null);
        $selectBuilderStub->method('order')->willReturnSelf();
        $selectBuilderStub->method('where')->willReturnSelf();

        $repositoryMock = $this->createMock(RDBRepository::class);
        $repositoryMock->method('where')->willReturn($selectBuilderStub);

        $entityManagerMock->method('getRDBRepository')
            ->willReturn($repositoryMock);

        // Mock entidad Contact
        $contactStub = $this->createStub(Entity::class);
        $contactStub->method('getId')->willReturn('contact-test-101');
        $contactStub->method('hasId')->willReturn(true);
        $contactStub->method('get')->willReturnMap([
            ['name', 'Carlos Viajero'],
            ['firstName', 'Carlos'],
            ['lastName', 'Viajero'],
        ]);

        // Mock entidad Opportunity
        $oppStub = $this->createMock(Entity::class);
        $oppStub->method('getId')->willReturn('opp-test-202');
        $oppStub->method('hasId')->willReturn(true);
        $oppStub->method('get')->willReturnMap([
            ['assignedUserId', 'agent-assigned-007'],
        ]);

        $entityManagerMock->method('getNewEntity')
            ->willReturnMap([
                ['Contact', $contactStub],
                ['Opportunity', $oppStub],
            ]);

        $entityManagerMock->expects($this->exactly(2))
            ->method('saveEntity');

        // Mock del servicio LeadDistributionService
        $leadServiceMock = $this->createMock(LeadDistributionService::class);
        $leadServiceMock->method('getNextAssignedUserId')
            ->willReturn('agent-assigned-007');

        $containerMock->method('has')
            ->with('leadDistributionService')
            ->willReturn(true);
        $containerMock->method('get')
            ->with('leadDistributionService')
            ->willReturn($leadServiceMock);

        $controller = new WebhookChatwoot($containerMock, $entityManagerMock, $configMock);

        $payload = [
            'event' => 'conversation_created',
            'conversation' => [
                'id' => 'conv-100',
            ],
            'meta' => [
                'sender' => [
                    'id' => 'cw-sender-1',
                    'name' => 'Carlos Viajero',
                    'phone_number' => '+51999111222',
                ],
            ],
        ];

        $result = $controller->actionReceive([], $payload, $requestMock);

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertSame('contact-test-101', $result['contactId']);
        $this->assertSame('opp-test-202', $result['opportunityId']);
        $this->assertSame('agent-assigned-007', $result['assignedUserId']);
    }

    // =========================================================================
    // 2. PRUEBAS DEL ALGORITMO ROUND-ROBIN (LeadDistributionService)
    // =========================================================================

    /**
     * Valida que getNextAssignedUserId() rote secuencialmente entre agentes activos:
     * Último 'agent-1' -> asigna 'agent-2' -> en la siguiente asigna 'agent-1'.
     */
    public function testRoundRobinRotatesBetweenActiveAgents(): void
    {
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('fetchAll')->willReturn(['agent-1', 'agent-2']);

        $pdoMock = $this->createMock(\PDO::class);
        $pdoMock->method('query')->willReturn($stmtMock);

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->method('getPDO')->willReturn($pdoMock);

        $configState = 'agent-1';
        $configMock = $this->createMock(Config::class);
        $configMock->method('get')
            ->with('roundRobinLastAgentId')
            ->willReturnCallback(function () use (&$configState) {
                return $configState;
            });
        $configMock->method('set')
            ->with('roundRobinLastAgentId', $this->anything())
            ->willReturnCallback(function ($key, $val) use (&$configState) {
                $configState = $val;
            });

        $configWriterMock = $this->createMock(ConfigWriter::class);
        $configWriterMock->expects($this->exactly(2))
            ->method('set')
            ->with('roundRobinLastAgentId', $this->anything())
            ->willReturnCallback(function ($key, $val) use (&$configState) {
                $configState = $val;
            });
        $configWriterMock->expects($this->exactly(2))
            ->method('save');

        $service = new LeadDistributionService($entityManagerMock, $configMock, $configWriterMock);

        // Invocación 1: último fue agent-1 -> debe rotar a agent-2
        $nextAgent1 = $service->getNextAssignedUserId();
        $this->assertSame('agent-2', $nextAgent1);
        $this->assertSame('agent-2', $configState);

        // Invocación 2: último fue agent-2 -> rotación circular completa a agent-1
        $nextAgent2 = $service->getNextAssignedUserId();
        $this->assertSame('agent-1', $nextAgent2);
        $this->assertSame('agent-1', $configState);
    }

    /**
     * Valida el fallback defensivo hacia el usuario Admin cuando la lista de agentes está vacía.
     */
    public function testFallbackToAdminWhenNoAgentsAvailable(): void
    {
        $stmtAgentsMock = $this->createMock(\PDOStatement::class);
        $stmtAgentsMock->method('fetchAll')->willReturn([]); // No active agents

        $stmtAdminMock = $this->createMock(\PDOStatement::class);
        $stmtAdminMock->method('fetchColumn')->willReturn('admin-master-id');

        $pdoMock = $this->createMock(\PDO::class);
        $pdoMock->method('query')
            ->willReturnCallback(function (string $sql) use ($stmtAgentsMock, $stmtAdminMock) {
                if (str_contains($sql, "'Agent'")) {
                    return $stmtAgentsMock;
                }
                return $stmtAdminMock;
            });

        $entityManagerMock = $this->createMock(EntityManager::class);
        $entityManagerMock->method('getPDO')->willReturn($pdoMock);

        $configMock = $this->createMock(Config::class);
        $configMock->method('get')->with('roundRobinLastAgentId')->willReturn(null);

        $configWriterMock = $this->createMock(ConfigWriter::class);
        // Fallback defensivo no debe actualizar el puntero de agentes
        $configWriterMock->expects($this->never())->method('set');
        $configWriterMock->expects($this->never())->method('save');

        $service = new LeadDistributionService($entityManagerMock, $configMock, $configWriterMock);

        $assignedUserId = $service->getNextAssignedUserId();

        $this->assertSame('admin-master-id', $assignedUserId);
    }
}
