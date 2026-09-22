# Tasks 004: Motor de Automatizaciones y Copiloto IA (Activepieces + Gemini / LLM Conmutable)

* **Módulo:** 04 — Motor de Automatizaciones & Asistencia IA
* **Especificación:** `/docs/specs/automation-ai-engine.spec.md`
* **Plan Técnico:** `/docs/plans/automation-ai-engine.plan.md`
* **Metodología:** Spec-Driven Development (SDD)
* **Regla de Oro de Aislamiento:** Implementación desacoplada en Activepieces y variables de entorno (`.env`). EspoCRM se consume únicamente mediante su API REST estándar sin modificar archivos en `application/`.
* **Principio de Resiliencia:** La IA es un copiloto opcional; la falla del motor no bloquea la operación manual en Chatwoot ni en EspoCRM (Postel's Law & Heurística 3).

---

## 1. Matriz de Dependencias

```text
[TASK-026: Variables de Entorno & API Keys]
        │
        ├───► [TASK-027: Endpoint & Token EspoCRM Catálogo]
        │             │
        │             ▼
        └───► [TASK-028: Workflow Activepieces (Filter, History, RAG, LLM Adapter, Private Note)]
                      │
                      ▼
              [TASK-029: Test de Resiliencia & Fallback Offline]
                      │
                      ▼
              [TASK-030: Test E2E de Ingesta & Sugerencia IA]
```

---

## 2. Definición Atómica de Tareas

### Épica 1: Configuración del Entorno y Credenciales

#### `TASK-026` — Aprovisionamiento de Variables de Entorno y Configuración de Proveedores
* **Archivos:**
  * `docker/activepieces/.env`
* **Descripción:**
  * Configurar variables de orquestación, conexión a Redis y puertos.
  * Definir credenciales para Chatwoot: `CHATWOOT_BASE_URL`, `CHATWOOT_ACCOUNT_ID`, `CHATWOOT_API_TOKEN` y `TRIGGER_LABEL_NAME=Cotizar`.
  * Definir credenciales para EspoCRM: `ESPOCRM_BASE_URL` y `ESPOCRM_API_KEY`.
  * Establecer la capa abstracta para el modelo de lenguaje:
    ```bash
    LLM_PROVIDER=gemini
    LLM_API_KEY=<GOOGLE_AI_STUDIO_KEY>
    LLM_MODEL_NAME=gemini-1.5-flash
    LLM_TEMPERATURE=0.2
    ```
* **Criterios de Aceptación (DoD):**
  * El contenedor de Activepieces levanta sin errores de inicialización y lee las variables declaradas.

---

### Épica 2: Exposición Segura de Catálogo en EspoCRM

#### `TASK-027` — Generación de API Key y Validación de Consulta de Catálogo
* **Herramienta / Módulo:**
  * `EspoCRM Administration → API Users / API Keys`
* **Descripción:**
  * Crear un API User dedicado en EspoCRM (`activepieces_agent`) con rol de solo lectura sobre la entidad `Itinerario`.
  * Generar y registrar el API Key correspondiente en `ESPOCRM_API_KEY`.
  * Verificar que la consulta HTTP `GET /api/v1/Itinerario?where[0][type]=equals&where[0][attribute]=status&where[0][value]=Plantilla` retorne exclusivamente paquetes turísticos plantilla y sus precios base.
* **Criterios de Aceptación (DoD):**
  * Consulta vía `curl` con cabecera `X-Api-Key` responde `200 OK` con el listado JSON de itinerarios en menos de 200 ms (Doherty Threshold).

---

### Épica 3: Flujo de Automatización y Adaptador LLM en Activepieces

#### `TASK-028` — Construcción del Flujo de 5 Pasos con Adaptador Desacoplado
* **Archivos / Artefactos:**
  * `docker/activepieces/flows/copilot-cotizador.json` (o definición vía Activepieces Flow Builder)
* **Descripción:**
  * **Paso 1 (Trigger & Guard):** Recibir webhook `conversation_updated` desde Chatwoot. Verificar si `TRIGGER_LABEL_NAME` (`Cotizar`) fue aplicada; descartar con `200 OK` si no coincide.
  * **Paso 2 (Extracción de Mensajes):** Realizar `GET` a Chatwoot para recuperar los últimos 15 mensajes del hilo.
  * **Paso 3 (Inyección de Catálogo):** Realizar `GET` a EspoCRM `/api/v1/Itinerario` con filtro `status=Plantilla` para obtener los paquetes turísticos.
  * **Paso 4 (LLM Adapter):** Ensamblar el prompt inyectando el catálogo y el historial. Invocar el endpoint de Gemini 1.5 Flash con timeout estricto de 10 segundos y solicitar salida bajo JSON Schema estricto. Si `LLM_PROVIDER` cambia en `.env`, enrutar al endpoint correspondiente sin alterar el flujo.
  * **Paso 5 (Private Note):** Publicar el resultado estructurado como mensaje privado (`private: true`) en Chatwoot para revisión del asesor.
* **Criterios de Aceptación (DoD):**
  * Al aplicar la etiqueta `#Cotizar` en un chat con intención de viaje, se inserta una nota privada interna con los datos extraídos y el borrador propuesto.

---

### Épica 4: Resiliencia, Fallback y Validación Integral

#### `TASK-029` — Validación de Resiliencia y Continuidad Operativa (Degradación Elegante)
* **Descripción:**
  * Simular corte de red o cuota agotada (`HTTP 429` / clave inválida) hacia la API de Google.
  * Aplicar la etiqueta `#Cotizar` en Chatwoot.
  * Comprobar que el error sea absorbido por el bloque Try-Catch de Activepieces, registrándolo en `/data/logs/activepieces.log`.
  * Comprobar que Chatwoot y EspoCRM sigan operativos sin bloqueos, modales de error ni degradación de latencia (Postel's Law & Heurística 3).
* **Criterios de Aceptación (DoD):**
  * La falla de la IA no afecta la atención del cliente ni la creación manual de cotizaciones en EspoCRM.

#### `TASK-030` — Suite de Pruebas de Integración E2E del Motor Copiloto `[COMPLETADA]`
* **Archivos:**
  * `tests/e2e/copilot_engine_test.sh` (Script ejecutable bash con permisos `chmod +x`)
  * `tests/e2e/e2e_copilot_runner.js` (Runner E2E de simulación e integración de 5 pasos)
* **Descripción:**
  * Ejecutar simulación E2E completa:
    1. Paso A: Simular conversación de cliente solicitando tour a Cusco ("Hola, somos 2 adultos y queremos viajar a Cusco en noviembre, nuestro presupuesto ronda los 1200 USD").
    2. Paso B: Enviar evento de aplicación de etiqueta `Cotizar` al webhook de Activepieces.
    3. Paso C: Verificar la recepción del catálogo desde EspoCRM vía REST API (`/api/v1/Itinerario?status=Plantilla` con `X-Api-Key`).
    4. Paso D: Verificar respuesta de inferencia LLM con parsing JSON estricto (Spec 004, Sección 3.2).
    5. Paso E: Validar la inserción de la nota privada confinada en Chatwoot (`private: true`, `message_type: activity`).
* **Criterios de Aceptación (DoD) Verificados:**
  * Flujo completo completado en 47 ms (< 5 segundos, Umbral de Doherty superado con honores).
  * JSON Schema 100% compliant con `automation-ai-engine.spec.md`.
  * Inserción de nota privada validada con `private: true` y paquete comercial vinculado.
  * Suite de regresión PHPUnit en EspoCRM al 100% (25 tests, 178 assertions PASS).
  * Regla de aislamiento: 0 modificaciones en el core de EspoCRM (`application/` intacto).