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

class TravelLifecycleE2ETest extends TestCase
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
                    // Silenciar fallos de limpieza para no ocultar aserciones
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
     * Prueba de Integración E2E: Ciclo de Venta Turístico Completo.
     */
    public function testCompleteTravelAgencyLifecycle(): void
    {
        $em = $this->entityManager;

        // =========================================================================
        // PASO 1: Creación de Contacto y Oportunidad Comercial
        // =========================================================================
        $contact = $em->getNewEntity('Contact');
        $contact->set([
            'firstName' => 'Carlos',
            'lastName' => 'Viajero',
            'emailAddress' => 'carlos@test.com'
        ]);
        $em->saveEntity($contact);
        $this->trackForCleanup($contact);
        $this->assertNotEmpty($contact->getId(), 'El Contacto debe ser persistido con ID válido');

        $opportunity = $em->getNewEntity('Opportunity');
        $opportunity->set([
            'name' => 'Vacaciones Cancún 2026',
            'stage' => 'Proposal/Quote',
            'contactId' => $contact->getId()
        ]);
        $em->saveEntity($opportunity);
        $this->trackForCleanup($opportunity);
        $this->assertNotEmpty($opportunity->getId(), 'La Oportunidad debe ser persistida en etapa Proposal/Quote');
        $this->assertEquals('Proposal/Quote', $opportunity->get('stage'));

        // =========================================================================
        // PASO 2: Creación de Itinerario Base (Estado: Cotización)
        // =========================================================================
        $itinerario = $em->getNewEntity('Itinerario');
        $itinerario->set([
            'name' => 'Cancún All Inclusive 2026',
            'destination' => 'Cancún, México',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-10',
            'quoteValidUntil' => date('Y-m-d H:i:s', time() + (86400 * 30)), // Vigente 30 días
            'status' => 'Cotización',
            'opportunityId' => $opportunity->getId()
        ]);
        $em->saveEntity($itinerario);
        $this->trackForCleanup($itinerario);
        $this->assertNotEmpty($itinerario->getId(), 'El Itinerario debe ser persistido correctamente');
        $this->assertEquals('Cotización', $itinerario->get('status'));

        // =========================================================================
        // PASO 3: Validación de Documentación de Pasajero (TASK-003: DocumentValidation)
        // =========================================================================
        // A. Intento de crear pasajero con pasaporte vencido antes del fin del viaje (2026-11-05 <= 2026-11-10)
        $invalidPassenger = $em->getNewEntity('Passenger');
        $invalidPassenger->set([
            'name' => 'Carlos Viajero',
            'itinerarioId' => $itinerario->getId(),
            'contactId' => $contact->getId(),
            'documentType' => 'Passport',
            'documentNumber' => 'PAS-INVALID-01',
            'documentExpiration' => '2026-11-05'
        ]);

        $threwDocValidationException = false;
        try {
            $em->saveEntity($invalidPassenger);
        } catch (BadRequest $e) {
            $threwDocValidationException = true;
            $this->assertStringContainsString('El documento del pasajero expira antes del término del itinerario', $e->getMessage());
        }
        $this->assertTrue($threwDocValidationException, 'DocumentValidation debe lanzar BadRequest si el pasaporte expira antes del fin del viaje');

        // B. Creación de pasajero con pasaporte vigente (2027-05-01 > 2026-11-10)
        $validPassenger = $em->getNewEntity('Passenger');
        $validPassenger->set([
            'name' => 'Carlos Viajero',
            'itinerarioId' => $itinerario->getId(),
            'contactId' => $contact->getId(),
            'documentType' => 'Passport',
            'documentNumber' => 'PAS-VALID-999',
            'documentExpiration' => '2027-05-01'
        ]);
        $em->saveEntity($validPassenger);
        $this->trackForCleanup($validPassenger);
        $this->assertNotEmpty($validPassenger->getId(), 'Pasajero con pasaporte vigente debe guardarse exitosamente');

        // =========================================================================
        // PASO 4: Agregación Financiera de Costos y Márgenes (TASK-005: FinancialAggregation)
        // =========================================================================
        $supplier = $em->getNewEntity('Supplier');
        $supplier->set([
            'name' => 'Hotel Riu Cancún',
            'supplierType' => 'Hotel'
        ]);
        $em->saveEntity($supplier);
        $this->trackForCleanup($supplier);

        // BudgetLine 1: cost 1000, margin 20% -> selling 1200, profit 200
        $line1 = $em->getNewEntity('BudgetLine');
        $line1->set([
            'name' => 'Alojamiento All Inclusive 10 noches',
            'itinerarioId' => $itinerario->getId(),
            'supplierId' => $supplier->getId(),
            'costPrice' => 1000.00,
            'marginRate' => 20.00,
            'sellingPrice' => 1200.00,
            'grossProfit' => 200.00
        ]);
        $em->saveEntity($line1);
        $this->trackForCleanup($line1);

        // BudgetLine 2: cost 500, margin 10% -> selling 550, profit 50
        $line2 = $em->getNewEntity('BudgetLine');
        $line2->set([
            'name' => 'Traslados y Excursión Chichén Itzá',
            'itinerarioId' => $itinerario->getId(),
            'supplierId' => $supplier->getId(),
            'costPrice' => 500.00,
            'marginRate' => 10.00,
            'sellingPrice' => 550.00,
            'grossProfit' => 50.00
        ]);
        $em->saveEntity($line2);
        $this->trackForCleanup($line2);

        // Recargar Itinerario y verificar agregación automática de totales
        $reloadedItinerario = $em->getEntity('Itinerario', $itinerario->getId());
        $this->assertEquals(1500.00, (float) $reloadedItinerario->get('totalCost'), 'totalCost debe ser 1500.00');
        $this->assertEquals(1750.00, (float) $reloadedItinerario->get('totalSelling'), 'totalSelling debe ser 1750.00');
        $this->assertEquals(250.00, (float) $reloadedItinerario->get('grossProfit'), 'grossProfit debe ser 250.00');

        // =========================================================================
        // PASO 5: Guardia Comercial en Opportunity (TASK-007: ClosedWonGuard)
        // =========================================================================
        $oppToTamper = $em->getEntity('Opportunity', $opportunity->getId());
        $oppToTamper->set('stage', 'Closed Won');

        $threwClosedWonGuardException = false;
        try {
            $em->saveEntity($oppToTamper);
        } catch (BadRequest $e) {
            $threwClosedWonGuardException = true;
            $this->assertStringContainsString('No es posible cerrar como ganada una oportunidad sin un itinerario confirmado', $e->getMessage());
        }
        $this->assertTrue($threwClosedWonGuardException, 'ClosedWonGuard debe bloquear el paso a Closed Won sin itinerario confirmado');

        // =========================================================================
        // PASO 6: Validación de Confirmación y Sincronización Comercial
        //         (TASK-004: ConfirmationGate & TASK-006: OpportunitySync)
        // =========================================================================
        // A. Intentar confirmar itinerario sin cronograma de pagos (1750 != 0)
        $itinerarioToConfirm = $em->getEntity('Itinerario', $itinerario->getId());
        $itinerarioToConfirm->set('status', 'Confirmado');

        $threwConfirmationGateException = false;
        try {
            $em->saveEntity($itinerarioToConfirm);
        } catch (BadRequest $e) {
            $threwConfirmationGateException = true;
            $this->assertStringContainsString('Descuadre financiero', $e->getMessage());
        }
        $this->assertTrue($threwConfirmationGateException, 'ConfirmationGate debe bloquear la confirmación si no hay cronograma de pagos que cubra totalSelling');

        // B. Crear cronograma de cobros exacto: Cuota 1 = 750 + Cuota 2 = 1000 (Total = 1750)
        $payment1 = $em->getNewEntity('PaymentSchedule');
        $payment1->set([
            'name' => 'Cuota 1: Seña y Reserva',
            'itinerarioId' => $itinerario->getId(),
            'dueDate' => '2026-10-01',
            'amount' => 750.00,
            'status' => 'Pendiente',
            'paymentMethod' => 'Transferencia'
        ]);
        $em->saveEntity($payment1);
        $this->trackForCleanup($payment1);

        $payment2 = $em->getNewEntity('PaymentSchedule');
        $payment2->set([
            'name' => 'Cuota 2: Saldo Final',
            'itinerarioId' => $itinerario->getId(),
            'dueDate' => '2026-10-25',
            'amount' => 1000.00,
            'status' => 'Pendiente',
            'paymentMethod' => 'Transferencia'
        ]);
        $em->saveEntity($payment2);
        $this->trackForCleanup($payment2);

        // C. Confirmar Itinerario con pagos cuadrados
        $itinerarioConfirmed = $em->getEntity('Itinerario', $itinerario->getId());
        $itinerarioConfirmed->set('status', 'Confirmado');
        $em->saveEntity($itinerarioConfirmed);

        $this->assertEquals('Confirmado', $itinerarioConfirmed->get('status'), 'Itinerario debe quedar en estado Confirmado');

        // D. Comprobar sincronización automática de la Oportunidad (OpportunitySync)
        $opportunitySynced = $em->getEntity('Opportunity', $opportunity->getId());
        $this->assertEquals('Closed Won', $opportunitySynced->get('stage'), 'La Oportunidad debe sincronizarse automáticamente a Closed Won');
        $this->assertEquals(1750.00, (float) $opportunitySynced->get('amount'), 'El monto de la Oportunidad debe igualar a totalSelling (1750.00)');
    }
}

// =============================================================================
// RUNNER CLI DIRECTO (Ejecución standalone mediante `php TravelLifecycleE2ETest.php`)
// =============================================================================
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $bootstrapPath = dirname(__DIR__, 5) . '/bootstrap.php';
    if (!file_exists($bootstrapPath)) {
        echo "Error: No se encontró bootstrap.php en $bootstrapPath\n";
        exit(1);
    }
    require_once $bootstrapPath;

    echo "\n=================================================================\n";
    echo "  EJECUTANDO PRUEBA DE INTEGRACIÓN E2E (TASK-011)\n";
    echo "  Ciclo de Vida Completo del Expediente de Viaje en EspoCRM\n";
    echo "=================================================================\n\n";

    $test = new TravelLifecycleE2ETest();

    // Invocar setUp
    $setUp = new \ReflectionMethod($test, 'setUp');
    $setUp->invoke($test);

    try {
        echo "▶ [1/6] Creando Contacto 'Carlos Viajero' y Oportunidad en cotización...\n";
        echo "▶ [2/6] Creando Itinerario Base con tarifas vigentes...\n";
        echo "▶ [3/6] Probando validación de pasaporte (TASK-003: DocumentValidation)...\n";
        echo "▶ [4/6] Cargando líneas de presupuesto y verificando agregación financiera (TASK-005)...\n";
        echo "▶ [5/6] Verificando guardia comercial en Opportunity (TASK-007: ClosedWonGuard)...\n";
        echo "▶ [6/6] Verificando ConfirmationGate (TASK-004) y sincronización a Closed Won (TASK-006)...\n";

        $testMethod = new \ReflectionMethod($test, 'testCompleteTravelAgencyLifecycle');
        $testMethod->invoke($test);

        echo "\n-----------------------------------------------------------------\n";
        echo "✅ RESULTADO: ÉXITO TOTAL (100% GREEN)\n";
        echo "   - Todas las aserciones de integridad, hooks y sincronización pasaron.\n";
        echo "   - La base de datos operó de forma atómica y sin inconsistencias.\n";
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
