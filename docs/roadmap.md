# Product Roadmap Completo: CRM SaaS Viajes B2C

## 1. Visión y Estado Global
* **Arquitectura Base:** Single-Tenant por agencia en VPS Hetzner contenerizado (Docker + MySQL con integridad transaccional ACID)[cite: 1].
* **Core:** EspoCRM (Self-hosted).
* **Integraciones:** Respond.io (WhatsApp), Activepieces (Flujos MIT), Claude API (IA).
* **Metodología:** Spec-Driven Development (SDD).
* **Fase Actual:** Módulo 01 validado. Preparando **Módulo 02** (Fase 2: Especificación).

---

## 2. Fases y Módulos de Producto

### Fase I: Fundaciones y Ciclo de Venta Core (MVP Comercial)
* **Módulo 01: Modelo de Datos Turístico**  
  *Alcance:* 7 entidades turísticas (`Itinerario`, `BudgetLine`, `Passenger`, etc.), fórmulas de rentabilidad, hooks transaccionales de control y sincronización unidireccional[cite: 1, 3].  
  *Estado:* ✅ **Validado al 100% (Fase 6)**.
* **Módulo 02: Pipeline Comercial Adaptado**  
  *Alcance:* Etapas comerciales de turismo en `Opportunity`, vista Kanban con métricas de destino, montos y rentabilidad visible.  
  *Estado:* 🟡 **En Especificación (Fase 2)**.
* **Módulo 03: Integración WhatsApp (Respond.io)**  
  *Alcance:* Captación de leads, vinculación de chat (`whatsappChatId`) y gestión de mensajes en el expediente del cliente.  
  *Estado:* ⚪ Pendiente.
* **Módulo 04: Automatización y Respuestas con IA (Activepieces + Claude API)**  
  *Alcance:* Calificación de prospectos, respuestas frecuentes sobre destinos, cotización guiada y alertas de expiración de tarifas.  
  *Estado:* ⚪ Pendiente.

### Fase II: Cierre de Venta, Documentación y Operación en Viaje
* **Módulo 05: Expediente Digital, Vouchers e Itinerarios para Cliente** *(Nuevo)*  
  *Alcance:* Generador de itinerarios visuales en PDF/Web interactiva con confirmaciones, políticas de equipaje y vouchers descargables.  
  *Estado:* ⚪ Pendiente.
* **Módulo 06: Conciliación de Cobros y Links de Pago** *(Nuevo)*  
  *Alcance:* Disparo de links de pago automáticos desde `PaymentSchedule` y registro de estados de cobranza vía webhooks.  
  *Estado:* ⚪ Pendiente.
* **Módulo 07: Operación en Destino y Post-Venta** *(Nuevo)*  
  *Alcance:* Tablero de seguimiento para pasajeros "En Viaje", manejo de cancelaciones/retrasos y recolección de feedback al retorno.  
  *Estado:* ⚪ Pendiente.

### Fase III: Escalamiento SaaS, Analítica y White-Label
* **Módulo 08: Reportería Comercial y Analítica de Rentabilidad**  
  *Alcance:* Métricas de conversión de embudo, rentabilidad bruta real por operador/destino y tiempos de respuesta por asesor.  
  *Estado:* ⚪ Pendiente.
* **Módulo 09: Branding y Replicabilidad Multi-Agencia (White-Label)**  
  *Alcance:* Tematizado visual por agencia (paletas, logos, dominios) e imagen base Docker para despliegue automatizado de nuevos clientes.  
  *Estado:* ⚪ Pendiente.
* **Módulo 10: Facturación y Planes SaaS**  
  *Alcance:* Cobro por suscripción de la plataforma a las agencias arrendatarias.  
  *Estado:* ⚪ Pendiente.