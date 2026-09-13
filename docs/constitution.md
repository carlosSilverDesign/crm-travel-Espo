# /docs/constitution.md

## 1. Principios de Arquitectura y Diseño
* **Extensión Limpia sobre Modificación:** Toda personalización y lógica de negocio específica de la agencia de viajes debe residir exclusivamente en extensiones y metadatos customizados (`custom/Espo/Custom/Resources/`). Queda estrictamente prohibido alterar o parchear el core de EspoCRM para asegurar la inmunidad a actualizaciones futuras.
* **Patrones Estructurados:** Las capas de servicio, controladores y hooks respetarán patrones reconocidos como MVC y Repository para asegurar un código mantenible, modular y escalable.
* **Contenerización y Despliegue:** Se utilizará Docker para empaquetar cada servicio y garantizar paridad exacta entre entornos locales y de producción.
* **Experiencia de Usuario (UX Heuristics & Laws):**
  * **Ley de Hick & Miller:** Minimizar opciones y agrupar la información de viaje en bloques digestibles (chunking) para evitar sobrecargar la memoria de trabajo del asesor.
  * **Ley de Tesler (Conservación de Complejidad):** Absorber la complejidad de cálculo (márgenes comerciales, vigencia de tarifas y sincronización con mensajería) en el backend y fórmulas automáticas, reduciendo los pasos manuales del agente.
  * **Doherty Threshold:** El sistema debe responder o proporcionar feedback visual en menos de 400 ms.
  * **Prevención de Errores:** Bloquear modificaciones accidentales en presupuestos o cotizaciones una vez que han sido confirmadas o abonadas.

## 2. Restricciones Técnicas
* **Núcleo:** EspoCRM (self-hosted).
* **Motor de Datos:** MySQL (estricto cumplimiento transaccional ACID para cotizaciones, márgenes e integridad financiera).
* **Infraestructura:** VPS en Hetzner administrado directamente para control de costos y datos.
* **Stack de Integraciones:**
  * **Respond.io:** Canal principal de mensajería (WhatsApp-centric).
  * **Activepieces:** Motor de flujos y webhooks (Licencia MIT y soporte MCP).
  * **Claude API:** Procesamiento de lenguaje natural, clasificación de intenciones y generación de respuestas turísticas automáticas.

## 3. Estrategia Multi-Tenancy (Single-Tenant Base Replicable)
* **Modelo:** Arquitectura Single-Tenant. Cada agencia opera en su propio contenedor Docker independiente con su propia base de datos MySQL y dominio asignado.
* **Aislamiento:** Cero mezcla lógica de datos entre clientes.
* **Evolución del Producto:** La primera implementación consolida el producto al 100% como "imagen base dorada" para agencias de viajes. Nuevas agencias se incorporarán clonando y desplegando dicha instancia base, permitiendo parametrizar configuraciones o lógica específica sin riesgos cruzados.

## 4. Criterios de Calidad y Gobernanza
* **Spec-Driven Development (SDD):** Prohibido escribir código de producción sin una especificación (`.spec.md`) aprobada por escrito.
* **Trazabilidad:** Cada entrega vincula: Requisito → Spec → Plan → Tarea atómica (`.tasks.md`) → Commit.
* **Gestión de Cambios:** Cualquier modificación estructural o desviación técnica requiere un Architecture Decision Record (`/docs/decisions/ADR-XXXX-*.md`).