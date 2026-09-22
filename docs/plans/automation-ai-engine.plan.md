# Plan Técnico 004: Motor de Automatizaciones y Copiloto IA (Activepieces + Gemini / LLM Conmutable)

* **Especificación de Referencia:** `/docs/specs/automation-ai-engine.spec.md`
* **Módulo:** 04 — Motor de Automatizaciones & Asistencia IA
* **Stack Tecnológico:** Activepieces Community Edition (Node.js en Docker), Google AI Studio (Gemini 1.5 Flash API), EspoCRM REST API (PHP 8.2+ Backend), Chatwoot Community Edition (Docker), Redis (Colas de tareas en Activepieces)
* **Regla de Oro de Aislamiento:** Todo flujo, webhook, pieza personalizada y script de orquestación reside de forma desacoplada en Activepieces y variables de entorno (`.env`). En EspoCRM no se tocan archivos del core en `application/`, únicamente se consumen endpoints estándar mediante API Key dedicada.

---

## 1. Arquitectura del Flujo y Topología de Red

```
[Asesor en Chatwoot]
         │ (Aplica etiqueta '#Cotizar')
         ▼
 [Chatwoot Webhook Outbound] ──► POST /api/v1/webhooks/activepieces (conversation_updated)
                                             │
                                             ▼
                                [Activepieces Docker Container]
                                ┌───────────────────────────────────────────┐
                                │ 1. Filtro: ¿label_added == 'Cotizar'?     │
                                │    NO ──► HTTP 200 OK (Descartar silente) │
                                │    SÍ                                     │
                                │     │                                     │
                                │ 2. GET Chatwoot: Últimos 15 mensajes      │
                                │     │                                     │
                                │ 3. GET EspoCRM: /api/v1/Itinerario        │
                                │    (status=Plantilla, deleted=false)      │
                                │     │                                     │
                                │ 4. LLM Service Adapter (Gemini 1.5 Flash) │
                                │    (Fallback seguro / Try-Catch total)    │
                                │     │                                     │
                                │ 5. POST Chatwoot: Mensaje Privado         │
                                │    (Borrador sugerido para el asesor)     │
                                └───────────────────────────────────────────┘
```

### Principios de UX y Heurística Aplicados

* **Heurística 3: Control y Libertad del Usuario (NN/g):** El flujo es bajo demanda por etiqueta. El asesor decide conscientemente cuándo recurrir a la IA, evitando llamadas innecesarias ante saludos simples.
* **Degradación Elegante y Fail-Safe (Postel's Law):** Si la API de Gemini alcanza el límite de tasa (`HTTP 429`), la red falla o Activepieces se detiene, el CRM y Chatwoot permanecen 100% receptivos. El asesor nunca ve bloqueada su pantalla ni se le impide contestar o cotizar manualmente.
* **Ley de Tesler (Conservación de la Complejidad):** La extracción de entidades heterogéneas en lenguaje natural (fechas, pasajeros, destinos, presupuesto) y el emparejamiento con el catálogo es resuelta por la IA en el backend, entregando un bloque pre-procesado al humano.
* **Umbral de Doherty (<400 ms en UI):** El asesor no espera a que la IA procese la respuesta; Chatwoot confirma la etiqueta de inmediato y la nota privada se añade asíncronamente en segundo plano.

---

## 2. Definición del Contenedor y Variables de Entorno

Activepieces se ejecuta en el mismo VPS de Hetzner junto con EspoCRM y Chatwoot, comunicándose a través de una red interna de Docker (`travel_network`).

### 2.1. Archivo `.env` de Activepieces (`/docker/activepieces/.env`)

```bash
# Configuración Base del Orquestador
AP_ENGINE_EXECUTABLE_PATH=/usr/src/app/packages/engine/dist/main.js
AP_REDIS_HOST=redis
AP_REDIS_PORT=6379

# Integración Chatwoot
CHATWOOT_BASE_URL=http://chatwoot-web:3000
CHATWOOT_ACCOUNT_ID=1
CHATWOOT_API_TOKEN=bot_agent_token_secret_xyz
TRIGGER_LABEL_NAME=Cotizar

# Integración EspoCRM
ESPOCRM_BASE_URL=http://espocrm:80
ESPOCRM_API_KEY=espocrm_api_key_module_04_secret

# Abstracción LLM (Intercambiable)
LLM_PROVIDER=gemini
LLM_API_KEY=AIzaSy_Gemini_1_5_Flash_Free_Tier_Key
LLM_MODEL_NAME=gemini-1.5-flash
LLM_TEMPERATURE=0.2
```

---

## 3. Diseño del Flujo de Automatización (Workflow JSON / Flow Definition)

El flujo en Activepieces se compone de 5 pasos secuenciales encapsulados bajo una política de manejo de errores pasiva (*Continue on Failure* / *Try-Catch*).

### Paso 1: Trigger Webhook (Recepción de Chatwoot)
* **Tipo:** Webhook `POST` receptor.
* **Filtro de Guarda (Fail-Fast):**
  * Evalúa `event == 'conversation_updated'`.
  * Verifica si el array `changed_attributes` contiene `labels` y si la etiqueta activa es exactamente `TRIGGER_LABEL_NAME` (`"Cotizar"`).
  * Si la condición no se cumple, finaliza la ejecución con código `HTTP 200` sin procesar pasos posteriores.

### Paso 2: Extracción de Historial de Conversación
* **Acción:** HTTP Request a la API interna de Chatwoot.
* **Endpoint:** `GET {{CHATWOOT_BASE_URL}}/api/v1/accounts/{{CHATWOOT_ACCOUNT_ID}}/conversations/{{conversation.id}}/messages`
* **Encabezados:** `api_access_token: {{CHATWOOT_API_TOKEN}}`
* **Transformación (Node.js Script):** Filtra los últimos 15 mensajes, extrayendo únicamente el rol del remitente (`client` o `agent`) y el contenido de texto para minimizar el consumo de tokens de entrada.

### Paso 3: Consulta Dinámica de Catálogo a EspoCRM
* **Acción:** HTTP Request a la API REST de EspoCRM.
* **Endpoint:** `GET {{ESPOCRM_BASE_URL}}/api/v1/Itinerario?where[0][type]=equals&where[0][attribute]=status&where[0][value]=Plantilla&select=id,name,destination,totalSelling,description&maxSize=20`
* **Encabezados:** `X-Api-Key: {{ESPOCRM_API_KEY}}`
* **Resultado:** Lista JSON simplificada con paquetes de viaje disponibles, destinos y precios base.

### Paso 4: Adaptador LLM y Ejecución de Prompt (Gemini 1.5 Flash)
* **Patrón de Software:** Adapter Pattern para desacoplar el proveedor de inferencia. Si `LLM_PROVIDER == 'gemini'`, se invoca el endpoint de Google AI Studio; si en el futuro se cambia a `'openai'`, el adaptador redirige a `/v1/chat/completions` sin modificar los pasos 1, 2, 3 y 5.
* **Endpoint Gemini:** `POST https://generativelanguage.googleapis.com/v1beta/models/{{LLM_MODEL_NAME}}:generateContent?key={{LLM_API_KEY}}`
* **Encabezados:** `Content-Type: application/json`
* **System Instruction / Contexto Inyectado:**

```plaintext
Eres el copiloto comercial interno de una agencia de viajes B2C.
Tu tarea es analizar el historial de conversación entre un cliente y un asesor, extraer las variables clave del viaje, compararlas contra el CATÁLOGO DE TOURS DISPONIBLES y generar un borrador de propuesta.

CATÁLOGO DE TOURS DE LA AGENCIA:
{{catalogo_espocrm_json}}

REGLAS CRÍTICAS:
1. Si el cliente menciona un destino que coincide con el catálogo, selecciona el paquete más adecuado y cita su precio base.
2. Si no hay coincidencia exacta o los datos son insuficientes, sugiere el paquete más cercano o redacta un mensaje solicitando las variables faltantes (fechas, pax, presupuesto).
3. Responde ÚNICAMENTE un objeto JSON válido con la estructura solicitada, sin markdown, comillas triples ni texto conversacional exterior.
```

* **Esquema de Salida Requerido (JSON Schema):**

```json
{
  "detectedDestination": "string",
  "estimatedStartDate": "string o null",
  "paxCount": "number",
  "budgetMentioned": "number o null",
  "matchedPackageId": "string o null",
  "matchedPackageName": "string o null",
  "suggestedDraftMessage": "string"
}
```

### Paso 5: Publicación de Nota Privada en Chatwoot
* **Acción:** HTTP Request a la API de Mensajes de Chatwoot.
* **Endpoint:** `POST {{CHATWOOT_BASE_URL}}/api/v1/accounts/{{CHATWOOT_ACCOUNT_ID}}/conversations/{{conversation.id}}/messages`
* **Encabezados:** `api_access_token: {{CHATWOOT_API_TOKEN}}`
* **Payload JSON:**

```json
{
  "content": "🤖 **Copiloto IA: Propuesta Sugerida**\n\n• **Destino:** {{step4.detectedDestination}}\n• **Pasajeros:** {{step4.paxCount}}\n• **Fechas:** {{step4.estimatedStartDate}}\n• **Paquete Sugerido:** {{step4.matchedPackageName}}\n\n📋 **Borrador para enviar al cliente:**\n\"{{step4.suggestedDraftMessage}}\"",
  "message_type": "activity",
  "private": true
}
```

---

## 4. Política de Continuidad Operativa (Resiliencia & Degrado Controlado)

Para cumplir con el requerimiento de que nada debe impedir que el asesor siga con su trabajo, el flujo técnico implementa las siguientes compuertas de seguridad:

* **Timeout Estricto:** La llamada a Gemini o al endpoint de IA tiene un timeout máximo de 10 segundos. Si excede ese límite, el orquestador aborta la petición de IA sin reintentos masivos que saturen la cola.
* **Try-Catch de Nivel Superior (Activepieces):** Los pasos 4 y 5 están contenidos dentro de un bloque de tolerancia a fallos. Si la API de Google devuelve `429 Too Many Requests`, `500 Internal Error` o fallo de autenticación de token, el orquestador escribe un registro de auditoría en log y finaliza silenciosamente.
* **Inexistencia de Bloqueos en Chatwoot/EspoCRM:** Chatwoot jamás espera una respuesta sincrónica de Activepieces para permitir el envío o recepción de mensajes. El asesor puede continuar chateando, enviando PDFs manuales o cotizando en EspoCRM sin experimentar degradación en la interfaz.

---

## 5. Estrategia de Pruebas y Validación (Fases SDD 5 y 6)

### 5.1. Prueba de Filtrado por Etiqueta (Unitario de Flujo)
* Disparar webhook con etiqueta `Soporte`. Validar que el flujo finalice en el Paso 1 con consumo cero de tokens.
* Disparar webhook con etiqueta `Cotizar`. Validar avance inmediato al Paso 2.

### 5.2. Prueba de Integración EspoCRM REST
* Ejecutar consulta a `/api/v1/Itinerario` con token de prueba. Validar que la respuesta contenga únicamente plantillas válidas con estado `Plantilla` y campos de tarifa comercial.

### 5.3. Prueba de Inferencia con Fallback (Simulación Cuota Cero / Offline)
* Configurar una API Key errónea o vacía en `LLM_API_KEY`.
* Añadir la etiqueta `Cotizar` en una conversación de Chatwoot.
* **Aserción:** La conversación en Chatwoot continúa fluida, no aparecen ventanas de error, y el log de Activepieces documenta el error `401/403 Provider Auth Failed` sin tumbar el contenedor.

### 5.4. Prueba E2E de Inyección Exitosa
* Conversación simulada solicitando paquete a Cusco para 2 personas.
* Aplicar etiqueta `Cotizar`.
* **Aserción:** En menos de 5 segundos aparece una nota privada en amarillo dentro del chat con la extracción de pasajeros, el paquete de EspoCRM vinculado y el borrador de respuesta redactado.