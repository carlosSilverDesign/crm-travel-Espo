# Spec 003: WhatsApp Integration via Meta Cloud API & Chatwoot (Self-Hosted)

## 1. Visión General
Esta especificación define la integración técnica y funcional entre WhatsApp (vía Meta Cloud API oficial conectada a una instancia self-hosted de Chatwoot en Docker) y EspoCRM para agencias de viajes B2C. 

El modelo desacopla la **experiencia de conversación en vivo** (manejada de forma fluida por Chatwoot) del **expediente comercial, operativo y financiero** (gestionado en EspoCRM bajo `custom/Espo/Custom/`). El enlace se realiza mediante webhooks HTTP entrantes asegurados con Bearer Token, resolviendo contactos, distribuyendo prospectos de forma rotativa (Round-Robin) y habilitando enlaces directos al chat para los asesores.

---

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Inbound Webhook Seguro desde Chatwoot
Como sistema CRM, quiero recibir eventos HTTP POST desde Chatwoot y autenticarlos mediante un Bearer Token constante para proteger la base de datos contra accesos no autorizados.

* **Given** una petición HTTP `POST` entrante hacia `/api/v1/webhook/chatwoot`.
* **When** la cabecera `Authorization` contiene el valor esperado `Bearer <CHATWOOT_WEBHOOK_SECRET>` validado mediante comparación segura (`hash_equals`).
* **Then** el endpoint procesa el evento y responde `200 OK` con un payload JSON descriptivo.
* **Given** una petición sin la cabecera adecuada o con credenciales incorrectas.
* **When** se evalúa la autenticación.
* **Then** el sistema interrumpe el procesamiento y responde inmediatamente con HTTP `401 Unauthorized` (`{"error": "Unauthorized"}`).

### HU-02: Resolución de Contactos y Creación de Oportunidades
Como asesor comercial, quiero que un cliente nuevo cree automáticamente su contacto y oportunidad comercial, mientras que los mensajes de clientes existentes se asocien a su expediente sin duplicar fichas.

* **Given** un evento de nueva conversación (`conversation_created` o `message_created`) en Chatwoot.
* **When** el número telefónico del remitente (en formato E.164) NO existe en la entidad `Contact`.
* **Then** el sistema crea un nuevo `Contact` en EspoCRM con:
  * `firstName` y `lastName` (derivados del nombre de perfil de WhatsApp o asignado como "Viajero").
  * `phoneNumber` normalizado en formato internacional E.164.
  * `chatwootContactId` y `chatwootConversationId` asociados.
* **And** crea automáticamente una `Opportunity` vinculada con:
  * `name` = "Viaje [Destino / 'Por definir'] - [Nombre Contacto]".
  * `stage` = `Prospecting` (10% probabilidad).
  * `leadSource` = `WhatsApp`.
  * `assignedUser` = Asesor asignado por algoritmo Round-Robin.
  * `contactId` = ID del contacto recién creado.
* **Given** un mensaje de un cliente cuyo número YA existe en `Contact`.
* **When** se procesa la solicitud.
* **Then** el sistema recupera el `Contact` existente sin duplicarlo.
* **And** evalúa las oportunidades asociadas:
  * Si existe una `Opportunity` activa (`stage` diferente de `Closed Won` y `Closed Lost`), actualiza el `chatwootConversationId` y refresca `lastWhatsappMessageAt`.
  * Si todas las oportunidades previas están cerradas, crea una nueva `Opportunity` en etapa `Prospecting` vinculada al contacto existente.

### HU-03: Distribución Round-Robin con Reasignación Rápida
Como director comercial, quiero que los nuevos prospectos se distribuyan equitativamente entre los asesores activos, permitiendo su reasignación inmediata hacia administradores o guardia operativa si el asesor no puede atender el caso.

* **Given** una nueva `Opportunity` creada desde el webhook.
* **When** se asigna el usuario responsable (`assignedUserId`).
* **Then** el sistema consulta de forma transaccional los usuarios activos con rol `Agent` y asigna al siguiente según la rotación Round-Robin, actualizando el puntero global en configuración de forma atómica.
* **Given** una `Opportunity` asignada a un asesor.
* **When** el asesor o administrador utiliza la acción rápida "Escalar a Guardia / Admin".
* **Then** el sistema reasigna el registro al usuario administrador de guardia predeterminado y emite una notificación interna en el CRM.

### HU-04: Enlace Directo a la Conversación (Ley de Fitts y Heurística 6)
Como asesor, quiero acceder a la conversación viva en Chatwoot desde la ficha de la Oportunidad en EspoCRM para responder con un solo clic.

* **Given** una `Opportunity` con `chatwootConversationId` válido.
* **When** el usuario abre la vista de detalle en EspoCRM.
* **Then** se muestra un enlace de acción destacado "Abrir Conversación en Chatwoot" con la URL:
  `{chatwootBaseUrl}/app/accounts/{accountId}/conversations/{chatwootConversationId}`
* **When** se hace clic sobre la acción.
* **Then** se abre el chat en una pestaña nueva con el foco listo para responder.

---

## 3. Modelo de Datos y Extensiones de Metadatos

Toda modificación reside exclusivamente bajo `custom/Espo/Custom/Resources/metadata/`.

### 3.1. Extensiones en `Contact` (`custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json`):
* `chatwootContactId`: `varchar(100)` con índice (`index: true`), identificador único del contacto en Chatwoot.
* `chatwootConversationId`: `varchar(100)`, identificador de la última conversación.
* `lastWhatsappMessageAt`: `datetime`, marca de tiempo del último mensaje entrante.

### 3.2. Extensiones en `Opportunity` (`custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`):
* `chatwootConversationId`: `varchar(100)`, ID de la conversación activa asociada al negocio.
* `whatsappChannel`: `varchar(50)` (ej. "Línea Oficial WhatsApp").

### 3.3. Configuración del Sistema (`data/config.php`):
* `chatwootWebhookSecret`: Token estático para autenticación de peticiones entrantes.
* `chatwootBaseUrl`: URL de la instancia Chatwoot (ej. `https://chat.tuagencia.com`).
* `chatwootAccountId`: ID de cuenta principal en Chatwoot (ej. `1`).
* `roundRobinLastAgentId`: ID del último usuario asignado.

---

## 4. Arquitectura de Transporte y Seguridad
* **Endpoint:** `POST /api/v1/webhook/chatwoot`
* **Controlador Custom:** `custom/Espo/Custom/Controllers/WebhookChatwoot.php`
* **Servicio Auxiliar:** `custom/Espo/Custom/Services/LeadDistributionService.php` (Lógica de Round-Robin persistente)
* **Regla de Resiliencia (Ley de Postel):**
  * Si el payload de Chatwoot omite el nombre, se asigna `"Viajero"` por defecto.
  * Si el proceso de Round-Robin falla por ausencia de agentes disponibles, la oportunidad se asigna por defecto al primer usuario `Admin` activo, garantizando la persistencia sin bloquear el flujo.