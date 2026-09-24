# Tasks 008: Reportería Comercial, Rentabilidad Real y Analítica de Operación (Agencia Piloto)

* **Módulo:** 08 — Reportería Comercial y Analítica de Rentabilidad
* **Especificación de Referencia:** `/docs/specs/analytics-reporting.spec.md`
* **Plan Técnico de Referencia:** `/docs/plans/analytics-reporting.plan.md`
* **Metodología:** Spec-Driven Development (SDD)
* **Regla de Oro de Aislamiento:** Todo el desarrollo backend reside bajo `custom/Espo/Custom/` y frontend en `client/custom/` sin alterar el core en `application/`.
* **Principio de Valor:** Cero métricas vanidosas y cero ganancias de papel. Rentabilidad basada estrictamente en transferencias confirmadas en banco y costos amparados en vouchers de operadores.

---

## 1. Matriz de Dependencias

```text
[TASK-049: Auditoría de Etapas (OpportunityStageHistory) & ClientType]
│
├───► [TASK-050: Servicio de Rentabilidad Real Consolidada]
│             │
│             ▼
├───► [TASK-051: Servicios de Conversión Pipeline & Métricas Chatwoot FRT]
│             │
│             ▼
├───► [TASK-052: Controlador REST Analytics (Endpoints de Inteligencia)]
│             │
│             ▼
├───► [TASK-053: Dashlets Interactivos en EspoCRM con Apache ECharts]
│             │
│             ▼
└───► [TASK-054: Suite de Pruebas Unitarias & Integración E2E Analítica]
```

---

## 2. Definición Atómica de Tareas

### Épica 1: Captura de Datos y Auditoría de Pipeline

#### `TASK-049` — Entidad `OpportunityStageHistory`, Hook de Transición y `clientType`
* **Archivos:**
  * `custom/Espo/Custom/Resources/metadata/entityDefs/OpportunityStageHistory.json`
  * `custom/Espo/Custom/Resources/metadata/scopes/OpportunityStageHistory.json`
  * `custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`
  * `custom/Espo/Custom/Hooks/Opportunity/TrackOpportunityStageTransition.php`
* **Descripción:**
  * Crear la entidad `OpportunityStageHistory` con los campos: `opportunityId`, `stage`, `enteredAt`, `exitedAt`, `durationSeconds`, `leadSource` y `assignedUserId`.
  * Añadir el campo `clientType` (enum: `B2C_Direct`, `B2B_Corporate`) a `Opportunity` con default `B2C_Direct`.
  * Implementar el hook `TrackOpportunityStageTransition`:
    * En `afterSave`, si la oportunidad es nueva, registra el estado inicial en `OpportunityStageHistory`.
    * Si la oportunidad ya existía y cambió de `stage`, cierra el registro anterior calculando `durationSeconds` e inserta la nueva etapa.
* **Criterios de Aceptación (DoD):**
  * `php command.php rebuild` aplica los esquemas en base de datos. Mover una oportunidad entre etapas persiste la duración exacta de permanencia de forma atómica.

---

### Épica 2: Servicios de Inteligencia Financiera y Comercial

#### `TASK-050` — Servicio Backend de Rentabilidad Real Consolidada
* **Archivos:**
  * `custom/Espo/Custom/Services/RealProfitabilityService.php`
* **Descripción:**
  * Implementar la Regla de Oro de Liquidación:
    * $\text{Ingreso Reconciliado} = \sum \text{Payment.amount}$ donde $\text{status} = \text{'Confirmed'}$.
    * $\text{Costo Confirmado} = \sum \text{BudgetLine.costPrice}$ vinculados a proveedores activos o con voucher.
    * $\text{Margen Bruto Real} = \text{Ingreso Reconciliado} - \text{Costo Confirmado}$.
  * Aislamiento contable: Cualquier cotización o reserva pendiente de cobro se segrega en `unreconciledFunnel` y jamás se suma a la cifra de margen real.
  * Agregar los cálculos por Destino (`destination`), por Operador (`supplierId`) y por Tipo de Cliente (`clientType`).
* **Criterios de Aceptación (DoD):**
  * El servicio retorna los agregados financieros en menos de 100 ms sin mezclar dinero no reconciliado en el margen bruto.

#### `TASK-051` — Servicios de Conversión de Pipeline y Métricas Chatwoot (FRT)
* **Archivos:**
  * `custom/Espo/Custom/Services/PipelineConversionService.php`
  * `custom/Espo/Custom/Services/ChatwootReportingService.php`
* **Descripción:**
  * En `PipelineConversionService`: Consultar `OpportunityStageHistory` y calcular la tasa de conversión etapa a etapa, drop-off por `lostReason` y la duración en mediana y percentil 90 (P90) por etapa y canal.
  * En `ChatwootReportingService`: Adaptador que consume la API de reportes de Chatwoot (`/api/v1/accounts/{id}/reports/conversations?type=agent`) para extraer First Response Time (FRT) humano en mediana y P90, con fallback resiliente offline (Ley de Postel).
* **Criterios de Aceptación (DoD):**
  * Cálculo algorítmico exacto de percentiles (P50/P90) y tiempos en cada fase del funnel.

---

### Épica 3: Exposición de API REST Analítica

#### `TASK-052` — Controlador REST de Analítica en EspoCRM
* **Archivos:**
  * `custom/Espo/Custom/Controllers/Analytics.php`
* **Descripción:**
  * Crear el controlador REST con los 4 endpoints:
    1. `GET /api/v1/Analytics/pipeline-conversion`: Conversión etapa a etapa, tiempos mediana/P90 y motivos de descarte.
    2. `GET /api/v1/Analytics/real-profitability`: Margen bruto liquidado vs dinero de papel, por destino y operador.
    3. `GET /api/v1/Analytics/agent-performance`: Tiempos de respuesta (FRT mediana y P90) y conversión por asesor.
    4. `GET /api/v1/Analytics/destination-quality`: Score NPS global (-100 a +100), impacto de contingencias (`costImpact`) y SLA de atención a detractores.
  * Validar control de acceso RBAC para roles administrativos y de gerencia.
* **Criterios de Aceptación (DoD):**
  * Los 4 endpoints responden con HTTP 200 OK y payloads estructurados según la especificación en menos de 400 ms (*Umbral de Doherty*).

---

### Épica 4: Visualización y Cuadros de Mando (UX/UI con Apache ECharts)

#### `TASK-053` — Dashlets Nativos Interactivos en EspoCRM
* **Archivos / Artefactos:**
  * `client/custom/lib/echarts.min.js`
  * `custom/Espo/Custom/Resources/metadata/dashlets/PipelineFunnel.json`
  * `custom/Espo/Custom/Resources/metadata/dashlets/RealProfitability.json`
  * `custom/Espo/Custom/Resources/metadata/dashlets/AgentPerformance.json`
  * `custom/Espo/Custom/Resources/metadata/dashlets/DestinationQuality.json`
  * `custom/Espo/Custom/Resources/metadata/app/dashlets.json`
  * Vistas en `client/custom/modules/travel/views/dashlets/`:
    * `pipeline-funnel.js`
    * `real-profitability.js`
    * `agent-performance.js`
    * `destination-quality.js`
* **Descripción:**
  * Integrar **Apache ECharts** (versión minificada local bajo licencia Apache 2.0).
  * Crear los 4 dashlets interactivos para los tableros del CRM con filtros de fechas rápidos (`Este Mes`, `Trimestre`, `Año`, `Personalizado`), tooltips informativos y gráficos Canvas responsivos.
* **Criterios de Aceptación (DoD):**
  * Los dashlets se pueden añadir al Dashboard principal de EspoCRM y cargan sus gráficas fluidamente en menos de 300 ms.

---

### Épica 5: Aseguramiento de Calidad y Pruebas Automatizadas

#### `TASK-054` — Suite de Pruebas Unitarias e Integración E2E Analítica
* **Archivos:**
  * `custom/Espo/Custom/Tests/Unit/AnalyticsServiceTest.php`
  * `tests/integration/AnalyticsE2ETest.php`
  * `custom/Espo/Custom/Tests/Integration/AnalyticsE2ETest.php`
* **Descripción:**
  * **Test Unitario:**
    * Validar que reservas sin conciliar no afecten el `RealGrossProfit`.
    * Validar cálculo matemático de Mediana y P90 ante outliers extremos.
    * Validar hook de transición de etapas (`enteredAt`, `exitedAt`, `durationSeconds`).
  * **Test E2E:**
    * Simular flujo completo: Lead $\to$ Cotización $\to$ Pago conciliado $\to$ Voucher de operador $\to$ NPS $\to$ Verificación de los 4 endpoints analíticos y latencia global.
* **Criterios de Aceptación (DoD):**
  * 100% de aserciones en verde bajo PHPUnit sin dependencias rotas y `git status -- application/` completamente limpio.
