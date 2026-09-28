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
            if ($expected != $actual) throw new \RuntimeException($message ?: "Failed asserting that $actual matches expected $expected.");
        }
        public function assertTrue($condition, string $message = ""): void {
            if (!$condition) throw new \RuntimeException($message ?: "Failed asserting that condition is true.");
        }
        public function assertStringContainsString(string $needle, string $haystack, string $message = ""): void {
            if (!str_contains($haystack, $needle)) throw new \RuntimeException($message ?: "Failed asserting that \"$haystack\" contains \"$needle\".");
        }
    }
    ');
}

use PHPUnit\Framework\TestCase;
use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;

class OpportunityPipelineE2ETest extends TestCase
{
    private ?EntityManager $entityManager = null;
    private array $cleanupStack = [];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Application();
        // Restaurar handlers para compatibilidad con PHPUnit
        restore_error_handler();
        restore_exception_handler();

        $container = $app->getContainer();
        $this->entityManager = $container->get('entityManager');

        // Garantizar que el usuario de sesión esté presente en CLI
        $systemUser = $this->entityManager->getEntity('User', 'system');
        $container->set('user', $systemUser);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager) {
            // Eliminar registros en orden inverso para preservar integridad relacional
            while (!empty($this->cleanupStack)) {
                $item = array_pop($this->cleanupStack);
                try {
                    $entity = $this->entityManager->getEntity($item['entityType'], $item['id']);
                    if ($entity) {
                        $this->entityManager->removeEntity($entity);
                    }
                } catch (\Throwable $e) {
                    // Silenciar fallos de limpieza
                }
            }
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
     * Prueba de Integración E2E: Validación del Ciclo Comercial Completo de Opportunity.
     */
    public function testOpportunityPipelineLifecycle(): void
    {
        $em = $this->entityManager;

        // =========================================================================
        // PASO 1: Prospección Inicial y Monto Manual Estimado
        // =========================================================================
        echo "   ▶ [Paso 1] Creando Contacto 'Lucía Viajera' y Opportunity con presupuesto estimado...\n";
        $contact = $em->getNewEntity('Contact');
        $contact->set([
            'firstName' => 'Lucía',
            'lastName' => 'Viajera',
            'emailAddress' => 'lucia.pipeline@test.com'
        ]);
        $em->saveEntity($contact);
        $this->trackForCleanup($contact);
        $this->assertNotEmpty($contact->getId(), 'El Contacto debe persistir con ID válido.');

        $opp1 = $em->getNewEntity('Opportunity');
        $opp1->set([
            'name' => 'Tour Europa Clásica 2026',
            'stage' => 'Prospecting',
            'amount' => 4500.00,
            'destination' => 'Europa',
            'leadSource' => 'WhatsApp',
            'contactId' => $contact->getId(),
            'closeDate' => date('Y-m-d', strtotime('+60 days'))
        ]);
        $em->saveEntity($opp1);
        $this->trackForCleanup($opp1);

        $this->assertNotEmpty($opp1->getId(), 'La Opportunity debe persistir con ID válido.');
        $this->assertEquals(4500.00, (float) $opp1->get('amount'), 'El monto estimado manual debe ser 4500.00.');
        $this->assertEquals('Prospecting', $opp1->get('stage'), 'La etapa debe ser Prospecting.');
        $this->assertEquals('Europa', $opp1->get('destination'), 'El destino debe ser Europa.');
        $this->assertEquals('WhatsApp', $opp1->get('leadSource'), 'El canal de captación debe ser WhatsApp.');
        echo "     ✔ Oportunidad creada con monto estimado \$4,500.00 y etapa Prospecting.\n";

        // =========================================================================
        // PASO 2: Validación de Compuerta PaymentPendingGate (Fallo esperado)
        // =========================================================================
        echo "   ▶ [Paso 2] Probando PaymentPendingGate sin itinerario cotizado (Fallo 400 esperado)...\n";
        $errorCaptured = false;
        try {
            $opp1->set('stage', 'PaymentPending');
            $em->saveEntity($opp1);
        } catch (BadRequest $e) {
            $errorCaptured = true;
            $this->assertStringContainsString(
                'No es posible pasar a Espera de Pago sin un Itinerario en cotización vinculado',
                $e->getMessage(),
                'El mensaje de error debe indicar la falta de itinerario cotizado.'
            );
            echo "     ✔ Compuerta activa: rechazo con HTTP 400 (\"{$e->getMessage()}\").\n";
        }

        $this->assertTrue($errorCaptured, 'PaymentPendingGate debió bloquear la transición sin itinerario.');

        // Recargar y verificar que la entidad siga en Prospecting
        $opp1Reloaded = $em->getEntity('Opportunity', $opp1->getId());
        $this->assertEquals('Prospecting', $opp1Reloaded->get('stage'), 'Opportunity no debe guardar la mutación inválida.');

        // =========================================================================
        // PASO 3: Creación de Itinerario y Sincronización Financiera Reactiva
        // =========================================================================
        echo "   ▶ [Paso 3] Creando Itinerario y verificando sincronización financiera reactiva (TASK-014)...\n";
        $itinerario = $em->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'Circuito Madrid - París 2026',
            'destination' => 'Europa',
            'startDate' => '2026-10-10',
            'endDate' => '2026-10-25',
            'quoteValidUntil' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'status' => 'Cotización',
            'totalSelling' => 5200.00,
            'grossProfit' => 800.00,
            'opportunityId' => $opp1->getId()
        ]);
        $em->saveEntity($itinerario);
        $this->trackForCleanup($itinerario);
        $this->assertNotEmpty($itinerario->getId(), 'Itinerario debe persistir con ID válido.');

        // Recargar Opportunity para verificar la propagación de OpportunitySync
        $opp1Synced = $em->getEntity('Opportunity', $opp1->getId());
        $this->assertEquals(5200.00, (float) $opp1Synced->get('amount'), 'Opportunity.amount debe sincronizarse a 5200.00.');
        $this->assertEquals(800.00, (float) $opp1Synced->get('projectedGrossProfit'), 'Opportunity.projectedGrossProfit debe ser 800.00.');
        $this->assertEquals('2026-10-10', $opp1Synced->get('travelStartDate'), 'Opportunity.travelStartDate debe sincronizarse.');
        $this->assertEquals('2026-10-25', $opp1Synced->get('travelEndDate'), 'Opportunity.travelEndDate debe sincronizarse.');
        echo "     ✔ Sincronización exitosa: Monto actualizado a \$5,200.00, Margen a \$800.00 y fechas mapeadas.\n";

        // =========================================================================
        // PASO 4: Validación de Compuerta PaymentPendingGate (Éxito)
        // =========================================================================
        echo "   ▶ [Paso 4] Transicionando Opportunity a PaymentPending con itinerario cotizado (Éxito)...\n";
        $opp1Synced->set('stage', 'PaymentPending');
        $em->saveEntity($opp1Synced);

        $opp1PaymentPending = $em->getEntity('Opportunity', $opp1->getId());
        $this->assertEquals('PaymentPending', $opp1PaymentPending->get('stage'), 'La etapa debe ser PaymentPending.');
        echo "     ✔ Transición a PaymentPending autorizada satisfactoriamente.\n";

        // =========================================================================
        // PASO 5: Confirmación de Viaje y Cierre Automático (Closed Won)
        // =========================================================================
        echo "   ▶ [Paso 5] Creando PaymentSchedules, confirmando viaje y verificando Closed Won...\n";
        // Dos cuotas que suman exactamente $5,200.00 para cumplir ConfirmationGate
        $ps1 = $em->getNewEntity('PaymentSchedule');
        $ps1->set([
            'name' => 'Anticipo 40%',
            'itinerarioId' => $itinerario->getId(),
            'amount' => 2000.00,
            'dueDate' => '2026-09-30',
            'status' => 'Programado'
        ]);
        $em->saveEntity($ps1);
        $this->trackForCleanup($ps1);

        $ps2 = $em->getNewEntity('PaymentSchedule');
        $ps2->set([
            'name' => 'Saldo Final 60%',
            'itinerarioId' => $itinerario->getId(),
            'amount' => 3200.00,
            'dueDate' => '2026-10-05',
            'status' => 'Programado'
        ]);
        $em->saveEntity($ps2);
        $this->trackForCleanup($ps2);

        // Confirmar itinerario
        $itinerarioReloaded = $em->getEntity('Itinerario', $itinerario->getId());
        $itinerarioReloaded->set('status', 'Confirmado');
        $em->saveEntity($itinerarioReloaded);

        // Recargar Opportunity
        $opp1Won = $em->getEntity('Opportunity', $opp1->getId());
        $this->assertEquals('Closed Won', $opp1Won->get('stage'), 'Opportunity debe transicionar automáticamente a Closed Won.');
        $this->assertEquals(100, (int) $opp1Won->get('probability'), 'Opportunity.probability debe ser 100%.');
        echo "     ✔ Oportunidad cerrada en Closed Won con 100% de probabilidad.\n";

        // =========================================================================
        // PASO 6: Validación de Compuerta LostReasonGuard en otra Oportunidad
        // =========================================================================
        echo "   ▶ [Paso 6] Validando LostReasonGuard en segunda Oportunidad 'Escapada Caribe 2026'...\n";
        $opp2 = $em->getNewEntity('Opportunity');
        $opp2->set([
            'name' => 'Escapada Caribe 2026',
            'stage' => 'Qualification',
            'amount' => 1800.00,
            'destination' => 'Caribe',
            'leadSource' => 'Instagram',
            'closeDate' => date('Y-m-d', strtotime('+30 days'))
        ]);
        $em->saveEntity($opp2);
        $this->trackForCleanup($opp2);

        // A. Intento de cerrar como perdida sin lostReason
        $errorLostCaptured = false;
        try {
            $opp2->set('stage', 'Closed Lost');
            $em->saveEntity($opp2);
        } catch (BadRequest $e) {
            $errorLostCaptured = true;
            $this->assertStringContainsString(
                'Debe especificar el motivo de pérdida (lostReason)',
                $e->getMessage(),
                'El mensaje debe exigir lostReason.'
            );
            echo "     ✔ Compuerta activa: intento sin motivo rechazado con HTTP 400.\n";
        }
        $this->assertTrue($errorLostCaptured, 'LostReasonGuard debió bloquear el cierre sin motivo.');

        // B. Asignar motivo válido y reintentar
        $opp2Reloaded = $em->getEntity('Opportunity', $opp2->getId());
        $opp2Reloaded->set([
            'stage' => 'Closed Lost',
            'lostReason' => 'Precio / Presupuesto Alto'
        ]);
        $em->saveEntity($opp2Reloaded);

        $opp2Lost = $em->getEntity('Opportunity', $opp2->getId());
        $this->assertEquals('Closed Lost', $opp2Lost->get('stage'), 'La etapa debe ser Closed Lost.');
        $this->assertEquals('Precio / Presupuesto Alto', $opp2Lost->get('lostReason'), 'El motivo debe coincidir.');
        $this->assertEquals(0, (int) $opp2Lost->get('probability'), 'Opportunity.probability debe ser 0%.');
        echo "     ✔ Oportunidad descartada exitosamente en Closed Lost con motivo y probabilidad 0%.\n";
    }
}

// Bloque para permitir la ejecución autónoma directa vía PHP CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $bootstrapPath = dirname(__DIR__, 5) . '/bootstrap.php';
    if (!file_exists($bootstrapPath)) {
        echo "Error: No se encontró bootstrap.php en $bootstrapPath\n";
        exit(1);
    }
    require_once $bootstrapPath;

    echo "\n=================================================================\n";
    echo "  TEST E2E: PIPELINE COMERCIAL ADAPTADO (OPPORTUNITY & ITINERARIO)\n";
    echo "=================================================================\n\n";

    $test = new OpportunityPipelineE2ETest();

    $setUp = new \ReflectionMethod($test, 'setUp');
    $setUp->invoke($test);

    try {
        $testMethod = new \ReflectionMethod($test, 'testOpportunityPipelineLifecycle');
        $testMethod->invoke($test);

        echo "\n-----------------------------------------------------------------\n";
        echo "✅ RESULTADO E2E: ÉXITO TOTAL (6 DE 6 PASOS COMPLETADOS)\n";
        echo "   - Todas las aserciones del pipeline comercial se cumplieron.\n";
        echo "   - Las compuertas PaymentPendingGate y LostReasonGuard operaron.\n";
        echo "   - La sincronización reactiva de montos y cierre automático pasó.\n";
        echo "-----------------------------------------------------------------\n\n";
    } catch (\Throwable $e) {
        echo "\n❌ FALLO EN LA PRUEBA E2E: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n\n";
        exit(1);
    } finally {
        $tearDown = new \ReflectionMethod($test, 'tearDown');
        $tearDown->invoke($test);
        echo "🧹 Limpieza de datos de prueba completada.\n\n";
    }

    exit(0);
}
