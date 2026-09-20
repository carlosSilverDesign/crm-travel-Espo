# Plan Técnico 003: Integración WhatsApp (Chatwoot Self-Hosted + Meta Cloud API)

* **Especificación:** `/docs/specs/whatsapp-integration.spec.md`
* **Módulo:** 03 — Integración WhatsApp & Omnicanalidad
* **Stack Tecnológico:** EspoCRM (ORM RDB, PHP 8.2+), Chatwoot Community Edition (Docker), Meta WhatsApp Cloud API, MySQL 8 (ACID)[cite: 1]
* **Regla de Oro de Aislamiento:** Todo el código y metadatos residen exclusivamente en `custom/Espo/Custom/`. El core de EspoCRM permanece intacto[cite: 1].

---

## 1. Arquitectura de Metadatos y Extensiones de Esquema

### 1.1. Modificación en `Contact` (`custom/Espo/Custom/Resources/metadata/entityDefs/Contact.json`)
Declaración de campos aditivos para evitar duplicidad y mantener el hilo:
* `chatwootContactId`: `varchar(100)`, indexado (`index: true`), solo lectura[cite: 1].
* `chatwootConversationId`: `varchar(100)`, solo lectura[cite: 1].
* `lastWhatsappMessageAt`: `datetime`, solo lectura[cite: 1].

### 1.2. Modificación en `Opportunity` (`custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`)
* `chatwootConversationId`: `varchar(100)`, indexado (`index: true`), solo lectura[cite: 1].
* `whatsappChannel`: `varchar(50)`, default: `"Línea Oficial WhatsApp"`[cite: 1].

### 1.3. Configuración del Sistema (`data/config.php`)
* `chatwootWebhookSecret`: Token alfanumérico seguro para validación Bearer[cite: 1].
* `chatwootBaseUrl`: Dominio de Chatwoot (ej. `https://chat.agencia.com`).
* `chatwootAccountId`: ID numérico de la cuenta en Chatwoot (ej. `1`).
* `roundRobinLastAgentId`: ID del último asesor asignado en la rotación[cite: 1].

---

## 2. Enrutamiento y Controlador de Webhook

### 2.1. Definición de Ruta (`custom/Espo/Custom/Resources/routes.json`)
* Método: `POST`[cite: 1]
* Ruta: `/webhook/chatwoot`[cite: 1]
* Controlador: `WebhookChatwoot`[cite: 1]
* Acción: `receive`[cite: 1]
* `auth`: `false` (la autenticación se delega al controlador mediante Bearer Token)[cite: 1].

### 2.2. Lógica del Controlador (`custom/Espo/Custom/Controllers/WebhookChatwoot.php`)
1. **Seguridad (Doherty Threshold & Fail-Fast):** Validar cabecera `Authorization: Bearer <TOKEN>` usando `hash_equals()`[cite: 1, 2]. Si es inválida, responder HTTP 401[cite: 1].
2. **Filtrado de Eventos:** Procesar solo eventos entrantes (`conversation_created` o mensajes de clientes) para evitar bucles[cite: 1].
3. **Ley de Postel (Sanitización y Normalización):** Extraer el número telefónico y normalizarlo a formato internacional E.164 (`+51...`). Si el nombre no viene en el payload, asignar `"Viajero"` por defecto[cite: 2].
4. **Resolución de Contacto:** Buscar por `phoneNumber` o `chatwootContactId`[cite: 1]. Si no existe, crear un nuevo `Contact`[cite: 1]. Si existe, actualizar marcas de tiempo e IDs[cite: 1].
5. **Resolución de Oportunidad:**
   * Si el contacto tiene una `Opportunity` activa (`stage NOT IN ('Closed Won', 'Closed Lost')`), vincular el `chatwootConversationId`[cite: 1].
   * Si no tiene oportunidades activas, solicitar el siguiente asesor al `LeadDistributionService` y crear una nueva `Opportunity` en etapa `Prospecting` (10% probabilidad) con `leadSource = 'WhatsApp'`[cite: 1].
6. **Respuesta Rápida:** Devolver `HTTP 200 OK` con JSON confirmando el procesamiento[cite: 1].

---

## 3. Servicio de Distribución de Leads (`custom/Espo/Custom/Services/LeadDistributionService.php`)

* **Algoritmo Round-Robin Atómico:**
  1. Consultar mediante consulta SQL relacional a los usuarios activos con rol `Agent` ordenados por ID[cite: 1].
  2. Si no hay agentes disponibles, fallback automático al primer usuario con rol `Admin`[cite: 1].
  3. Obtener `roundRobinLastAgentId` de la configuración del sistema, determinar el siguiente índice de forma rotativa `(i + 1) % N` y actualizar la configuración de forma persistente[cite: 1].
  4. Retornar el ID del asesor asignado[cite: 1].

---

## 4. Experiencia Visual y Enlaces de Acción (UX)

### 4.1. Vista de Detalle (`custom/Espo/Custom/Resources/layouts/Opportunity/detail.json`)
* Integrar el campo `chatwootConversationId` en el panel *"Información Comercial del Viaje"*[cite: 1, 3].
* Añadir enlace de acción rápida:
  * Etiqueta: *"Abrir Chat en Chatwoot"*[cite: 3].
  * URL: `{chatwootBaseUrl}/app/accounts/{accountId}/conversations/{chatwootConversationId}`.
  * Ley de Fitts: acceso en una pestaña nueva con un solo clic[cite: 2].

### 4.2. Acción de Reasignación Rápida
* Incorporar la acción *"Escalar a Guardia / Admin"* en el menú de la `Opportunity` para reasignar el registro en caso de que el asesor no pueda atenderlo (Heurística 3 de Nielsen: Control y Libertad)[cite: 3].