# Spec 002: Adapted Travel Sales Pipeline & Kanban Experience

## 1. Visión General
Esta especificación define la adaptación del embudo comercial de la entidad nativa `Opportunity` en EspoCRM para agencias de viajes B2C. Incluye la estandarización de las etapas de venta (*stages*), la configuración de la vista Kanban enfocada en variables clave del viaje, la gestión de motivos de pérdida y la interacción fluida entre el asesor comercial y el expediente operativo (`Itinerario`).

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Progresión del Embudo Comercial de Viajes
Como asesor comercial de viajes, quiero gestionar mis prospectos a través de etapas representativas del ciclo de venta turística para priorizar negociaciones y dar seguimiento oportuno.

* **Given** una `Opportunity` en curso.
* **When** el asesor interactúa con el campo `stage`.
* **Then** las únicas opciones disponibles deben ser:
  * `Prospecting` (Primer contacto / Consulta entrante)
  * `Qualification` (Briefing: fechas tentativas, pasajeros, presupuesto)
  * `Proposal` (Armado y envío de cotización/itinerario preliminar)
  * `Negotiation` (Ajustes de vuelos, hoteles o servicios)
  * `PaymentPending` (Aceptación verbal, a la espera de seña/abono)
  * `Closed Won` (Reserva pagada/confirmada)
  * `Closed Lost` (Venta descartada/cancelada)

### HU-02: Vista Kanban Turística y Reconocimiento Inmediato
Como asesor o gerente de ventas, quiero una vista de tablero Kanban donde cada tarjeta muestre el destino, fechas del viaje, margen bruto y canal de procedencia para evaluar el valor de cada oportunidad sin abrirla.

* **Given** el tablero Kanban de `Opportunity`.
* **When** se renderiza una tarjeta en cualquier columna activa.
* **Then** la tarjeta debe exponer de forma visible y jerarquizada:
  * Nombre de la oportunidad y contacto principal
  * Destino principal (`destination`)
  * Rango de fechas estimadas (`travelDates`)
  * Monto total estimado (`amount`)
  * Margen comercial proyectado (`projectedGrossProfit`)
  * Canal de origen (WhatsApp / Referido / Web)
* **Given** una tarjeta en el tablero Kanban.
* **When** el asesor la arrastra manualmente hacia la columna `Closed Won`.
* **Then** el sistema activa la compuerta de seguridad implementada en `ClosedWonGuard` (TASK-007) y rechaza el movimiento con un error si no existe un `Itinerario` confirmado vinculado[cite: 3].

### HU-03: Tipificación Obligatoria de Pérdida (Closed Lost)
Como director de agencia, quiero registrar el motivo por el cual se pierde una oportunidad para alimentar la reportería comercial y corregir desviaciones de precios o servicio.

* **Given** una `Opportunity` en cualquier etapa previa a cierre.
* **When** el usuario transiciona el `stage` a `Closed Lost`.
* **Then** el sistema exige de forma obligatoria el campo `lostReason` (`enum`: 'Precio / Presupuesto Alto', 'Eligió Competencia', 'Canceló Viaje', 'Sin Respuesta / Fantasma', 'Fechas / Cupos No Disponibles', 'Otro').
* **Given** un intento de mover una oportunidad a `Closed Lost` sin especificar `lostReason`.
* **Then** la interfaz y el backend bloquean el guardado exigiendo completar el motivo[cite: 3].

## 3. Entidades y Extensiones de Datos

### Extensiones sobre Entidad Nativa `Opportunity`:
*   `stage`: Modificación de la lista de opciones (`options`) y probabilidades por defecto:
    *   `Prospecting` (10%)
    *   `Qualification` (25%)
    *   `Proposal` (50%)
    *   `Negotiation` (75%)
    *   `PaymentPending` (90%)
    *   `Closed Won` (100%)
    *   `Closed Lost` (0%)
*   `destination`: Tipo `varchar(100)`, para almacenar el destino de interés antes de crear un itinerario.
*   `travelStartDate`: Tipo `date`, fecha estimada de inicio de viaje.
*   `travelEndDate`: Tipo `date`, fecha estimada de fin de viaje.
*   `leadSource`: Tipo `enum` ('WhatsApp', 'Instagram', 'Facebook', 'Referido', 'Sitio Web', 'Presencial').
*   `lostReason`: Tipo `enum` ('Precio Alto', 'Eligió Competencia', 'Canceló Viaje', 'Sin Respuesta', 'Sin Disponibilidad', 'Otro'), condicionalmente obligatorio en cierre perdido.
*   `lostReasonDetails`: Tipo `text`, notas adicionales de pérdida.

## 4. Reglas de Negocio y Permisos (ACL)

| Rol | Etapas Activas (Prospecting a PaymentPending) | Transición a Closed Won | Transición a Closed Lost |
| :--- | :--- | :--- | :--- |
| **Admin** | Edición total de montos y etapas | Permitida (sujeta a itinerario confirmado) | Permitida con motivo obligatorio |
| **Manager** | Edición total de montos y etapas | Permitida (sujeta a itinerario confirmado) | Permitida con motivo obligatorio |
| **Agent** | Edición de oportunidades asignadas | Disparada automáticamente vía confirmación de `Itinerario` (TASK-006) | Permitida con motivo obligatorio |

## 5. Puntos de Integración
*   **Respond.io:** Al crearse un contacto nuevo por mensaje entrante de WhatsApp, la automatización asigna `leadSource = 'WhatsApp'` e inicializa la oportunidad en `Prospecting`.
*   **Activepieces:** Escucha del cambio a `PaymentPending` para despachar recordatorios automatizados de abono al cliente vía WhatsApp.

## 6. Fuera de Alcance (Out of Scope)
*   Asignación automática equitativa de leads (Round-Robin entre asesores; se especificará en módulos posteriores).
*   Scoring predictivo por IA de la probabilidad de compra (módulo de reportería / analítica).

## 7. Preguntas y Puntos de Decisión para Aprobación
1. ¿Deseas que al pasar a `PaymentPending` el sistema requiera obligatoriamente tener un `Itinerario` creado en estado "Cotización", o se permite avanzar a esa etapa de forma libre?
2. ¿El campo `amount` de la oportunidad debe seguir siendo editable de forma manual en las etapas tempranas (`Prospecting`, `Qualification`), o debe ser siempre de solo lectura y calculado exclusivamente desde los itinerarios?

