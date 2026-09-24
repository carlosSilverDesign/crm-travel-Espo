# Spec 008: Reportería Comercial, Rentabilidad Real y Analítica de Operación (Agencia Piloto)

* **Módulo:** 08 — Reportería Comercial y Analítica de Rentabilidad
* **Fecha:** Septiembre 2026
* **Estado:** Especificación Formal (SDD)
* **Enfoque de Negocio:** Single-Tenant para Agencia de Viajes Real (Cero métricas vanidosas, cero márgenes "de papel").
* **Aislamiento Arquitectural:** 100% de extensiones bajo `custom/Espo/Custom/` y `client/custom/`. Cero modificaciones sobre `application/` ni el core de EspoCRM.
* **Integración Omnicanal y Analítica:** Chatwoot Reporting API (First Response Time humano) + Apache ECharts (Dashlets interactivos nativos).

---

## 1. Visión General y Alcance del Módulo

Esta especificación formaliza la capa de inteligencia de negocio, control de gestión y analítica para el dueño y los líderes de equipo de una agencia de viajes real. El objetivo es proporcionar visibilidad operativa y financiera transparente para tomar decisiones basadas en datos auditados y no en proyecciones optimistas.

El módulo resuelve tres pilares analíticos fundamentales:

1. **Conversión Etapa a Etapa y Tiempos en Pipeline:**
   - Auditoría determinista de cada transición de etapa en `Opportunity`.
   - Medición de la tasa de conversión real entre etapas ($E_n \to E_{n+1}$) y tasas de abandono (*drop-off rate*).
   - Tiempos de permanencia en cada etapa expresados en **mediana y percentil 90 (P90)**.
   - Segmentación por canal de captación (`leadSource`: WhatsApp Chatwoot, Web, Referido, Redes Sociales, Presencial).
   - Pareto de motivos de descarte (`lostReason`).

2. **Rentabilidad Bruta Real Consolidada (Cero Ganancias de Papel):**
   - **Regla de Oro de Liquidación:** Una venta solo ingresa al cómputo de rentabilidad bruta real cuando cumple copulativamente:
     1. Ingreso efectivamente conciliado en banco (`Payment.status = 'Confirmed'`).
     2. Costo del servicio respaldado por un voucher/confirmación del operador (`BudgetLine` con `supplierId` / `ItineraryItem.status in ['Confirmado', 'Emitido']`).
   - Margen Bruto Real Liquidado = $\sum \text{Pagos Conciliados} - \sum \text{Costos con Voucher Confirmado}$.
   - Agregación multidimensional: por Destino (`destination`), por Operador Receptivo (`supplierId`) y por Tipo de Cliente (`clientType`: `B2C_Direct` vs `B2B_Corporate`).
   - Embudo de "Dinero en Cartera / No Conciliado": visualización desacoplada de montos pendientes de cobro o servicios sin confirmar para no distorsionar la liquidez real.

3. **Desempeño y Tiempos de Respuesta Humana por Asesor:**
   - Tiempo de Primera Respuesta Humana (*First Response Time - FRT*), excluyendo de forma estricta bots, respuestas automáticas de bienvenida y copilotos IA.
   - Medición en **mediana y P90** (eliminando distorsiones de promedios simples provocadas por mensajes entrantes en la madrugada).
   - Discriminación horaria: Horario laboral de la agencia (Lunes a Viernes 09:00 - 18:00) vs. fuera de horario.
   - Consumo estructurado desde la Reporting API nativa de Chatwoot.

4. **Calidad y Salud Operativa en Destino:**
   - Termómetro NPS consolidado (-100 a +100) con distribución porcentual de Promotores (9-10), Pasivos (7-8) y Detractores (1-6).
   - Impacto financiero de contingencias logísticas: Total de gastos de remediación asumidos por la agencia (`Incident.costImpact`) restados de la utilidad neta.
   - Cumplimiento de SLA en la contención de detractores (< 2 horas desde la ingesta de la calificación negativa).

---

## 2. Historias de Usuario y Criterios de Aceptación (Gherkin)

### HU-01: Auditoría de Embudo y Conversión por Canal (Heurística 6 & Ley de Tesler)
Como director de la agencia de viajes, quiero analizar la tasa de conversión etapa a etapa y el tiempo que tardan los prospectos en avanzar, segmentado por canal de origen, para identificar cuellos de botella comerciales.

* **Given** oportunidades comerciales avanzando a través de las etapas del pipeline (`Prospecting` $\to$ `Qualification` $\to$ `Proposal` $\to$ `Negotiation` $\to$ `PaymentPending` $\to$ `Closed Won` / `Closed Lost`).
* **When** el asesor o el sistema modifica la etapa (`stage`) de una `Opportunity`:
  * El sistema registra automáticamente en `OpportunityStageHistory` la fecha de entrada (`enteredAt`), la fecha de salida (`exitedAt`) y la duración en segundos (`durationSeconds`).
* **Then** el dashboard de conversión calcula:
  * Tasa de avance etapa a etapa: $\frac{\text{Oportunidades que pasaron a } E_{i+1}}{\text{Oportunidades que llegaron a } E_i} \times 100$.
  * Mediana y P90 del tiempo transcurrido en cada etapa por canal (`leadSource`).
* **And** las oportunidades descartadas muestran la distribución de `lostReason` para auditoría cualitativa.

### HU-02: Rentabilidad Bruta Real Liquidada vs Dinero de Papel (Heurística 5: Prevención de Errores)
Como gerente financiero o dueño de la agencia, quiero ver la rentabilidad real de los viajes finalizados o en curso basada exclusivamente en transferencias bancarias verificadas y vouchers emitidos, para no tomar decisiones sobre dinero no recaudado.

* **Given** reservas con cobranzas en `Payment` y servicios en `Itinerario` / `BudgetLine`.
* **When** se solicita el reporte de rentabilidad para un rango de fechas:
  * El sistema suma únicamente los cobros cuyo estado es `'Confirmed'` (`verifiedById` no nulo).
  * El sistema suma únicamente los costos de operadores cuyos ítems poseen voucher confirmado o emitido.
  * Calcula el margen bruto real: $\text{Margen Real} = \text{Ingreso Reconciliado} - \text{Costo Confirmado Operador}$.
* **Then** el sistema presenta el margen agregado por Destino y por Proveedor/Operador.
* **And** segrega las ventas directas (`clientType = 'B2C_Direct'`) de las cuentas corporativas (`clientType = 'B2B_Corporate'`).
* **And** mantiene en una sección separada ("Embudo de Recaudación") el saldo pendiente (`pendingBalance`) y las cotizaciones no confirmadas, sin sumarlas al margen real.

### HU-03: Auditoría de Velocidad de Atención por Asesor (Chatwoot First Response Time)
Como jefe de ventas, quiero medir el tiempo de primera respuesta humana de cada asesor en mediana y P90 dentro del horario laboral, para garantizar un estándar de servicio ágil sin penalizar la atención nocturna.

* **Given** conversaciones entrantes por WhatsApp atendidas por el equipo comercial en Chatwoot.
* **When** se consulta la analítica de rendimiento de agentes:
  * El sistema consulta la API de reportes de Chatwoot (`/api/v1/accounts/{id}/reports/conversations?type=agent`).
  * Filtra únicamente las respuestas emitidas por usuarios humanos (excluyendo bots de bienvenida y copilotos IA).
* **Then** reporta para cada asesor:
  * Mediana de First Response Time (FRT) en minutos.
  * Percentil 90 (P90) de FRT en minutos.
  * Conversaciones totales asignadas vs oportunidades ganadas (tasa de cierre personal).
* **And** permite filtrar entre horario de oficina (09:00 - 18:00) y horario extendido.

### HU-04: Cuadro de Mando de Calidad y Contingencias en Destino (Peak-End Rule)
Como gerente de operaciones, quiero supervisar el Net Promoter Score (NPS) del mes, los costos imprevistos asumidos por contingencias y el tiempo de respuesta ante reclamos de clientes.

* **Given** encuestas de satisfacción registradas en `Feedback` y disrupciones registradas en `Incident`.
* **When** se renderiza el tablero de calidad:
  * El sistema calcula el NPS Score: $\% \text{Promoters (9-10)} - \% \text{Detractors (1-6)}$.
  * Suma el impacto económico total de contingencias asumidas por la agencia (`costImpact`).
  * Evalúa el cumplimiento de SLA de las tareas de atención a detractores (`Task` urgente resuelta en < 2 horas).
* **Then** expone los indicadores visuales mediante velocímetros/tacómetros (*gauges*) y gráficos interactivos con Apache ECharts.

---

## 3. Modelo de Datos y Contratos de Entidades

### 3.1. Entidad `OpportunityStageHistory` (Auditoría de Embudo)
* **Tabla MySQL:** `opportunity_stage_history`
* **Campos:**
  * `id`: varchar(17) — Clave primaria EspoCRM.
  * `opportunityId`: varchar(17) [Index] — Relación a `Opportunity`.
  * `stage`: enum [Index] — Etapa auditada (`Prospecting`, `Qualification`, `Proposal`, `Negotiation`, `PaymentPending`, `Closed Won`, `Closed Lost`).
  * `enteredAt`: datetime [Index] — Momento en que la oportunidad ingresó a la etapa.
  * `exitedAt`: datetime nullable — Momento en que la oportunidad cambió a otra etapa (nulo si es la etapa activa).
  * `durationSeconds`: int unsigned nullable — Duración en segundos transcurrida en la etapa.
  * `leadSource`: enum nullable [Index] — Canal de procedencia original (`WhatsApp`, `Web`, `Referido`, etc.).
  * `assignedUserId`: varchar(17) nullable [Index] — Asesor responsable durante la permanencia en la etapa.

### 3.2. Extensión de `Opportunity`
* **Campo `clientType`:**
  * Tipo: `enum`
  * Opciones: `["B2C_Direct", "B2B_Corporate"]`
  * Default: `"B2C_Direct"`
  * Index: `true`
  * Descripción: Discrimina clientes viajeros directos de cuentas corporativas con acuerdos de crédito diferido.

---

## 4. Contratos de API REST Analítica Interna

Todos los endpoints analíticos residirán bajo el controlador `custom/Espo/Custom/Controllers/Analytics.php`, protegidos con RBAC de administrador y líder de equipo (`GET /api/v1/Analytics/...`).

### 4.1. `GET /api/v1/Analytics/pipeline-conversion`
* **Parámetros Query:** `dateFrom`, `dateTo`, `leadSource`, `assignedUserId`
* **Respuesta JSON (200 OK):**
```json
{
  "summary": {
    "totalLeads": 150,
    "totalWon": 38,
    "overallConversionRate": 25.33
  },
  "stages": [
    {
      "stage": "Prospecting",
      "count": 150,
      "conversionToNext": 82.0,
      "dropOffCount": 27,
      "medianDurationHours": 4.2,
      "p90DurationHours": 18.5
    },
    {
      "stage": "Proposal",
      "count": 95,
      "conversionToNext": 65.26,
      "dropOffCount": 33,
      "medianDurationHours": 26.0,
      "p90DurationHours": 72.0
    },
    {
      "stage": "Closed Won",
      "count": 38,
      "conversionToNext": 100.0,
      "dropOffCount": 0,
      "medianDurationHours": 0.0,
      "p90DurationHours": 0.0
    }
  ],
  "dropOffReasons": [
    { "reason": "Precio / Presupuesto Alto", "count": 28, "percentage": 48.27 },
    { "reason": "Sin Respuesta / Fantasma", "count": 16, "percentage": 27.58 }
  ]
}
```

### 4.2. `GET /api/v1/Analytics/real-profitability`
* **Parámetros Query:** `dateFrom`, `dateTo`, `clientType`, `destination`, `supplierId`
* **Respuesta JSON (200 OK):**
```json
{
  "reconciled": {
    "totalIncome": 128500.00,
    "totalCost": 92300.00,
    "grossProfit": 36200.00,
    "grossMarginRate": 28.17,
    "confirmedBookingsCount": 42
  },
  "unreconciledFunnel": {
    "pendingBalance": 18400.00,
    "unconfirmedQuotesCost": 12100.00,
    "pipelineBookingsCount": 15
  },
  "byDestination": [
    {
      "destination": "Cusco, Perú",
      "reconciledIncome": 68000.00,
      "reconciledCost": 46000.00,
      "grossProfit": 22000.00,
      "marginRate": 32.35
    },
    {
      "destination": "Punta Cana, Rep. Dominicana",
      "reconciledIncome": 60500.00,
      "reconciledCost": 46300.00,
      "grossProfit": 14200.00,
      "marginRate": 23.47
    }
  ],
  "bySupplier": [
    {
      "supplierId": "sup-001",
      "supplierName": "Operador Receptivo Andino SAC",
      "confirmedCost": 34500.00,
      "itemsCount": 28
    }
  ],
  "byClientType": {
    "B2C_Direct": { "income": 98500.00, "profit": 29800.00, "marginRate": 30.25 },
    "B2B_Corporate": { "income": 30000.00, "profit": 6400.00, "marginRate": 21.33 }
  }
}
```

### 4.3. `GET /api/v1/Analytics/agent-performance`
* **Parámetros Query:** `dateFrom`, `dateTo`
* **Respuesta JSON (200 OK):**
```json
{
  "agents": [
    {
      "userId": "usr-001",
      "userName": "Carlos Asesor",
      "conversationsCount": 85,
      "closedWonCount": 24,
      "conversionRate": 28.24,
      "frtMedianMinutes": 6.5,
      "frtP90Minutes": 22.0,
      "businessHoursCompliance": 94.5
    }
  ]
}
```

### 4.4. `GET /api/v1/Analytics/destination-quality`
* **Parámetros Query:** `dateFrom`, `dateTo`
* **Respuesta JSON (200 OK):**
```json
{
  "nps": {
    "totalResponses": 64,
    "promoters": 48,
    "passives": 11,
    "detractors": 5,
    "npsScore": 67.18
  },
  "contingency": {
    "incidentsCount": 4,
    "totalCostImpact": 420.00,
    "averageCostPerIncident": 105.00
  },
  "detractorSla": {
    "totalDetractorTasks": 5,
    "resolvedUnder2Hours": 4,
    "slaComplianceRate": 80.0
  }
}
```

---

## 5. Arquitectura de Visualización y UX en Dashboards

Para satisfacer la **Ley de Fitts**, la **Ley de Miller** y el **Umbral de Doherty (< 400 ms)**:
- Se implementará un set de **Dashlets personalizados** registrados en EspoCRM cargando **Apache ECharts** (Canvas render) de forma asíncrona y modular.
- Controles interactivos con rangos rápidos de fecha (`Este Mes`, `Último Trimestre`, `Año Actual`, `Rango Personalizado`).
- Cero recargas de página (*SPA reactivo* con filtros en memoria y tooltips informativos con glosario contable).
