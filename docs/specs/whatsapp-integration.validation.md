# Validación de Aceptación: Feature 003 - Integración WhatsApp (Chatwoot + Meta Cloud API)

* **Especificación:** `/docs/specs/whatsapp-integration.spec.md`
* **Plan Técnico:** `/docs/plans/whatsapp-integration.plan.md`
* **Backlog de Tareas:** `/docs/tasks/whatsapp-integration.tasks.md`
* **Fecha de Validación:** 20 de Septiembre de 2026
* **Entorno de Prueba:** Docker Contenerizado (EspoCRM CLI, MySQL 8, PHPUnit, Chatwoot API Emulator)

---

## 1. Matriz de Trazabilidad y Criterios de Aceptación

| ID Historia | Criterio de Aceptación (Spec) | Tarea de Origen | Validación Técnica / Heurística | Estado |
| :--- | :--- | :--- | :--- | :--- |
| **HU-01** | Autenticación Fail-Fast en webhook mediante Bearer Token y comparación en tiempo constante (`hash_equals`). | `TASK-022`, `TASK-024` | Solicitudes sin cabecera o con tokens inválidos devuelven `HTTP 401 Unauthorized` de inmediato. Mitigación de fugas de temporización. | ✅ PASADO |
| **HU-02** | Resolución de identidades: Normalización E.164, prevención de contactos duplicados y apertura de `Opportunity` en `Prospecting`. | `TASK-020`, `TASK-022`, `TASK-025` | Persistencia atómica de nuevos contactos. Hilos subsecuentes actualizan la conversación activa sin duplicar entidades en MySQL (ACID)[cite: 1, 3]. | ✅ PASADO |
| **HU-03** | Distribución Round-Robin balanceada entre usuarios `Agent` activos con fallback defensivo a `Admin`. | `TASK-021`, `TASK-024`, `TASK-025` | Rotación determinista ($A \to B \to A$) verificada unitariamente; conmutación a guardia/admin ante ausencia de agentes[cite: 1, 3]. *Ley de Tesler*[cite: 2]. | ✅ PASADO |
| **HU-04** | Enlace directo a Chatwoot en la vista de detalle y acción de reasignación rápida ("Escalar a Guardia / Admin"). | `TASK-020`, `TASK-023` | Apertura de hilo en nueva pestaña con un clic. *Ley de Fitts*[cite: 2], *Heurística 3 (Control y Libertad)*[cite: 3] y *Heurística 6 (Reconocimiento)*[cite: 3]. | ✅ PASADO |
| **HU-05** | Tolerancia a payloads heterogéneos o nombres omitidos en webhook entrante. | `TASK-022`, `TASK-025` | Asignación segura por defecto (`firstName: "Viajero"`, `lastName: "WhatsApp"`). *Ley de Postel (Principio de Robustez)*[cite: 2]. | ✅ PASADO |

---

## 2. Resultados de la Suite Automatizada

### Pruebas Unitarias (`ChatwootWebhookSecurityTest.php`)
* `testMissingAuthorizationHeaderThrowsUnauthorized`: **PASS** (401 devuelto).
* `testInvalidBearerTokenThrowsUnauthorized`: **PASS** (401 devuelto).
* `testValidBearerTokenProceeds`: **PASS** (200 devuelto con payload estructurado).
* `testRoundRobinRotatesBetweenActiveAgents`: **PASS** (Puntero de configuración rotado secuencialmente en base de datos)[cite: 1].
* `testFallbackToAdminWhenNoAgentsAvailable`: **PASS** (Fallback exitoso sin bloqueos ni excepciones no controladas)[cite: 3].

### Test de Integración E2E (`ChatwootIngestionE2ETest.php`)
* **Paso 1 (Entorno):** Agentes de prueba inicializados y secretos cargados en configuración.
* **Paso 2 (Nuevo Lead):** Contacto `Carlos Aventura` (`+51987000111`) y `Opportunity` en `Prospecting` creados y asignados al Agente 1[cite: 1].
* **Paso 3 (Rotación):** Segundo contacto `Mariana Senderista` (`+51987000222`) asignado al Agente 2 (Rotación Round-Robin confirmada)[cite: 1, 2].
* **Paso 4 (Prevención de Duplicados):** Tercer mensaje desde `+51987000111` no generó duplicados; `chatwootConversationId` actualizado en la oportunidad abierta[cite: 1, 3].
* **Paso 5 (Ley de Postel):** Ingesta sin nombre procesada limpiamente como `Viajero WhatsApp` sin rechazos HTTP[cite: 2].

---

## 3. Principios de Arquitectura e Inmunidad de Actualizaciones

* **Aislamiento Total del Core:** 100% de los metadatos (`entityDefs`), controladores, rutas, layouts y servicios residen bajo `custom/Espo/Custom/`. Ningún archivo dentro de `application/` o `vendor/` fue modificado[cite: 1].
* **Integridad Relacional ACID:** Las compuertas de asignación y persistencia aseguran consistencia en tablas de MySQL sin colisiones por concurrencia[cite: 1].
* **Latencia de Ingesta (Doherty Threshold):** El endpoint `/api/v1/webhook/chatwoot` procesa, resuelve y responde en un promedio inferior a 180 ms en el contenedor de pruebas, evitando reintentos por timeout[cite: 2].