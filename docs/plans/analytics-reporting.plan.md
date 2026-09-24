# Plan Técnico 008: Reportería Comercial, Rentabilidad Real y Analítica de Operación (Agencia Piloto)

* **Especificación de Referencia:** `/docs/specs/analytics-reporting.spec.md`
* **Módulo:** 08 — Reportería Comercial y Analítica de Rentabilidad
* **Metodología:** Spec-Driven Development (SDD)
* **Stack Tecnológico:** PHP 8.2+ (EspoCRM Custom Entities, Services, Controllers, Hooks), MySQL 8.0 (InnoDB), Chatwoot API v1, Apache ECharts (Canvas-based interactive dashboards)
* **Regla de Oro de Aislamiento:** Todo el desarrollo backend debe residir estrictamente bajo `custom/Espo/Custom/` y el frontend en `client/custom/`. Cero modificaciones a archivos nativos bajo `application/`.

---

## 1. Arquitectura General y Flujo de Datos

```text
       [Asesor / Sistema cambia etapa en Opportunity]
                              │
                              ▼
            [Hook: TrackOpportunityStageTransition]
                              │
                              ▼
                [opportunity_stage_history]
                              │
┌─────────────────────────────┼─────────────────────────────┐
│                             │                             │
▼                             ▼                             ▼
[PipelineConversionService]   [RealProfitabilityService]    [ChatwootReportingService]
- Etapa a etapa %             - Ingresos Reconciliados      - First Response Time (FRT)
- Mediana & P90 tiempo        - Costos con Voucher          - Mediana & P90 por Asesor
- Drop-off por LostReason     - Margen Real por Destino     - Horario Oficina vs Extendido
                              - Segregación B2C vs B2B
                              │
                              ▼
             [AnalyticsController (API REST EspoCRM)]
             - GET /api/v1/Analytics/pipeline-conversion
             - GET /api/v1/Analytics/real-profitability
             - GET /api/v1/Analytics/agent-performance
             - GET /api/v1/Analytics/destination-quality
                              │
                              ▼
        [Dashlets Interactivos con Apache ECharts (UI/UX)]
        - Funnel de Conversión Dinámico
        - Barras Apiladas: Margen Real vs Costos por Operador
        - Boxplot / Heatmap de Tiempos de Respuesta
        - Gauge de NPS y Termómetro de Contingencias
```

---

## 2. Esquema de Datos y Metadatos en EspoCRM

### 2.1. Entidad `OpportunityStageHistory`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/OpportunityStageHistory.json`
- Atributos:
  - `id`: varchar(17)
  - `opportunityId`: link Opportunity (index: true, required: true)
  - `stage`: enum (index: true, required: true)
  - `enteredAt`: datetime (index: true, required: true)
  - `exitedAt`: datetime (index: true)
  - `durationSeconds`: int (unsigned)
  - `leadSource`: enum (index: true)
  - `assignedUserId`: link User (index: true)

Archivo Scope: `custom/Espo/Custom/Resources/metadata/scopes/OpportunityStageHistory.json`
- `entity`: true, `type`: "Base", `module`: "Travel"

### 2.2. Extensión de `Opportunity`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`
- Agregar campo `clientType`:
  ```json
  "clientType": {
    "type": "enum",
    "options": ["B2C_Direct", "B2B_Corporate"],
    "default": "B2C_Direct",
    "index": true
  }
  ```

---

## 3. Lógica de Dominio y Servicios Backend

### 3.1. Hook de Auditoría de Etapas: `TrackOpportunityStageTransition.php`
Archivo: `custom/Espo/Custom/Hooks/Opportunity/TrackOpportunityStageTransition.php`
- En `afterSave`:
  - Si `$entity->isNew()`: Inserta el primer registro de `OpportunityStageHistory` con `enteredAt = $entity->get('createdAt') ?? now()`, `stage = $entity->get('stage')`, `leadSource = $entity->get('leadSource')`, `assignedUserId = $entity->get('assignedUserId')`.
  - Si `!$entity->isNew()` y `isAttributeChanged('stage')`:
    - Busca el registro activo previo en `OpportunityStageHistory` donde `opportunityId = id` y `exitedAt IS NULL`.
    - Actualiza `exitedAt = now()` y calcula `durationSeconds = strtotime(now()) - strtotime(enteredAt)`.
    - Inserta el nuevo registro de `OpportunityStageHistory` con la nueva etapa y `enteredAt = now()`.

### 3.2. Servicio `RealProfitabilityService.php`
Archivo: `custom/Espo/Custom/Services/RealProfitabilityService.php`
- **Fórmula de Margen Real Liquidado:**
  $$\text{ReconciledIncome} = \sum \text{Payment.amount} \quad \text{donde } \text{status} = \text{'Confirmed'}$$
  $$\text{ConfirmedCost} = \sum \text{BudgetLine.costPrice} \quad \text{de itinerarios vinculados con } \text{supplierId IS NOT NULL}$$
  $$\text{RealGrossProfit} = \text{ReconciledIncome} - \text{ConfirmedCost}$$
- **Aislamiento Contable Estricto:** Si una reserva no tiene pagos confirmados o no tiene costos vinculados, no entra a la cifra de `RealGrossProfit`. Se suma al bloque `unreconciledFunnel.pendingBalance`.
- **Agrupaciones:**
  - Por Destino (`destination`).
  - Por Operador Receptivo (`supplierId`).
  - Por Tipo de Cliente (`clientType`: `B2C_Direct` vs `B2B_Corporate`).

### 3.3. Servicio `PipelineConversionService.php`
Archivo: `custom/Espo/Custom/Services/PipelineConversionService.php`
- Consulta indexada sobre `opportunity_stage_history`.
- Calcula:
  - Total de oportunidades únicas que alcanzaron cada etapa.
  - Tasa de conversión porcentual hacia la etapa inmediata siguiente.
  - Tasa de abandono (*drop-off count* y motivos desde `lostReason`).
  - Cálculo algorítmico exacto de la **Mediana** y el **Percentil 90 (P90)** de permanencia en horas.

### 3.4. Servicio `ChatwootReportingService.php`
Archivo: `custom/Espo/Custom/Services/ChatwootReportingService.php`
- Consulta autenticada hacia la API de Chatwoot:
  `GET {chatwootBaseUrl}/api/v1/accounts/{accountId}/reports/conversations?type=agent`
- Headers: `api_access_token`.
- Extrae la métrica `first_response_time` calculada exclusivamente sobre mensajes humanos (sin bots).
- Calcula la mediana y el P90 de FRT por agente.
- Fallback defensivo (Ley de Postel): Si Chatwoot API no responde o no está configurado, retorna métricas calculadas a partir del primer contacto registrado en EspoCRM sin bloquear el dashboard.

### 3.5. Controlador REST `Analytics.php`
Archivo: `custom/Espo/Custom/Controllers/Analytics.php`
- Expone las 4 rutas REST estándar:
  - `actionGetPipelineConversion(Request $request)`
  - `actionGetRealProfitability(Request $request)`
  - `actionGetAgentPerformance(Request $request)`
  - `actionGetDestinationQuality(Request $request)`
- Validación de autorización y filtrado por rango de fechas (`dateFrom`, `dateTo`).

---

## 4. Visualización con Apache ECharts (Dashlets en EspoCRM)

### 4.1. Integración de Apache ECharts
- Descarga / distribución local de `echarts.min.js` (Apache 2.0 License) dentro de `client/custom/lib/echarts.min.js`.
- Totalmente desacoplada, sin dependencias externas ni llamadas a CDN bloqueadas por red corporativa.

### 4.2. Definición de Dashlets Nativos
1. **`PipelineFunnel`:** Gráfico de embudo interactivo mostrando la conversión etapa a etapa y tiempos de permanencia.
2. **`RealProfitability`:** Gráfico de barras bidireccionales / apiladas comparando Ingresos Conciliados vs Costo de Proveedores por Destino.
3. **`AgentPerformance`:** Tabla ejecutiva y boxplot de tiempos de respuesta (Mediana y P90) por asesor.
4. **`DestinationQuality`:** Tacómetro (Gauge) de Net Promoter Score (-100 a +100) y semáforo de gastos de contingencia en destino (`costImpact`).

Archivos de definición:
- `custom/Espo/Custom/Resources/metadata/dashlets/PipelineFunnel.json`
- `custom/Espo/Custom/Resources/metadata/dashlets/RealProfitability.json`
- `custom/Espo/Custom/Resources/metadata/dashlets/AgentPerformance.json`
- `custom/Espo/Custom/Resources/metadata/dashlets/DestinationQuality.json`
- `custom/Espo/Custom/Resources/metadata/app/dashlets.json`

---

## 5. Estrategia de Pruebas y Validación (DoD)

1. **Pruebas Unitarias (`AnalyticsServiceTest.php`):**
   - Validación del cálculo matemático de rentabilidad real vs dinero de papel (aislamiento de reservas sin conciliar).
   - Validación algorítmica de la mediana y P90 en arrays con outliers extremos.
   - Validación del hook de transición de etapas (`enteredAt`, `exitedAt`, `durationSeconds`).
2. **Pruebas de Integración E2E (`AnalyticsE2ETest.php`):**
   - Sembrado de ciclo completo: Oportunidad $\to$ Transición de 3 etapas $\to$ Cobro bancario conciliado $\to$ Voucher con operador $\to$ Encuesta NPS detractor $\to$ Consumo de los 4 endpoints analíticos.
   - Comprobación de latencia de endpoints `< 400 ms` (*Umbral de Doherty*).
3. **Regla de Oro de Aislamiento:** Verificación estricta de cero cambios en `application/`.
