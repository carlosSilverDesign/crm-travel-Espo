# Spec 004: Motor de Automatizaciones y Copiloto IA (Activepieces + Gemini / LLM Conmutable)

## 1. Visión General

Especificación funcional y técnica para el motor de asistencia comercial basado en Activepieces (Community Edition desplegado en Docker en Hetzner) y modelos de lenguaje de gran escala (iniciando con Gemini 1.5 Flash mediante API). El sistema asiste al asesor de viajes extrayendo requerimientos de las conversaciones de WhatsApp, consultando el catálogo interno de tours en EspoCRM y generando borradores de cotización como notas privadas en Chatwoot.

El diseño arquitectónico se basa en desacoplamiento total y degradación elegante: la indisponibilidad total o parcial de la capa de IA no interrumpe ni bloquea el flujo de trabajo manual del asesor en Chatwoot o EspoCRM.

---

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Disparo Bajo Demanda por Etiqueta (Heurística 3: Control y Libertad)

**Como** asesor de ventas,  
**quiero** decidir cuándo solicitar asistencia de la IA aplicando una etiqueta en Chatwoot,  
**para** evitar llamadas innecesarias en saludos o mensajes incompletos.

* **Given** una conversación activa en Chatwoot entre un cliente y un asesor.
* **When** el asesor añade la etiqueta `#Cotizar` (o `#AnalizarViaje`) al hilo de chat.
* **Then** Chatwoot emite un webhook `conversation_updated` hacia Activepieces.
* **And** Activepieces valida que la etiqueta añadida coincida con `TRIGGER_LABEL_NAME` antes de continuar el flujo.
* **And** si la etiqueta no coincide, el webhook se descarta de inmediato con código `HTTP 200` sin consumir tokens de inferencia.

### HU-02: Consulta Dinámica de Catálogo y Generación de Propuesta

**Como** asesor de ventas,  
**quiero** que la IA compare los requerimientos del cliente contra los paquetes y tours reales de la agencia en EspoCRM,  
**para** recibir un borrador con precios y servicios válidos.

* **Given** el disparo confirmado por etiqueta en Activepieces.
* **When** Activepieces recupera el historial reciente del chat (últimos 15 mensajes entrantes y salientes).
* **Then** consulta la API REST de EspoCRM (`GET /api/v1/Itinerario?where[status]=Plantilla`) para obtener los paquetes turísticos vigentes (destinos, precios base, servicios incluidos).
* **And** ensambla el prompt de sistema inyectando el catálogo extraído y el historial de conversación.
* **And** envía la carga a Gemini 1.5 Flash exigiendo una respuesta en formato JSON estructurado estricto.
* **And** publica una Nota Privada interna en la conversación de Chatwoot visible únicamente para el equipo, detallando:
  * Resumen del viaje: Destino detectado, fechas tentativas, cantidad de pasajeros y presupuesto estimado.
  * Paquete sugerido de la agencia con precio y código de referencia.
  * Borrador de respuesta listo para copiar, editar y enviar al cliente.

### HU-03: Resiliencia Operativa y Continuidad del Asesor (Fail-Safe / Postel's Law)

**Como** asesor y director de agencia,  
**quiero** que el trabajo continúe con absoluta normalidad si la IA o Activepieces sufren caídas, cortes de red o límites de cuota,  
**para** no interrumpir el flujo comercial ni la operación manual.

* **Given** un error de conexión, límite de tasa (`HTTP 429`), cuota agotada en Google AI Studio o detención del contenedor de Activepieces.
* **When** el asesor añade la etiqueta `#Cotizar` o interactúa con el chat.
* **Then** la interfaz de Chatwoot no muestra bloqueos, modales de error intrusivos ni pérdidas de mensajes.
* **And** la interfaz de EspoCRM permanece 100% operativa para cotizaciones y cierres manuales.
* **And** el error se captura de forma silente en el orquestador y se registra en `/data/logs/activepieces.log` para auditoría técnica.

### HU-04: Conmutabilidad de Proveedor LLM (Plugable Architecture)

**Como** administrador de sistemas,  
**quiero** alternar el proveedor de IA (de Gemini 1.5 Flash a OpenAI GPT-4o u otros) modificando únicamente variables de configuración,  
**para** no alterar el código de los servicios ni los flujos.

* **Given** el flujo de Activepieces configurado.
* **When** se actualizan las variables de entorno `LLM_PROVIDER`, `LLM_API_KEY` y `LLM_MODEL_NAME`.
* **Then** la pieza conectora conmuta de API de destino sin requerir cambios estructurales en el webhook ni en el parser JSON.

---

## 3. Modelo de Datos y Contratos de Integración

### 3.1. Extracción de Catálogo desde EspoCRM (REST API)

* **Endpoint consultado:** `GET /api/v1/Itinerario`
* **Autenticación:** Cabecera `X-Api-Key: <ESPOCRM_API_KEY>`
* **Filtros requeridos:**
  * `where[status] = "Plantilla"`
  * `where[deleted] = false`
* **Campos seleccionados:** `id`, `name`, `destination`, `totalSelling`, `description`.

### 3.2. Formato de Salida Estructurado de la IA (JSON Schema)

El modelo de lenguaje debe retornar exclusivamente un objeto JSON parseable sin texto adicional ni bloques markdown:

```json
{
  "detectedDestination": "string",
  "estimatedStartDate": "string (YYYY-MM-DD o null)",
  "paxCount": "integer",
  "budgetMentioned": "number o null",
  "matchedPackageId": "string o null",
  "matchedPackageName": "string o null",
  "suggestedDraftMessage": "string"
}
```

### 3.3. Inyección de Nota Privada en Chatwoot

* **Endpoint:** `POST /api/v1/accounts/{account_id}/conversations/{conversation_id}/messages`
* **Autenticación:** Cabecera `api_access_token: <CHATWOOT_API_TOKEN>`
* **Payload:**

```json
{
  "content": "🤖 **Sugerencia de Copiloto IA**:\n- **Destino:** {{detectedDestination}}\n- **Pasajeros:** {{paxCount}}\n- **Fechas:** {{estimatedStartDate}}\n- **Paquete Base:** {{matchedPackageName}}\n\n📝 **Borrador de Respuesta:**\n{{suggestedDraftMessage}}",
  "message_type": "activity",
  "private": true
}
```

---

## 4. Parámetros de Configuración Global (.env de Activepieces)

* `LLM_PROVIDER`: `"gemini"` (conmutable a `"openai"` o `"anthropic"`).
* `LLM_API_KEY`: Clave de acceso a la API del proveedor seleccionado.
* `LLM_MODEL_NAME`: `"gemini-1.5-flash"`.
* `ESPOCRM_API_KEY`: Token de acceso con permisos de solo lectura sobre `Itinerario`.
* `ESPOCRM_BASE_URL`: URL interna o externa del CRM (ej. `http://espocrm:80` o `https://crm.agencia.com`).
* `CHATWOOT_API_TOKEN`: Token de bot o agente con permisos para crear mensajes privados.
* `CHATWOOT_BASE_URL`: URL de la instancia de Chatwoot.
* `TRIGGER_LABEL_NAME`: `"Cotizar"`.