# Product Roadmap Completo: CRM SaaS Viajes B2C

## 1. Visión y Estado Global
* **Arquitectura Base:** Single-Tenant por agencia en VPS Hetzner contenerizado (Docker + MySQL con integridad transaccional ACID).
* **Core:** EspoCRM (Self-hosted) con Regla de Oro de Aislamiento estricta: cero modificaciones en `application/`; 100% de customizaciones en `custom/Espo/Custom/`.
* **Microservicios e Integraciones Desacopladas:**
  * **Chatwoot (WhatsApp & Omnicanalidad):** Plataforma open-source self-hosted en Docker, garantizando soberanía de datos y eliminando costos de licenciamiento por usuario.
  * **Activepieces (Motor de Automatización de Flujos):** Orquestador de flujos basado en Node.js/TypeScript, PostgreSQL y Redis, desacoplado del CRM.
  * **Gemini 1.5 Flash (Copiloto de IA Conmutable):** Motor RAG y generación de propuestas rápidas con arquitectura de proveedor conmutable (Gemini / OpenAI / Anthropic) optimizado para <400 ms de latencia y costo eficiente.
  * **Puppeteer Headless (`pdf-service`):** Microservicio Node.js + Chromium optimizado sobre Alpine Linux para renderizado PDF de fidelidad de impresión A4.
  * **Travel Web (`travel-web`):** Portal web público desacoplado para viajeros con acceso seguro vía token UUIDv4 y diseño responsive mobile-first.
* **Metodología:** Spec-Driven Development (SDD).
* **Fase Actual:** Módulos 01 al 05 completados y validados al 100%. Iniciando **Módulo 06: Conciliación de Cobros y Links de Pago**.

---

## 2. Bitácora de Evolución Tecnológica y Decisiones Arquitecturales (ADR)

Durante el ciclo de desarrollo bajo metodología SDD, se aplicaron mejoras estratégicas sobre la arquitectura tecnológica original:

| Dominio | Decisión Original | Evolución / Stack Actual | Justificación / Beneficio |
| :--- | :--- | :--- | :--- |
| **Canal WhatsApp** | Respond.io (SaaS propietario cerrado) | **Chatwoot (Self-hosted en Docker)** | Reducción drástica de costos operativos por asiento, soberanía total de datos de clientes y API/Webhooks nativos directos con EspoCRM. |
| **Motor de IA** | Claude API directa | **Gemini 1.5 Flash + Capa Conmutable (Activepieces)** | Tiempos de respuesta ultra-rápidos (<400 ms para cumplir con el Umbral de Doherty), costos de inferencia 10x menores y tolerancia a fallos mediante desacoplamiento no bloqueante. |
| **Renderizado de Documentos** | Plantillas HTML/PDF internas en PHP/EspoCRM | **Microservicio Node.js Puppeteer Headless (`pdf-service`)** | Aislamiento de carga de CPU/RAM fuera del CRM, soporte total de CSS moderno (`@media print`, flexbox, grid, web fonts) y generación idéntica pixel-perfect del expediente interactivo. |
| **Expediente del Cliente** | PDF adjunto tradicional enviado por correo | **Portal Web Responsive (`travel-web`) + PDF en Caché** | Acceso inmediato al itinerario en destino desde el smartphone con botones táctiles (Fitts's Law), actualización en tiempo real, chunking por días (Miller's Law) y privacidad absoluta sin fuga de márgenes comerciales. |

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

* **Módulo 06: Conciliación de Cobros y Links de Pago**  
  * *Alcance:* Generación y disparo de links de pago automáticos desde `PaymentSchedule`, integración con pasarelas de pago (Stripe / MercadoPago / Wompi) y conciliación automática de estados de cobranza vía webhooks hacia `Opportunity` e `Itinerario`.  
  * *Estado:* 🟡 **Siguiente en Backlog / Preparando Especificación (Fase 2)**.

* **Módulo 07: Operación en Destino y Post-Venta**  
  * *Alcance:* Tablero de control y seguimiento operativo para pasajeros en estado "En Viaje", gestión ágil de reprogramaciones, cancelaciones, retrasos y recolección automatizada de feedback de satisfacción al retorno.  
  * *Estado:* ⚪ Pendiente.

---

### Fase III: Escalamiento SaaS, Analítica y White-Label

* **Módulo 08: Reportería Comercial y Analítica de Rentabilidad**  
  * *Alcance:* Dashboards analíticos de conversión por canal y etapa, rentabilidad bruta real consolidada por operador y destino, y tiempos de respuesta por asesor de viajes.  
  * *Estado:* ⚪ Pendiente.

* **Módulo 09: Branding y Replicabilidad Multi-Agencia (White-Label)**  
  * *Alcance:* Tematizado visual configurable por agencia cliente (paleta cromática, logotipos, dominios personalizados) e imagen Docker parametrizable para aprovisionamiento automatizado de nuevas instancias de agencias.  
  * *Estado:* ⚪ Pendiente.

* **Módulo 10: Facturación y Planes SaaS**  
  * *Alcance:* Gestión de suscripciones, límites de volumen de pasajeros/itinerarios por plan y facturación recurrente de la plataforma hacia las agencias arrendatarias.  
  * *Estado:* ⚪ Pendiente.

---

## 4. Próximos Pasos Inmediatos
1. Iniciar el ciclo SDD del **Módulo 06: Conciliación de Cobros y Links de Pago**.
2. Redactar la especificación funcional y técnica del flujo de pago en `docs/specs/payment-reconciliation.spec.md`.
3. Modelar la integración con webhooks de pasarelas de pago y sincronización con `PaymentSchedule`.