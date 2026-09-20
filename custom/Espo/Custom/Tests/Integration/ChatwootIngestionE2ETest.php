<?php

namespace Espo\Custom\Tests\Integration;

// Si se ejecuta directamente vía CLI sin el binario de PHPUnit, proveer el shim de aserciones
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    eval('
    namespace PHPUnit\Framework;
    class TestCase {
        protected function setUp(): void {}
        protected function tearDown(): void {}
        public function assertNotEmpty($actual, string $message = ""): void {
            if (empty($actual)) throw new \RuntimeException($message ?: "Failed asserting that value is not empty.");
        }
        public function assertEquals($expected, $actual, string $message = ""): void {
            if ($expected != $actual) throw new \RuntimeException($message ?: "Failed asserting that " . var_export($actual, true) . " matches expected " . var_export($expected, true) . ".");
        }
        public function assertTrue($condition, string $message = ""): void {
            if (!$condition) throw new \RuntimeException($message ?: "Failed asserting that condition is true.");
        }
        public function assertCount(int $expectedCount, $haystack, string $message = ""): void {
            $count = is_countable($haystack) ? count($haystack) : 0;
            if ($count !== $expectedCount) throw new \RuntimeException($message ?: "Failed asserting that count $count matches expected $expectedCount.");
        }
        public function assertStringContainsString(string $needle, string $haystack, string $message = ""): void {
            if (!str_contains($haystack, $needle)) throw new \RuntimeException($message ?: "Failed asserting that \"$haystack\" contains \"$needle\".");
        }
    }
    ');
}

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Exceptions\Unauthorized;
use Espo\Custom\Controllers\WebhookChatwoot;
use Espo\ORM\Entity;

/**
 * Clase simuladora de Request HTTP para emular cabeceras y métodos HTTP de ingesta.
 */
class SimulatedWebhookRequest
{
    private array $headers;

    public function __construct(array $headers = [])
    {
        $this->headers = $headers;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $val) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($val) ? ($val[0] ?? null) : (string) $val;
            }
        }
        return null;
    }

    public function getServerParam(string $name): ?string
    {
        if (strcasecmp($name, 'HTTP_AUTHORIZATION') === 0) {
            return $this->getHeader('Authorization');
        }
        return null;
    }
}

/**
 * TASK-025: Prueba de Integración E2E - Ingesta de Webhooks Chatwoot (WhatsApp & Omnicanalidad).
 *
 * Valida:
 * 1. Configuración previa del entorno y autenticación Bearer Token.
 * 2. Ingesta atómica de nuevo lead (Contact + Opportunity en Prospecting).
 * 3. Rotación Round-Robin equitativa entre agentes.
 * 4. Prevención estricta de duplicados ante mensajes subsecuentes.
 * 5. Tolerancia a fallos ante nombres incompletos (Ley de Postel / 'Viajero WhatsApp').
 */
class ChatwootIngestionE2ETest extends TestCase
{
    private ?Container $container = null;
    private ?EntityManager $entityManager = null;
    private ?Config $config = null;
    private ?ConfigWriter $configWriter = null;
    private ?WebhookChatwoot $controller = null;

    private array $cleanupStack = [];
    private ?string $originalSecret = null;
    private ?string $originalLastAgentId = null;
    private array $activeAgentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        restore_error_handler();
        restore_exception_handler();

        $this->container = $app->getContainer();
        $this->entityManager = $this->container->get('entityManager');
        $this->config = $this->container->get('config');

        // ConfigWriter
        if ($this->container->has('configWriter')) {
            $this->configWriter = $this->container->get('configWriter');
        } elseif ($this->container->has('injectableFactory')) {
            try {
                $this->configWriter = $this->container->get('injectableFactory')
                    ->create(ConfigWriter::class);
            } catch (\Throwable) {}
        }

        // Garantizar usuario de sesión para hooks y eventos en CLI
        $systemUser = $this->entityManager->getEntity('User', 'system');
        if ($systemUser) {
            $this->container->set('user', $systemUser);
        }

        // Instanciar controlador
        $this->controller = new WebhookChatwoot(
            $this->container,
            $this->entityManager,
            $this->config,
            $this->configWriter
        );

        // Guardar estado original de configuración para restaurar en tearDown
        $this->originalSecret = (string) $this->config->get('chatwootWebhookSecret', '');
        $this->originalLastAgentId = $this->config->get('roundRobinLastAgentId');
    }

    protected function tearDown(): void
    {
        // 1. Limpieza de entidades de prueba en orden inverso
        if ($this->entityManager) {
            while (!empty($this->cleanupStack)) {
                $item = array_pop($this->cleanupStack);
                try {
                    $entity = $this->entityManager->getEntity($item['entityType'], $item['id']);
                    if ($entity) {
                        $this->entityManager->removeEntity($entity);
                    }
                } catch (\Throwable) {}
            }
        }

        // 2. Restaurar configuración original
        if ($this->configWriter) {
            try {
                if ($this->originalSecret !== null) {
                    $this->configWriter->set('chatwootWebhookSecret', $this->originalSecret);
                }
                if ($this->originalLastAgentId !== null) {
                    $this->configWriter->set('roundRobinLastAgentId', $this->originalLastAgentId);
                }
                $this->configWriter->save();
            } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function trackForCleanup(Entity $entity): void
    {
        $this->cleanupStack[] = [
            'entityType' => $entity->getEntityType(),
            'id' => $entity->getId()
        ];
    }

    /**
     * Pipeline E2E Completo de Ingesta Webhook Chatwoot.
     */
    public function testChatwootIngestionE2E(): void
    {
        $em = $this->entityManager;
        $controller = $this->controller;

        echo "▶ [Paso 1] Configuración Previa del Entorno...\n";

        // =========================================================================
        // PASO 1: Configuración Previa del Entorno
        // =========================================================================
        $testSecret = 'test_secret_e2e_2026';
        if ($this->configWriter) {
            $this->configWriter->set('chatwootWebhookSecret', $testSecret);
            // Resetear puntero de round-robin para asegurar orden determinista
            $this->configWriter->set('roundRobinLastAgentId', null);
            $this->configWriter->save();
        }
        $this->config->set('chatwootWebhookSecret', $testSecret);
        $this->config->set('roundRobinLastAgentId', null);

        $this->assertEquals($testSecret, $this->config->get('chatwootWebhookSecret'));
        echo "     \033[32m✔ chatwootWebhookSecret configurado en 'test_secret_e2e_2026'.\033[0m\n";

        // Consultar agentes activos con rol 'Agent'
        $pdo = $em->getPDO();
        $sqlAgents = "
            SELECT u.id, u.user_name 
            FROM `user` u 
            INNER JOIN role_user ru ON ru.user_id = u.id 
            INNER JOIN role r ON r.id = ru.role_id 
            WHERE r.name = 'Agent' AND u.is_active = 1 AND u.deleted = 0 AND ru.deleted = 0 AND r.deleted = 0
            ORDER BY u.id ASC
        ";
        $sth = $pdo->query($sqlAgents);
        $agents = $sth ? $sth->fetchAll(\PDO::FETCH_ASSOC) : [];
        $this->assertTrue(count($agents) >= 2, "Debe haber al menos 2 agentes activos con rol 'Agent'.");

        $agent1 = $agents[0];
        $agent2 = $agents[1];
        $agent1Id = (string) $agent1['id'];
        $agent2Id = (string) $agent2['id'];

        echo "     \033[32m✔ Agentes activos identificados para Round-Robin (Agente 1: {$agent1['user_name']} [{$agent1Id}], Agente 2: {$agent2['user_name']} [{$agent2Id}]).\033[0m\n";
        echo "     \033[32m✔ Puntero de rotación inicializado determinísticamente.\033[0m\n";

        // Verificación de seguridad previa: Fallo con token ausente o inválido
        $unauthRequest = new SimulatedWebhookRequest([]);
        $unauthCaught = false;
        try {
            $controller->actionReceive([], ['event' => 'conversation_created'], $unauthRequest);
        } catch (Unauthorized $e) {
            $unauthCaught = true;
        }
        $this->assertTrue($unauthCaught, "Petición sin token debe lanzar Unauthorized.");

        $invalidTokenRequest = new SimulatedWebhookRequest(['Authorization' => 'Bearer token_invalido']);
        $invalidCaught = false;
        try {
            $controller->actionReceive([], ['event' => 'conversation_created'], $invalidTokenRequest);
        } catch (Unauthorized $e) {
            $invalidCaught = true;
        }
        $this->assertTrue($invalidCaught, "Petición con token inválido debe lanzar Unauthorized.");
        echo "     \033[32m✔ Compuerta de seguridad activa: peticiones sin autorización o con token inválido rechazadas (401 Unauthorized).\033[0m\n";

        // Request autorizado para los pasos de ingesta
        $validRequest = new SimulatedWebhookRequest(['Authorization' => 'Bearer ' . $testSecret]);

        // =========================================================================
        // PASO 2: Simulación de Nuevo Contacto (Primer Lead Entrante)
        // =========================================================================
        echo "▶ [Paso 2] Simulación de Nuevo Contacto (Primer Lead Entrante: Carlos Aventura)...\n";

        $payload1 = [
            'event' => 'conversation_created',
            'sender' => [
                'id' => 901,
                'name' => 'Carlos Aventura',
                'phone_number' => '+51987000111',
            ],
            'conversation' => [
                'id' => 5001,
            ],
        ];

        $res1 = $controller->actionReceive([], $payload1, $validRequest);

        $this->assertEquals('success', $res1['status'] ?? null);
        $this->assertNotEmpty($res1['contactId'] ?? null);
        $this->assertNotEmpty($res1['opportunityId'] ?? null);

        // Verificar Contact en BD
        /** @var Entity|null $contact1 */
        $contact1 = $em->getEntity('Contact', $res1['contactId']);
        $this->assertNotEmpty($contact1, "El Contacto 1 debe existir en BD.");
        $this->assertEquals('+51987000111', $contact1->get('phoneNumber'));
        $this->assertEquals('Carlos', $contact1->get('firstName'));
        $this->assertEquals('Aventura', $contact1->get('lastName'));
        $this->assertEquals('901', (string) $contact1->get('chatwootContactId'));

        // Verificar Opportunity en BD
        /** @var Entity|null $opp1 */
        $opp1 = $em->getEntity('Opportunity', $res1['opportunityId']);
        $this->assertNotEmpty($opp1, "La Oportunidad 1 debe existir en BD.");
        $this->assertEquals('Prospecting', $opp1->get('stage'));
        $this->assertEquals('WhatsApp', $opp1->get('leadSource'));
        $this->assertEquals($contact1->getId(), $opp1->get('contactId'));
        $this->assertEquals('5001', (string) $opp1->get('chatwootConversationId'));
        $this->assertEquals($agent1Id, $opp1->get('assignedUserId'), "El primer lead debe asignarse al Agente 1.");

        // Trackear en orden para eliminación segura (Opportunity primero, luego Contact)
        $this->trackForCleanup($contact1);
        $this->trackForCleanup($opp1);

        echo "     \033[32m✔ Webhook recibido y procesado con éxito (HTTP 200, status: success).\033[0m\n";
        echo "     \033[32m✔ Contacto persistido: Carlos Aventura (+51987000111, Chatwoot ID: 901).\033[0m\n";
        echo "     \033[32m✔ Oportunidad creada atómicamente en etapa 'Prospecting' (LeadSource: WhatsApp, Convo ID: 5001).\033[0m\n";
        echo "     \033[32m✔ Lead asignado a Agente 1 [{$agent1Id}] según Round-Robin.\033[0m\n";

        // =========================================================================
        // PASO 3: Segundo Lead Entrante (Validación de Rotación Round-Robin)
        // =========================================================================
        echo "▶ [Paso 3] Segundo Lead Entrante (Validación de Rotación: Mariana Senderista)...\n";

        $payload2 = [
            'event' => 'conversation_created',
            'sender' => [
                'id' => 902,
                'name' => 'Mariana Senderista',
                'phone_number' => '+51987000222',
            ],
            'conversation' => [
                'id' => 5002,
            ],
        ];

        $res2 = $controller->actionReceive([], $payload2, $validRequest);

        $this->assertEquals('success', $res2['status'] ?? null);
        $this->assertNotEmpty($res2['contactId'] ?? null);
        $this->assertNotEmpty($res2['opportunityId'] ?? null);

        // Verificar Contact 2 en BD
        /** @var Entity|null $contact2 */
        $contact2 = $em->getEntity('Contact', $res2['contactId']);
        $this->assertNotEmpty($contact2, "El Contacto 2 debe existir en BD.");
        $this->assertEquals('+51987000222', $contact2->get('phoneNumber'));
        $this->assertEquals('Mariana', $contact2->get('firstName'));
        $this->assertEquals('Senderista', $contact2->get('lastName'));
        $this->assertEquals('902', (string) $contact2->get('chatwootContactId'));

        // Verificar Opportunity 2 en BD
        /** @var Entity|null $opp2 */
        $opp2 = $em->getEntity('Opportunity', $res2['opportunityId']);
        $this->assertNotEmpty($opp2, "La Oportunidad 2 debe existir en BD.");
        $this->assertEquals('Prospecting', $opp2->get('stage'));
        $this->assertEquals('WhatsApp', $opp2->get('leadSource'));
        $this->assertEquals($contact2->getId(), $opp2->get('contactId'));
        $this->assertEquals('5002', (string) $opp2->get('chatwootConversationId'));
        $this->assertEquals($agent2Id, $opp2->get('assignedUserId'), "El segundo lead debe asignarse al Agente 2 (Rotación confirmada).");

        $this->trackForCleanup($contact2);
        $this->trackForCleanup($opp2);

        echo "     \033[32m✔ Webhook recibido y procesado con éxito para Mariana Senderista (+51987000222).\033[0m\n";
        echo "     \033[32m✔ Contacto persistido: Mariana Senderista (+51987000222, Chatwoot ID: 902).\033[0m\n";
        echo "     \033[32m✔ Oportunidad creada en 'Prospecting'.\033[0m\n";
        echo "     \033[32m✔ Rotación confirmada: Lead asignado equitativamente al Agente 2 [{$agent2Id}].\033[0m\n";

        // =========================================================================
        // PASO 4: Mensaje Subsiguiente de Contacto Existente (Prevención de Duplicados)
        // =========================================================================
        echo "▶ [Paso 4] Mensaje Subsiguiente de Contacto Existente (Prevención de Duplicados)...\n";

        $payload3 = [
            'event' => 'conversation_created',
            'sender' => [
                'id' => 901,
                'name' => 'Carlos Aventura',
                'phone_number' => '+51987000111',
            ],
            'conversation' => [
                'id' => 5003,
            ],
        ];

        $res3 = $controller->actionReceive([], $payload3, $validRequest);

        $this->assertEquals('success', $res3['status'] ?? null);
        $this->assertEquals($contact1->getId(), $res3['contactId'], "Debe reutilizar el mismo Contacto.");
        $this->assertEquals($opp1->getId(), $res3['opportunityId'], "Debe actualizar la Oportunidad activa preexistente.");

        // Aserción 1: El conteo total de contactos con '+51987000111' debe seguir siendo exactamente 1
        $existingContacts = $em->getRDBRepository('Contact')
            ->where(['phoneNumber' => '+51987000111'])
            ->find();
        $this->assertCount(1, $existingContacts, "El conteo de contactos para el teléfono debe ser exactamente 1.");

        // Aserción 2: La Oportunidad activa debe haberse actualizado con el nuevo chatwootConversationId
        $reloadedOpp1 = $em->getEntity('Opportunity', $opp1->getId());
        $this->assertEquals('5003', (string) $reloadedOpp1->get('chatwootConversationId'), "chatwootConversationId debe ser '5003'.");

        // Aserción 3: NO debe crearse una nueva Opportunity si la anterior sigue activa
        $allOppsForContact = $em->getRDBRepository('Opportunity')
            ->where(['contactId' => $contact1->getId()])
            ->find();
        $this->assertCount(1, $allOppsForContact, "No debe crearse una nueva oportunidad si ya existe una activa en Prospecting.");

        echo "     \033[32m✔ Webhook recibido para cliente recurrente (+51987000111, nuevo hilo de conversación: 5003).\033[0m\n";
        echo "     \033[32m✔ Cero duplicidad confirmada: Conteo total de contactos con +51987000111 es exactamente 1.\033[0m\n";
        echo "     \033[32m✔ Oportunidad activa actualizada correctamente con nuevo chatwootConversationId (5003).\033[0m\n";
        echo "     \033[32m✔ Comprobado: NO se crearon oportunidades duplicadas para el lead en curso.\033[0m\n";

        // =========================================================================
        // PASO 5: Resiliencia ante Payload Incompleto (Ley de Postel)
        // =========================================================================
        echo "▶ [Paso 5] Resiliencia ante Payload Incompleto (Ley de Postel)...\n";

        $payload4 = [
            'event' => 'conversation_created',
            'sender' => [
                'id' => 903,
                'name' => '', // Nombre vacío o ausente
                'phone_number' => '+51987000333',
            ],
            'conversation' => [
                'id' => 5004,
            ],
        ];

        $res4 = $controller->actionReceive([], $payload4, $validRequest);

        $this->assertEquals('success', $res4['status'] ?? null);
        $this->assertNotEmpty($res4['contactId'] ?? null);
        $this->assertNotEmpty($res4['opportunityId'] ?? null);

        // Verificar Contact resiliente en BD
        /** @var Entity|null $contact3 */
        $contact3 = $em->getEntity('Contact', $res4['contactId']);
        $this->assertNotEmpty($contact3, "El Contacto 3 debe existir en BD.");
        $this->assertEquals('+51987000333', $contact3->get('phoneNumber'));
        $this->assertEquals('Viajero', $contact3->get('firstName'));
        $this->assertEquals('WhatsApp', $contact3->get('lastName'));
        $this->assertEquals('903', (string) $contact3->get('chatwootContactId'));

        // Verificar Opportunity en BD
        /** @var Entity|null $opp3 */
        $opp3 = $em->getEntity('Opportunity', $res4['opportunityId']);
        $this->assertNotEmpty($opp3, "La Oportunidad 3 debe existir en BD.");
        $this->assertEquals('Prospecting', $opp3->get('stage'));
        $this->assertEquals('WhatsApp', $opp3->get('leadSource'));
        $this->assertEquals($contact3->getId(), $opp3->get('contactId'));
        $this->assertEquals('5004', (string) $opp3->get('chatwootConversationId'));

        $this->trackForCleanup($contact3);
        $this->trackForCleanup($opp3);

        echo "     \033[32m✔ Webhook tolerante a fallos: Payload con sender.name vacío procesado sin excepciones.\033[0m\n";
        echo "     \033[32m✔ Contacto creado con fallback defensivo: 'Viajero WhatsApp' (+51987000333).\033[0m\n";
        echo "     \033[32m✔ Oportunidad comercial vinculada exitosamente en etapa 'Prospecting'.\033[0m\n";
    }
}

// =============================================================================
// PUNTO DE ENTRADA CLI DIRECTO (docker compose exec espocrm php ...)
// =============================================================================
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $bootstrapPath = dirname(__DIR__, 5) . '/bootstrap.php';
    if (!file_exists($bootstrapPath)) {
        echo "Error: No se encontró bootstrap.php en $bootstrapPath\n";
        exit(1);
    }
    require_once $bootstrapPath;

    echo "\n=================================================================\n";
    echo "  EJECUTANDO PRUEBA DE INTEGRACIÓN E2E (TASK-025)\n";
    echo "  Ingesta de Webhooks Chatwoot (WhatsApp) en EspoCRM\n";
    echo "=================================================================\n\n";

    $test = new ChatwootIngestionE2ETest();

    $setUp = new \ReflectionMethod($test, 'setUp');
    $setUp->invoke($test);

    try {
        $testMethod = new \ReflectionMethod($test, 'testChatwootIngestionE2E');
        $testMethod->invoke($test);

        echo "\n-----------------------------------------------------------------\n";
        echo "\033[32m✅ RESULTADO: ÉXITO TOTAL (100% GREEN)\033[0m\n";
        echo "   - Autenticación exitosa con Bearer Token y compuerta activa.\n";
        echo "   - Creación atómica de Contact y Opportunity en etapa Prospecting.\n";
        echo "   - Balanceo equitativo entre agentes vía Round-Robin.\n";
        echo "   - Cero duplicidad de contactos para números de teléfono preexistentes.\n";
        echo "   - Asignación por defecto tolerante a fallos (Viajero WhatsApp).\n";
        echo "-----------------------------------------------------------------\n\n";
    } catch (\Throwable $e) {
        echo "\n\033[31m❌ FALLO EN LA PRUEBA E2E: " . $e->getMessage() . "\033[0m\n";
        echo $e->getTraceAsString() . "\n\n";
        exit(1);
    } finally {
        $tearDown = new \ReflectionMethod($test, 'tearDown');
        $tearDown->invoke($test);
        echo "🧹 Limpieza de datos de prueba completada.\n\n";
    }

    exit(0);
}
