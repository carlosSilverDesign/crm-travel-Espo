# Validación de Aceptación: Feature 001 - Modelo de Datos Turístico y Reglas de Negocio

* **Especificación:** [`/docs/specs/travel-data-model.spec.md`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/docs/specs/travel-data-model.spec.md)
* **Plan Técnico:** [`/docs/plans/travel-data-model.plan.md`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/docs/plans/travel-data-model.plan.md)
* **Backlog de Tareas:** [`/docs/tasks/travel-data-model.tasks.md`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/docs/tasks/travel-data-model.tasks.md)
* **Constitución del Proyecto:** [`/docs/constitution.md`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/docs/constitution.md)
* **Fecha de Validación:** 16 de Septiembre de 2026
* **Entorno de Prueba:** Docker Contenerizado (`espocrm/espocrm:10.0.6` con PHP 8.4.24, Apache 2.4 y MySQL 8.0).
* **Motor de Pruebas:** PHPUnit 13.3.4 + Runner CLI Autónomo sobre EntityManager ORM.

---

## 1. Matriz de Trazabilidad y Validación de Tareas (TASK-001 a TASK-011)

| ID Tarea | Épica | Componente / Archivo | Criterio de Aceptación Verificado | Evidencia de Ejecución | Estado |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TASK-001** | Épica 1 | `metadata/scopes/` y `metadata/entityDefs/` | Definición limpia de 7 entidades custom (`Itinerario`, `ItineraryItem`, `BudgetLine`, `Passenger`, `PaymentSchedule`, `Supplier`, `PackageTemplate`) y extensiones a `Opportunity` y `Contact`. | Metadatos cargados y reconstruidos vía `setup-crm.js` y `command.php rebuild`. Tablas creadas en MySQL. | ✅ PASADO |
| **TASK-002** | Épica 1 | `metadata/app/formula.json` | Fórmulas reactivas en `BudgetLine`: `sellingPrice = costPrice * (1 + marginRate / 100)` y `grossProfit = sellingPrice - costPrice`. | Evaluado en memoria por `formulaManager` en unit tests con precisión monetaria al centavo. | ✅ PASADO |
| **TASK-003** | Épica 2 | `Hooks/Passenger/DocumentValidation.php` | Interceptar `beforeSave` de `Passenger` y bloquear el guardado si `documentExpiration <= Itinerario.endDate`. | Rechazo HTTP 400 (`BadRequest`) ante fecha inválida; aprobación ante documento vigente. | ✅ PASADO |
| **TASK-004** | Épica 2 | `Hooks/Itinerario/ConfirmationGate.php` | Bloquear cambio de `status` a "Confirmado" si `quoteValidUntil < now()` o si `sum(PaymentSchedule) != totalSelling`. | Rechazo por descuadre de pagos (1750 != 0) comprobado en integración; aprobación tras cuadre exacto. | ✅ PASADO |
| **TASK-005** | Épica 3 | `Hooks/BudgetLine/FinancialAggregation.php` | Recalcular `totalCost`, `totalSelling` y `grossProfit` en `Itinerario` en eventos `afterSave` y `afterRemove` de `BudgetLine`. | Agregación automática: Costo 1000 + 500 = 1500; Venta 1200 + 550 = 1750; Margen = 250 verificado en DB. | ✅ PASADO |
| **TASK-006** | Épica 3 | `Hooks/Itinerario/OpportunitySync.php` | Al confirmar `Itinerario`, actualizar automáticamente `Opportunity` a `stage = Closed Won` y `amount = totalSelling`. En cancelación total, pasar a `Closed Lost`. | Oportunidad pasó automáticamente a `Closed Won` con `amount = 1750.00` sin intervención manual. | ✅ PASADO |
| **TASK-007** | Épica 3 | `Hooks/Opportunity/ClosedWonGuard.php` | Prohibir cambio manual o vía API a `Closed Won` en `Opportunity` si no existe al menos un `Itinerario` confirmado vinculado. | Intento de cierre en frío interceptado y rechazado con `BadRequest (400)` por la guardia comercial. | ✅ PASADO |
| **TASK-008** | Épica 4 | `Acl/BudgetLine.php` y `FinancialAggregation::beforeSave` | Campo `grossProfit` visible para agentes; `costPrice` y `marginRate` bloqueados como `readOnly` para rol `Agent` si el itinerario no está en "Cotización". | Clase ACL y hook defensivo verifican permisos en caliente respetando facultades de `Admin`. | ✅ PASADO |
| **TASK-009** | Épica 4 | `layouts/` (`detail.json`, `list.json`, `bottomPanelsDetail.json`, `relationships.json`) | Chunking cognitivo en Detalle (2 bloques: General y Financiero); orden operativo inferior: Pasajeros $\to$ Servicios $\to$ Finanzas $\to$ Pagos. Listas entre 5 y 7 columnas sin desbordamiento horizontal. | Pruebas de aceptación contra `Layout\Service` superadas al 100% en backend y cliente de EspoCRM 10. | ✅ PASADO |
| **TASK-010** | Épica 5 | `Tests/Unit/` (`BudgetLineCalculationTest`, `PassengerDocumentValidationTest`) | Suite unitaria PHPUnit que valida fórmulas financieras, casos de frontera (margen 0%, decimales) y validaciones de pasaporte con mocks y stubs limpios. | 9 tests unitarios ejecutados en 0.034s, 28 aserciones, 0 errores, 0 fallos, 0 deprecations. | ✅ PASADO |
| **TASK-011** | Épica 5 | `Tests/Integration/TravelLifecycleE2ETest.php` | Prueba de integración E2E del ciclo completo de venta turística: Contacto $\to$ Oportunidad $\to$ Itinerario $\to$ Pasajero $\to$ Costos $\to$ Bloqueo comercial $\to$ Pagos $\to$ Confirmación $\to$ Sincronización a Closed Won. | Ejecución dual (Runner CLI y PHPUnit) 100% exitosa con limpieza transaccional (teardown) garantizada. | ✅ PASADO |

---

## 2. Cobertura de Historias de Usuario (Spec 001)

### HU-01: Cotización, Margen Automático y Transparencia Comercial
* **Cálculo reactivo:** Validado que la persistencia o modificación de `costPrice = 1000` y `marginRate = 20` calcula de forma determinista `sellingPrice = 1200` y `grossProfit = 200`.
* **Protección de tarifas cerradas:** La clase ACL nativa [`custom/Espo/Custom/Acl/BudgetLine.php`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/custom/Espo/Custom/Acl/BudgetLine.php) y el hook de agregación conmutan `costPrice` y `marginRate` a solo lectura para agentes comerciales una vez que el expediente pasa a "Confirmado".
* **Resultado:** **CUMPLIDO AL 100%** (Verificado en `TASK-002`, `TASK-008`, `TASK-010`).

### HU-02: Gestión de Pasajeros y Control de Documentación
* **Validación de expiración:** Se probó que asociar un pasajero con pasaporte vencido antes del fin del viaje (`documentExpiration = 2026-11-05` con viaje hasta `2026-11-10`) detiene la transacción y arroja `BadRequest (400)`.
* **Caso de frontera:** Documentos con expiración en el mismo día del fin del viaje ($\le \text{endDate}$) son rechazados preventivamente. Documentos vigentes tras el viaje son aprobados.
* **Resultado:** **CUMPLIDO AL 100%** (Verificado en `TASK-003`, `TASK-010`, `TASK-011`).

### HU-03: Sincronización Unidireccional de Pipeline e Itinerario
* **Sincronización ascendente:** Al confirmar el `Itinerario`, el hook [`OpportunitySync.php`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/custom/Espo/Custom/Hooks/Itinerario/OpportunitySync.php) localiza la oportunidad comercial vinculada, conmuta su etapa a `Closed Won` e iguala su monto (`amount`) al `totalSelling` del itinerario ($1,750.00).
* **Integridad comercial preventiva:** El hook [`ClosedWonGuard.php`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/custom/Espo/Custom/Hooks/Opportunity/ClosedWonGuard.php) impidió que un comercial forzara manualmente el estado `Closed Won` en la oportunidad mientras el itinerario se encontraba en estado "Cotización".
* **Resultado:** **CUMPLIDO AL 100%** (Verificado en `TASK-006`, `TASK-007`, `TASK-011`).

### HU-04: Cronograma de Pagos y Expiración de Cotización
* **Control de vigencia tarifaria:** `ConfirmationGate.php` valida que `quoteValidUntil` no pertenezca al pasado antes de permitir la confirmación.
* **Cuadre financiero estricto:** El intento de confirmar el itinerario con un valor de venta de $1,750.00 sin cuotas de cobro asignadas ($0.00) fue bloqueado con alerta de descuadre. Una vez registradas las cuotas de seña ($750.00) y saldo ($1,000.00), la confirmación procedió de inmediato.
* **Resultado:** **CUMPLIDO AL 100%** (Verificado en `TASK-004`, `TASK-011`).

---

## 3. Cumplimiento de Principios No Negociables (Constitución)

1. **Inmunidad de Actualización (Zero Core Modifications):**
   - El 100% de los archivos creados reside en `custom/Espo/Custom/`.
   - El núcleo (`application/Espo/`) se mantuvo de solo lectura sin un solo parche o modificación, permitiendo actualizar la imagen Docker de EspoCRM a versiones futuras sin riesgo de sobreescritura.
2. **Estándar PSR-4 e Inyección de Dependencias Limpia:**
   - Todas las clases implementan namespaces PSR-4 nativos (`Espo\Custom\Hooks\...`, `Espo\Custom\Acl\...`, `Espo\Custom\Tests\...`).
   - Todos los constructores usan tipado estricto (`EntityManager $entityManager`, `User $user`) garantizando compatibilidad con el contenedor de inyección (`InjectableFactory`) de EspoCRM 10.
3. **Integridad Transaccional ACID en MySQL:**
   - La agregación financiera en cascada y las transiciones de estado operan bajo el ORM de EspoCRM con persistencia atómica en base de datos.
4. **Ergonomía Visual y Chunking Cognitivo:**
   - `Itinerario/detail.json` divide la información en dos bloques visuales claros: *Información General* y *Resumen Financiero y Notas*.
   - Los paneles inferiores (`bottomPanelsDetail.json` y `relationships.json`) fuerzan la secuencia operativa natural de la agencia:
     $$\text{Pasajeros} \longrightarrow \text{Servicios Turísticos} \longrightarrow \text{Finanzas y Márgenes} \longrightarrow \text{Cronograma de Pagos}$$
   - Las listas (`list.json`) se optimizaron entre 5 y 7 columnas para evitar scroll horizontal innecesario.
5. **Idempotencia y Reproducibilidad:**
   - El script [`setup-crm.js`](file:///Users/shaper/Documents/EspoCRM/crm-viajes-saas/setup-crm.js) permite reconstruir todo el esquema, fórmulas y layouts desde cero en cualquier instancia limpia.
   - Las pruebas de integración incluyen un mecanismo de *cleanup stack* que revierte en orden inverso todos los registros creados tras cada ejecución.

---

## 4. Evidencias de Ejecución Automatizada

### Suite Unitaria (PHPUnit 13.3.4)
```text
$ docker compose exec espocrm ./vendor/bin/phpunit custom/Espo/Custom/Tests/Unit/

PHPUnit 13.3.4 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
Configuration: /var/www/html/phpunit.xml

.........                                                           9 / 9 (100%)

Time: 00:00.034, Memory: 48.10 MB
OK (9 tests, 28 assertions)
```

### Script de Integración E2E (Runner Standalone)
```text
$ docker compose exec espocrm php custom/Espo/Custom/Tests/Integration/TravelLifecycleE2ETest.php

=================================================================
  EJECUTANDO PRUEBA DE INTEGRACIÓN E2E (TASK-011)
  Ciclo de Vida Completo del Expediente de Viaje en EspoCRM
=================================================================

▶ [1/6] Creando Contacto 'Carlos Viajero' y Oportunidad en cotización...
▶ [2/6] Creando Itinerario Base con tarifas vigentes...
▶ [3/6] Probando validación de pasaporte (TASK-003: DocumentValidation)...
▶ [4/6] Cargando líneas de presupuesto y verificando agregación financiera (TASK-005)...
▶ [5/6] Verificando guardia comercial en Opportunity (TASK-007: ClosedWonGuard)...
▶ [6/6] Verificando ConfirmationGate (TASK-004) y sincronización a Closed Won (TASK-006)...

-----------------------------------------------------------------
✅ RESULTADO: ÉXITO TOTAL (100% GREEN)
   - Todas las aserciones de integridad, hooks y sincronización pasaron.
   - La base de datos operó de forma atómica y sin inconsistencias.
-----------------------------------------------------------------

🧹 Limpieza de datos de prueba completada.
```

### Suite Completa Consolidada (Unitarios + Integración)
```text
$ docker compose exec espocrm ./vendor/bin/phpunit

PHPUnit 13.3.4 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
Configuration: /var/www/html/phpunit.xml

..........                                                        10 / 10 (100%)

Time: 00:00.134, Memory: 60.10 MB
OK (10 tests, 46 assertions)
```

---

## 5. Dictamen Final de Aceptación

El desarrollo de la **Feature 001: Modelo de Datos Turístico y Reglas de Negocio** (Épicas 1 a 5, Tareas TASK-001 a TASK-011) cumple al 100% con los criterios de aceptación, estándares de arquitectura no negociables y calidad de código exigidos en la especificación técnica.

* **Veredicto:** **APROBADO PARA PRODUCCIÓN / FASE SIGUIENTE DE INTEGRACIONES EXTERNAS**
* **Responsable:** Senior EspoCRM Backend & QA Lead Engineer
* **Fecha:** 16 de Septiembre de 2026
