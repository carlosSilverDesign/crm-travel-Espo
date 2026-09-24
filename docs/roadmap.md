# Product Roadmap Completo: CRM SaaS Viajes B2C

## 1. Visión y Estado Global
* **Arquitectura Base:** Single-Tenant por agencia en VPS Hetzner contenerizado (Docker + MySQL con integridad transaccional ACID en InnoDB).
* **Core:** EspoCRM (Self-hosted) con Regla de Oro de Aislamiento estricta: cero modificaciones en `application/`; 100% de customizaciones en `custom/Espo/Custom/`.
* **Microservicios e Integraciones Desacopladas:**
  * **Chatwoot (WhatsApp & Omnicanalidad):** Plataforma open-source self-hosted en Docker, garantizando soberanía de datos y eliminando costos de licenciamiento por usuario.
  * **Activepieces (Motor de Automatización de Flujos):** Orquestador de flujos basado en Node.js/TypeScript, PostgreSQL y Redis, desacoplado del CRM.
  * **Gemini 1.5 Flash (Copiloto de IA Conmutable):** Motor RAG y generación de propuestas rápidas con arquitectura de proveedor conmutable (Gemini / OpenAI / Anthropic) optimizado para <400 ms de latencia y costo eficiente.
  * **Puppeteer Headless (`pdf-service`):** Microservicio Node.js + Chromium optimizado sobre Alpine Linux para renderizado PDF de fidelidad de impresión A4.
  * **Travel Web (`travel-web`):** Portal web público desacoplado para viajeros con acceso seguro vía token UUIDv4 y diseño responsive mobile-first.
* **Metodología:** Spec-Driven Development (SDD).
* **Fase Actual:** Módulos 01 al 07 completados y validados al 100%. **Fase II: Cierre de Venta, Documentación y Operación en Viaje culminada con éxito**. Iniciando **Fase III: Escalamiento SaaS, Analítica y White-Label (Módulo 08)**.

---

## 2. Bitácora de Evolución Tecnológica y Decisiones Arquitecturales (ADR)

Durante el ciclo de desarrollo bajo metodología SDD, se aplicaron mejoras estratégicas sobre la arquitectura tecnológica original:

| Dominio | Decisión Original | Evolución / Stack Actual | Justificación / Beneficio |
| :--- | :--- | :--- | :--- |
| **Canal WhatsApp** | Respond.io (SaaS propietario cerrado) | **Chatwoot (Self-hosted en Docker)** | Reducción drástica de costos operativos por asiento, soberanía total de datos de clientes y API/Webhooks nativos directos con EspoCRM. |
| **Motor de IA** | Claude API directa | **Gemini 1.5 Flash + Capa Conmutable (Activepieces)** | Tiempos de respuesta ultra-rápidos (<400 ms para cumplir con el Umbral de Doherty), costos de inferencia 10x menores y tolerancia a fallos mediante desacoplamiento no bloqueante. |
| **Renderizado de Documentos** | Plantillas HTML/PDF internas en PHP/EspoCRM | **Microservicio Node.js Puppeteer Headless (`pdf-service`)** | Aislamiento de carga de CPU/RAM fuera del CRM, soporte total de CSS moderno (`@media print`, flexbox, grid, web fonts) y generación idéntica pixel-perfect del expediente interactivo. |
| **Expediente del Cliente** | PDF adjunto tradicional enviado por correo | **Portal Web Responsive (`travel-web`) + PDF en Caché** | Acceso inmediato al itinerario en destino desde el smartphone con botones táctiles (Fitts's Law), actualización en tiempo real, chunking por días (Miller's Law) y privacidad absoluta sin fuga de márgenes comerciales. |
| **Cobros y Conciliación** | Pasarela de pagos automatizada obligatoria (Stripe/Culqi) | **Transferencias Bancarias Empresariales con Auditoría Humana + Arquitectura Extensible para Pasarelas Futuras** | Cero comisiones de intermediación en la fase inicial, validación directa de constancias bancarias por cajeros/asesores, blindaje contable con inmutabilidad en MySQL InnoDB y desacoplamiento limpio para conectar gateways a posteriori sin rediseñar el modelo de datos. |
| **Operación en Destino y Post-Venta** | Formulario web estático o encuestas por email desconectadas | **Detección Automática de Retornos por Cron + Encuesta Conversacional WhatsApp (Activepieces/Chatwoot) + Tareas Urgentes de Calidad** | Captura de satisfacción en el canal natural del cliente (WhatsApp) a las 24 horas del regreso (*Peak-End Rule*), parser tolerante (*Ley de Postel*) y mitigación inmediata de insatisfacción con SLA < 2 horas antes de que escale a quejas públicas. |

---

## 3. Fases y Módulos de Producto

### Fase I: Fundaciones y Ciclo de Venta Core (MVP Comercial)

* **Módulo 01: Modelo de Datos Turístico**  
  * *Alcance:* 7 entidades turísticas (`Itinerario`, `BudgetLine`, `Passenger`, `PaymentSchedule`, etc.), fórmulas de rentabilidad bruta y margen neto, hooks transaccionales de control financiero (`ConfirmationGate`, `MarginFloorHook`) y sincronización unidireccional de estados.  
  * *Estado:* ✅ **Validado al 100% (Fase 6 — Verificado en PHPUnit & MySQL ACID)**.

* **Módulo 02: Pipeline Comercial Adaptado**  
  * *Alcance:* Etapas comerciales turísticas en `Opportunity` (`Prospecting` → `Closed Won` / `Closed Lost`), validación obligatoria para pasar a `PaymentPending`, guard de motivos de pérdida (`LostReasonGuard`), ACL dinámica sobre campos financieros y vista Kanban especializada con métricas de destino y rentabilidad.  
  * *Estado:* ✅ **Validado al 100% (Fase 6 — Verificado en PHPUnit & E2E)**.

* **Módulo 03: Integración WhatsApp & Omnicanalidad (Chatwoot)**  
  * *Alcance:* Captación de leads entrantes vía webhook autenticado (`HMAC-SHA256`), distribución de prospectos con algoritmo Round-Robin resiliente con fallback defensivo a administrador, sincronización bidireccional con `Contact` y `Opportunity`, y botón UI de salto directo a la conversación de Chatwoot.  
  * *Estado:* ✅ **Validado al 100% (Fase 6 — Verificado con Webhook Ingestion Tests)**.

* **Módulo 04: Motor de Automatizaciones y Copiloto IA (Activepieces + Gemini)**  
  * *Alcance:* Orquestación desacoplada en Activepieces, disparador por etiqueta (`Cotizar`), extracción de contexto histórico conversacional, consulta RAG de catálogo de itinerarios en EspoCRM mediante API Key segura de solo lectura, y sugerencia de respuestas en notas privadas de Chatwoot con LLM conmutable (Gemini 1.5 Flash). Principio de resiliencia Postel (asistente opcional no bloqueante).  
  * *Estado:* ✅ **Validado al 100% (Fase 6 — Verificado en Flujos E2E & Fallback Offline)**.

---

### Fase II: Cierre de Venta, Documentación y Operación en Viaje

* **Módulo 05: Expediente Digital, Vouchers e Itinerarios para Cliente (Puppeteer Headless + Web Responsive)**  
  * *Alcance:* 
    * Hook de generación automática e idempotente de tokens criptográficos `UUIDv4` (`publicAccessToken`) para acceso público seguro y sin autenticación de usuario.
    * Portal web responsive desacoplado (`travel-web`) con chunking cognitivo diario (Ley de Miller), tarjetas de servicios con iconografía de accesibilidad, botones táctiles de WhatsApp y descarga (Ley de Fitts).
    * Privacidad estricta (Heurística 8 de Nielsen): Cero exposición de márgenes de ganancia, costos de operadores o notas internas en la vista pública.
    * Microservicio headless Node.js en contenedor Docker (`pdf-service`) compilando documentos A4 de alta fidelidad emulando `@media print`.
    * Servicio de integración y caché en EspoCRM (`pdfCacheFileId`) con respuesta < 200 ms (Umbral de Doherty) y degradación elegante HTTP 503 ante caídas del microservicio (Heurística 9 y Ley de Postel).
  * *Estado:* ✅ **Validado al 100% (Fase 6 — TASK-031 a TASK-036 completadas con suite unitaria e integración E2E)**.

* **Módulo 06: Conciliación de Cobros, Cuentas Bancarias y Verificación de Constancias**  
  * *Alcance:*
    * **TASK-037:** Entidad `BankAccount` con catálogo multi-divisa (USD / PEN), control RBAC estricto para cajeros y finanzas, y seed determinista (BCP USD, BBVA PEN, Interbank USD).
    * **TASK-038:** Entidad transaccional `Payment` con generación automática de referencias (`PAY-XXXXXX`), acumulación financiera en `Opportunity` (`amountPaid`, `pendingBalance`, `financialStatus`) y vistas de listado/detalle.
    * **TASK-039:** Hook `FinancialReconciliation` y servicio de dominio con inmutabilidad contable (excepción `403 Forbidden` ante alteración de pagos confirmados), validación de motivos de rechazo (`400 BadRequest`) y recálculo atómico bajo transacción ACID en MySQL (marcando cuotas de `PaymentSchedule` como `Pagado` y cerrando automáticamente a `Closed Won` al liquidar el 100%).
    * **TASK-040:** API pública (`PublicPaymentController`) y portal web en `travel-web` con filtrado estricto por moneda (cero cuentas cruzadas), botones de copia de CCI/cuenta con feedback visual rápido (<200 ms) y subida segura de comprobantes JPG/PNG/PDF pasando defensivamente a `UnderReview`.
    * **TASK-041:** Mesa de control en EspoCRM para cajeros/asesores con banner comparativo (monto esperado vs declarado), visualizador embebido de voucher (imágenes y PDFs sin descarga forzada), confirmación en 1 clic y modal con motivo de rechazo obligatorio.
    * **TASK-042:** Suite completa de pruebas unitarias (`FinancialReconciliationTest`) e integración E2E (`PaymentLifecycleE2ETest`) verificando aislamiento por divisa, inmutabilidad contable, transaccionalidad ACID y cierre comercial automático.
  * *Estado:* ✅ **Validado al 100% (Fase 6 — TASK-037 a TASK-042 completadas con 73/73 tests en verde y 506 aserciones)**.

* **Módulo 07: Operación en Destino, Incidentes y Post-Venta**  
  * *Alcance:*
    * **TASK-043:** Entidades `Incident` y `Feedback` con aislamiento contable estricto (`costImpact` en incidentes sin mutar saldos de `Opportunity` ni cobros en `Payment`), categorización de disrupciones (`severity`, `category`, `status`), relaciones 1:N y layout de paneles.
    * **TASK-044:** Hook `ClassifyNpsScore` con validación defensiva (1-10), clasificación matemática automatizada (`Promoter`, `Passive`, `Detractor`), y alerta reactiva con generación atómica de `Task` urgente (< 2h SLA) para detractores (*Heurística 9* y *Peak-End Rule*).
    * **TASK-045:** Consola y tablero operativo de pasajeros "En Viaje" (`currentInDestination`) con filtro SQL indexado sobre fechas de viaje (`startDate <= CURDATE() AND endDate >= CURDATE()`) y tiempo de respuesta < 400 ms (*Umbral de Doherty* y *Heurística 6*).
    * **TASK-046:** Scheduled Job `TriggerPostTripSurveyJob` que corre diariamente (09:00 UTC) detectando retornos a las 24 horas (`endDate = ayer`), normalizando teléfonos a estándar internacional `E.164` y despachando webhooks hacia Activepieces con tolerancia a fallos (*Ley de Postel*).
    * **TASK-047:** Flujo de integración en Activepieces (`post-trip-nps-survey.json` y motor desacoplado) con envío de plantilla WhatsApp vía Chatwoot / Meta WhatsApp Cloud API, parser conversacional tolerante de calificaciones (ej: "10!", "10/10", "3 - pésimo hotel") e ingesta REST autenticada en `POST /api/v1/Feedback` en 29 ms.
    * **TASK-048:** Suite completa de pruebas unitarias (`FeedbackClassificationTest`) e integración E2E (`InTripOperationsE2ETest`) verificando la clasificación, alerta de detractores, aislamiento contable y ciclo post-viaje completo.
  * *Estado:* ✅ **Validado al 100% (Fase 6 — TASK-043 a TASK-048 completadas con 107/107 tests en verde y 827 aserciones)**.

---

### Fase III: Escalamiento SaaS, Analítica y White-Label

* **Módulo 08: Reportería Comercial y Analítica de Rentabilidad**  
  * *Alcance:* Dashboards analíticos de conversión por canal y etapa, rentabilidad bruta real consolidada por operador y destino, y tiempos de respuesta por asesor de viajes.  
  * *Estado:* 🟡 **Siguiente en Backlog / Preparando Especificación (Fase 1 - SDD)**.

* **Módulo 09: Branding y Replicabilidad Multi-Agencia (White-Label)**  
  * *Alcance:* Tematizado visual configurable por agencia cliente (paleta cromática, logotipos, dominios personalizados) e imagen Docker parametrizable para aprovisionamiento automatizado de nuevas instancias de agencias.  
  * *Estado:* ⚪ Pendiente.

* **Módulo 10: Facturación y Planes SaaS**  
  * *Alcance:* Gestión de suscripciones, límites de volumen de pasajeros/itinerarios por plan y facturación recurrente de la plataforma hacia las agencias arrendatarias.  
  * *Estado:* ⚪ Pendiente.

---

## 4. Próximos Pasos Inmediatos
1. Iniciar el ciclo SDD de la **Fase III: Escalamiento SaaS, Analítica y White-Label**, arrancando con el **Módulo 08: Reportería Comercial y Analítica de Rentabilidad**.
2. Redactar la especificación funcional y técnica en `docs/specs/analytics-reporting.spec.md`.
3. Modelar los dashboards ejecutivos y métricas de rentabilidad comercial consolidada.