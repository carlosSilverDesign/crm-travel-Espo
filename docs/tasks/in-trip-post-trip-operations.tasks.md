# Tasks 007: Operación en Destino, Incidentes y Post-Venta

* **Módulo:** 07 — Operación en Destino y Post-Venta
* **Especificación de Referencia:** `/docs/specs/in-trip-post-trip-operations.spec.md`
* **Plan Técnico de Referencia:** `/docs/plans/in-trip-post-trip-operations.plan.md`
* **Metodología:** Spec-Driven Development (SDD)
* **Regla de Oro de Aislamiento:** Todo el desarrollo backend reside bajo `custom/Espo/Custom/` sin alterar el core en `application/`.
* **Principio de Resiliencia y UX:** La atención de disrupciones en destino no muta la contabilidad de la reserva, y la recolección post-venta mitiga fricciones antes de la exposición pública (*Peak-End Rule* & *Heurística 6*).

---

## 1. Matriz de Dependencias

```text
[TASK-043: Entidades Incident & Feedback en EspoCRM]
│
├───► [TASK-044: Hook de Clasificación NPS & Alerta de Detractores]
│             │
│             ▼
├───► [TASK-045: Tablero Operativo "En Viaje" & Filtros Rápidos]
│             │
│             ▼
├───► [TASK-046: Scheduled Job de Retorno & Webhook Post-Venta]
│             │
│             ▼
└───► [TASK-047: Flujo Activepieces (Template WhatsApp & Ingesta)]
              │
              ▼
[TASK-048: Suite de Pruebas Unitarias & Integración E2E]
```

---

## 2. Definición Atómica de Tareas

### Épica 1: Estructura de Datos para Operación y Calidad

#### `TASK-043` — Creación de Entidades `Incident` y `Feedback`
* **Archivos:**
  * `custom/Espo/Custom/Resources/metadata/entityDefs/Incident.json`
  * `custom/Espo/Custom/Resources/metadata/entityDefs/Feedback.json`
  * `custom/Espo/Custom/Resources/metadata/scopes/Incident.json`
  * `custom/Espo/Custom/Resources/metadata/scopes/Feedback.json`
  * `custom/Espo/Custom/Resources/metadata/relationships/OpportunityIncident.json`
  * `custom/Espo/Custom/Resources/metadata/relationships/OpportunityFeedback.json`
* **Descripción:**
  * Crear la entidad `Incident` con los campos: `name`, `opportunityId`, `itinerarioId`, `itemItinerarioId`, `severity` (enum: Low, Medium, High, Critical), `category` (enum: FlightDelay, SupplierFailure, HealthEmergency, WeatherForceMajeure, CustomerComplaint, Other), `status` (enum: Reported, InInvestigation, Resolved, Escalated), `costImpact` (currency), `resolutionPlan` (text), `resolvedAt` (datetime) y `reportedById`.
  * Crear la entidad `Feedback` con los campos: `name`, `contactId`, `opportunityId`, `npsScore` (int, 1-10), `sentiment` (enum: Promoter, Passive, Detractor), `comments` (text), `channel` (default: WhatsApp), `followUpRequired` (bool) y `followUpStatus` (enum: Pending, Contacted, Resolved, NotNeeded).
  * Configurar relaciones 1:N entre `Opportunity` $\to$ `Incident` y 1:N entre `Opportunity` $\to$ `Feedback`.
* **Criterios de Aceptación (DoD):**
  * `php command.php rebuild` aplica los esquemas en base de datos sin errores ni tablas huérfanas.

---

### Épica 2: Clasificación de Satisfacción y Manejo de Detractores

#### `TASK-044` — Hook de Automatización y Tareas para Detractores
* **Archivos:**
  * `custom/Espo/Custom/Hooks/Feedback/ClassifyNpsScore.php`
* **Descripción:**
  * Implementar hook en `beforeSave` para validar que `npsScore` esté comprendido estrictamente entre 1 y 10.
  * Asignar automáticamente el campo `sentiment`:
    * Puntuación 9-10 $\to$ `'Promoter'`, `followUpRequired = false`, `followUpStatus = 'NotNeeded'`.
    * Puntuación 7-8 $\to$ `'Passive'`, `followUpRequired = false`, `followUpStatus = 'NotNeeded'`.
    * Puntuación 1-6 $\to$ `'Detractor'`, `followUpRequired = true`, `followUpStatus = 'Pending'`.
  * En `afterSave`, si el registro es `'Detractor'`, instanciar automáticamente una entidad `Task` asignada al equipo de calidad con prioridad `'Urgent'` (*Heurística 9: Diagnóstico y Recuperación de Errores*).
* **Criterios de Aceptación (DoD):**
  * Guardar un `Feedback` con puntaje $\le 6$ genera de forma atómica una tarea urgente sin intervención manual del asesor.

---

### Épica 3: Consola Operativa en Destino

#### `TASK-045` — Vista de Tablero "En Viaje" con Contactos de Emergencia
* **Archivos:**
  * `custom/Espo/Custom/SelectManagers/Itinerario.php`
  * `custom/Espo/Custom/Resources/metadata/clientDefs/Itinerario.json`
* **Descripción:**
  * Crear el filtro de búsqueda primaria en el listado de itinerarios: `"currentInDestination"`.
  * Definir la cláusula SQL indexada: `status = 'Confirmado' AND startDate <= CURDATE() AND endDate >= CURDATE()`.
  * Configurar la tarjeta operativa en la vista del CRM para exponer: nombre del titular, pasajeros totales, servicio activo del día, voucher del proveedor y enlace directo al chat de Chatwoot (*Heurística 6: Reconocimiento antes que Recuerdo*).
* **Criterios de Aceptación (DoD):**
  * La consulta carga los pasajeros activos en destino en menos de 400 ms (*Umbral de Doherty*).

---

### Épica 4: Automatización Post-Viaje y Canal WhatsApp

#### `TASK-046` — Scheduled Job de Detección de Retornos
* **Archivos:**
  * `custom/Espo/Custom/Jobs/TriggerPostTripSurveyJob.php`
  * `custom/Espo/Custom/Resources/metadata/app/jobs.json`
* **Descripción:**
  * Registrar el Scheduled Job en EspoCRM para ejecución automática diaria (09:00 UTC).
  * Consultar itinerarios cerrados con `endDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)` y `status = 'Confirmado'`.
  * Despachar un webhook HTTP POST hacia Activepieces conteniendo: `{ itineraryId, contactId, phone, clientName, destination }`.
* **Criterios de Aceptación (DoD):**
  * El cron job se ejecuta sin advertencias en los logs de EspoCRM y despacha el payload hacia la cola de Activepieces.

#### `TASK-047` — Flujo de Ingesta y Disparo en Activepieces
* **Archivos / Artefactos:**
  * `docker/activepieces/flows/post-trip-nps-survey.json`
* **Descripción:**
  * **Paso 1 (Trigger):** Recibir webhook POST emitido por EspoCRM.
  * **Paso 2 (Salida WhatsApp):** Disparar plantilla HSM homologada de WhatsApp vía Chatwoot / Meta Cloud API con saludo de retorno y solicitud de calificación del 1 al 10 (*Peak-End Rule*).
  * **Paso 3 (Parser):** Escuchar el mensaje de respuesta del cliente en Chatwoot y extraer el dígito del score.
  * **Paso 4 (Ingesta CRM):** Invocar `POST /api/v1/Feedback` en la API de EspoCRM vinculando el score, comentarios y el ID de contacto.
* **Criterios de Aceptación (DoD):**
  * Responder a la encuesta en WhatsApp crea el registro de `Feedback` en EspoCRM en menos de 5 segundos.

---

### Épica 5: Aseguramiento de Calidad y Pruebas E2E

#### `TASK-048` — Suite de Pruebas Unitarias y E2E de Operación Post-Venta
* **Archivos:**
  * `custom/Espo/Custom/Tests/Unit/FeedbackClassificationTest.php`
  * `tests/integration/InTripOperationsE2ETest.php`
* **Descripción:**
  * **Test Unitario:** Validar clasificación matemática de puntajes (10 = Promoter, 8 = Passive, 4 = Detractor) y creación de la tarea urgente.
  * **Test de Aislamiento:** Validar que registrar un `Incident` con impacto de costo no modifique los balances ni los cobros en `Payment`.
  * **Test E2E:** Simular viaje completado ayer $\to$ ejecución del Scheduled Job $\to$ mock de webhook recibido $\to$ ingesta de Feedback y alerta de detractor.
* **Criterios de Aceptación (DoD):**
  * 100% de aserciones en verde bajo PHPUnit sin errores residuales.