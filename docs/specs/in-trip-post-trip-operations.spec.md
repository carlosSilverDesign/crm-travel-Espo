# Spec 007: Operación en Destino, Incidentes y Post-Venta

* **Módulo:** 07 — Operación en Destino y Post-Venta
* **Fecha:** Septiembre 2026
* **Estado:** Especificación Formal (SDD)
* **Aislamiento Arquitectural:** 100% de extensiones bajo `custom/Espo/Custom/`. Sin modificaciones sobre `application/` ni el core de EspoCRM.
* **Integración Omnicanal:** Chatwoot Community Edition (Docker) + Meta WhatsApp Cloud API + Activepieces Engine

---

## 1. Visión General y Alcance del Módulo

Especificación funcional y técnica para la administración operativa en tiempo real durante la estancia de los viajeros en destino y el ciclo de cierre post-viaje. El sistema resuelve:
1. **Consola Operativa "En Viaje":** Vista centralizada para asesores y personal de guardia con filtros dinámicos por destino, fecha de servicio y estado del pasajero.
2. **Protocolo de Gestión de Incidentes:** Registro estructurado, reasignación de servicios/proveedores y disparo de contingencias operativas (retrasos de vuelos, cancelaciones, cambios de hotel/guía) sin corromper la contabilidad histórica.
3. **Ciclo Post-Venta Automatizado (NPS & Feedback):** Disparo programado de encuestas de satisfacción vía WhatsApp tras el retorno del pasajero, cálculo automático de métricas y derivación a campañas de fidelización o gestión de detracciones.

---

## 2. Historias de Usuario y Criterios de Aceptación (Gherkin)

### HU-01: Tablero de Control de Pasajeros "En Viaje" (Heurística 6 & Ley de Miller)
Como asesor de operaciones o agente de guardia, quiero visualizar en un solo tablero los pasajeros que se encuentran actualmente en destino con sus servicios del día, hoteles y teléfonos locales para resolver emergencias sin buscar en carpetas dispersas.

* **Given** reservas (`Opportunity` / `Itinerario`) en estado `"Closed Won"`.
* **When** la fecha actual (`CURRENT_DATE`) se encuentra entre la fecha de inicio y fin del itinerario.
* **Then** el sistema presenta al pasajero dentro de la vista de tablero operativo agrupado por Destino y Fecha.
* **And** la tarjeta de operación muestra:
  * Nombre del titular y total de acompañantes (Pax).
  * Servicio activo del día (Vuelo, Tour, Traslado) con proveedor local asignado.
  * Teléfono local de emergencia del guía/chofer y botón de enlace directo a Chatwoot.
* **And** el tablero responde a filtros en menos de 400 ms (*Umbral de Doherty*).

### HU-02: Registro y Gestión de Incidentes Logísticos (Heurística 5 & 9)
Como agente de guardia, quiero documentar inmediatamente una disrupción en destino (ej. vuelo cancelado o huelga de trenes) y registrar el plan de contingencia sin alterar los datos contables históricos.

* **Given** un pasajero activo en destino.
* **When** ocurre una contingencia y el agente crea un registro en la entidad `Incident`:
  * Asocia el `Itinerario` y el `ItemItinerario` afectado.
  * Selecciona severidad (`"Low"`, `"Medium"`, `"High"`, `"Critical"`) y categoría (`"FlightDelay"`, `"SupplierFailure"`, `"HealthEmergency"`, `"WeatherForceMajeure"`, `"CustomerComplaint"`, `"Other"`).
  * Ingresa las acciones de mitigación adoptadas y el costo de contingencia asumido.
* **Then** el sistema actualiza el estado del servicio a `"Afectado"` o `"Reprogramado"`.
* **And** notifica internamente al asesor comercial original mediante una notificación del sistema.
* **And** no altera los registros de `Payment` ni los balances ya conciliados.

### HU-03: Automatización de Encuesta Post-Viaje y NPS (Peak-End Rule & Ley de Tesler)
Como director de la agencia, quiero que el sistema envíe automáticamente un mensaje de bienvenida y una encuesta NPS por WhatsApp 24 horas después del retorno del pasajero, registrando su puntuación y comentarios en el CRM.

* **Given** un `Itinerario` cuya fecha de finalización fue el día anterior (`CURRENT_DATE == endDate + 1`).
* **When** el Cron Job nocturno de EspoCRM ejecuta la rutina de post-venta:
  * Dispara un webhook hacia Activepieces.
  * Activepieces envía plantilla homologada de WhatsApp vía Chatwoot: saludo de bienvenida y solicitud de calificación del 1 al 10.
* **When** el cliente responde en WhatsApp con su puntuación:
  * Activepieces parsea la respuesta numérica.
  * Crea el registro en `Feedback` vinculado al `Contact` y `Opportunity`.
  * Clasifica el resultado en Promoter (9-10), Passive (7-8) o Detractor (1-6).
* **Then** si es Detractor, genera automáticamente una tarea urgente (`Task`) asignada al supervisor de calidad con SLA de contacto < 2 horas.

---

## 3. Modelo de Datos y Entidades en EspoCRM

```text
       [Opportunity / Itinerario]
                   │
         ┌─────────┴─────────┐
         ▼                   ▼
    [Incident]           [Feedback]

    - severity           - npsScore (1-10)
    - category           - sentiment (Promoter/Passive/Detractor)
    - costImpact         - comments
    - status             - followUpRequired
```

### 3.1. Entidad `Incident` (Nueva Entidad Custom)
* `id`: varchar(24) [PK]
* `name`: varchar(150) (ej. "INC-2026-0042: Reprogramación Tren Ollantaytambo")
* `opportunity`: link (`Opportunity`) [Index: true]
* `itinerario`: link (`Itinerario`) [Index: true]
* `itemItinerario`: link (`ItemItinerario`) [Nullable]
* `severity`: enum(`"Low"`, `"Medium"`, `"High"`, `"Critical"`)
* `category`: enum(`"FlightDelay"`, `"SupplierFailure"`, `"HealthEmergency"`, `"WeatherForceMajeure"`, `"CustomerComplaint"`, `"Other"`)
* `status`: enum(`"Reported"`, `"InInvestigation"`, `"Resolved"`, `"Escalated"`) [default: `"Reported"`]
* `costImpact`: currency [default: 0.00] (Gastos extras asumidos por la agencia)
* `resolutionPlan`: text
* `resolvedAt`: datetime
* `reportedBy`: link (`User`)

### 3.2. Entidad `Feedback` (Nueva Entidad Custom)
* `id`: varchar(24) [PK]
* `name`: varchar(100) (ej. "NPS-2026-0089: Titular Reserva")
* `contact`: link (`Contact`) [Index: true]
* `opportunity`: link (`Opportunity`) [Index: true]
* `npsScore`: int [Rango 1 a 10]
* `sentiment`: enum(`"Promoter"`, `"Passive"`, `"Detractor"`) [readOnly: true]
* `comments`: text
* `followUpRequired`: bool [default: false]
* `followUpStatus`: enum(`"Pending"`, `"Contacted"`, `"Resolved"`, `"NotNeeded"`) [default: `"NotNeeded"`]

---

## 4. Seguridad y Restricciones de Dominio

1. **Inmutabilidad Financiera:** La creación o resolución de un `Incident` no altera los registros de `Payment` ni muta los montos base pactados en `Opportunity`. Cualquier gasto extra se audita en `costImpact` de forma segregada.
2. **Acceso Seguro a la Consola Operativa:** Las consultas del tablero "En Viaje" están protegidas por roles RBAC para evitar fuga de información de contacto o números de pasaporte a roles no operativos.
3. **Escalamiento Preventivo (Detractores):** Todo `Feedback` con calificación inferior a 7 bloquea el cierre del caso hasta que exista una nota de seguimiento documentada por un supervisor.