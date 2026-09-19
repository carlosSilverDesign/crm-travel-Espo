# Spec 002: Adapted Travel Sales Pipeline & Kanban Experience

## 1. Visión General
Adaptación integral del embudo comercial de la entidad nativa `Opportunity` en EspoCRM para agencias de viajes B2C. Estandariza las etapas comerciales del sector turístico, optimiza la vista Kanban con métricas del viaje (destino, fechas, margen bruto proyectado), gestiona los motivos de descarte (`Closed Lost`) y establece compuertas de seguridad transaccional entre el asesor comercial y el expediente operativo (`Itinerario`).

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Progresión del Embudo Comercial Turístico
Como asesor comercial de viajes, quiero mover mis oportunidades a través de etapas representativas del ciclo turístico para priorizar cotizaciones y acelerar los cierres.

* **Given** una `Opportunity` activa en el sistema.
* **When** el usuario visualiza o modifica el campo `stage`.
* **Then** las opciones disponibles son estrictamente:
  * `Prospecting` (Consulta inicial / Mensaje entrante sin calificar)
  * `Qualification` (Briefing: destino de interés, fechas tentativas, número de pasajeros)
  * `Proposal` (Itinerario en cotización elaborado y presentado al cliente)
  * `Negotiation` (Ajustes de tarifas, hoteles, vuelos o servicios adicionales)
  * `PaymentPending` (Aceptación formal del viaje; a la espera de seña o abono)
  * `Closed Won` (Reserva pagada y confirmada operativamente)
  * `Closed Lost` (Oportunidad descartada o cancelada)

### HU-02: Compuerta de Integridad en "Espera de Pago" (Regla Sección 7.1)
Como gerente de operaciones, quiero impedir que una oportunidad avance a `PaymentPending` si no cuenta con una cotización activa para evitar solicitar cobros sin respaldo de costos.

* **Given** una `Opportunity` en etapas `Prospecting`, `Qualification`, `Proposal` o `Negotiation`.
* **When** el usuario intenta cambiar el `stage` a `PaymentPending` (vía Kanban, vista de detalle o API).
* **Then** el backend verifica si existe al menos un `Itinerario` vinculado con estado `'Cotización'`.
* **Given** una oportunidad que **NO** tiene ningún `Itinerario` vinculado en estado `'Cotización'`.
* **When** se procesa la solicitud de guardado.
* **Then** el sistema interrumpe la persistencia y arroja una excepción HTTP 400 (`BadRequest`):  
  *"No es posible pasar a Espera de Pago sin un Itinerario en cotización vinculado. Cree o asocie un itinerario primero."*

### HU-03: Autoridad del Monto Financiero (Regla Sección 7.2)
Como asesor y director financiero, quiero estimar montos preliminares en prospección temprana pero asegurar que el valor del negocio refleje exactamente el itinerario cotizado una vez estructurado.

* **Given** una `Opportunity` en `Prospecting` o `Qualification` **sin itinerarios vinculados**.
* **When** el asesor registra o modifica el campo `amount`.
* **Then** el sistema permite la edición manual del valor financiero estimado.
* **Given** una `Opportunity` que tiene al menos un `Itinerario` vinculado.
* **When** se crea o actualiza un `Itinerario` asociado.
* **Then** el hook del backend sobreescribe `Opportunity.amount` con el valor exacto de `Itinerario.totalSelling` y bloquea la edición manual directa de dicho campo en la interfaz (modo solo-lectura).

### HU-04: Tarjetas Kanban con Chunking Cognitivo y Reconocimiento Inmediato
Como asesor de ventas, quiero un tablero Kanban que presente los datos esenciales del viaje en cada tarjeta para evaluar el pipeline sin necesidad de abrir cada registro individualmente.

* **Given** la vista Kanban de `Opportunity`.
* **When** se renderiza el tablero.
* **Then** cada tarjeta muestra de manera legible y estructurada:
  * Título de la oportunidad y contacto principal
  * Destino principal (`destination`)
  * Rango de fechas estimadas (`travelStartDate` - `travelEndDate`)
  * Monto de venta (`amount`)
  * Margen bruto proyectado (`projectedGrossProfit`, visible para rol Agent y superiores)
  * Canal de origen (`leadSource`: WhatsApp, Referido, Web, etc.)
* **Given** una tarjeta en el tablero Kanban.
* **When** el usuario la arrastra manualmente a la columna `Closed Won`.
* **Then** la guardia `ClosedWonGuard` (TASK-007) intercepta la acción y bloquea el movimiento con error si no existe un itinerario confirmado que respalde el cierre.

### HU-05: Tipificación Obligatoria de Pérdida (`Closed Lost`)
Como director comercial, quiero registrar el motivo de pérdida de cada venta descartada para alimentar los reportes de rendimiento y detectar fallas de mercado.

* **Given** una `Opportunity` en cualquier etapa activa.
* **When** el usuario cambia el `stage` a `Closed Lost`.
* **Then** el campo `lostReason` se vuelve estrictamente obligatorio.
* **Given** un intento de guardar `stage = 'Closed Lost'` con `lostReason` vacío o nulo.
* **Then** el sistema bloquea el guardado mediante validación de cliente y backend con un error descriptivo.

## 3. Modelo y Extensiones de Datos

### Extensiones en `Opportunity` (`custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`):
* `stage`: Modificación de la lista de opciones y probabilidades asignadas:
  * `Prospecting` (10%)
  * `Qualification` (25%)
  * `Proposal` (50%)
  * `Negotiation` (75%)
  * `PaymentPending` (90%)
  * `Closed Won` (100%)
  * `Closed Lost` (0%)
* `destination`: Tipo `varchar`, longitud 100, para registrar el destino durante prospección temprana.
* `travelStartDate`: Tipo `date`, fecha estimada de inicio de viaje.
* `travelEndDate`: Tipo `date`, fecha estimada de retorno.
* `projectedGrossProfit`: Tipo `currency`, calculado automáticamente desde el total de margen del itinerario activo.
* `leadSource`: Tipo `enum` (`['WhatsApp', 'Instagram', 'Facebook', 'Referido', 'Web', 'Presencial']`).
* `lostReason`: Tipo `enum` (`['Precio / Presupuesto Alto', 'Eligió Competencia', 'Canceló Viaje', 'Sin Respuesta / Fantasma', 'Fechas / Cupos No Disponibles', 'Otro']`).
* `lostReasonDetails`: Tipo `text`, detalles cualitativos del motivo de descarte.

## 4. Reglas de Negocio y Permisos (ACL)

| Rol | Etapas Tempranas (`Prospecting` / `Qualification`) | Etapa `PaymentPending` | Etapa `Closed Won` | Etapa `Closed Lost` |
| :--- | :--- | :--- | :--- | :--- |
| **Agent** | Edición de oportunidad y monto manual estimado | Permitido **solo** con Itinerario en Cotización | Disparado automáticamente vía confirmación de `Itinerario` (TASK-006) | Permitido con `lostReason` obligatorio |
| **Manager** | Edición total de datos y montos | Permitido **solo** con Itinerario en Cotización | Permitido si existe Itinerario Confirmado (TASK-007) | Permitido con `lostReason` obligatorio |
| **Admin** | Control total de campos | Sujeto a compuerta de integridad de Itinerario | Sujeto a compuerta de integridad de Itinerario | Permitido con `lostReason` obligatorio |

## 5. Puntos de Integración
* **Módulo 03 (WhatsApp / Respond.io):** Al crearse un contacto nuevo por mensaje entrante, el webhook inicializa la `Opportunity` en `Prospecting`, asigna `leadSource = 'WhatsApp'` y mapea el identificador de chat.
* **Módulo 04 (Activepieces + Claude API):** Al cambiar a `PaymentPending`, se dispara un flujo de notificación al cliente vía WhatsApp con el resumen de la propuesta y los datos bancarios o enlace de pago.

