# Spec 001: Travel Core Data Model & Business Rules

## 1. Visión General
Esta especificación define el modelo de entidades, las relaciones estructurales, las reglas de validación transaccional y el control de acceso (ACL) para soportar el ciclo de venta y operación de viajes dentro de una instancia dedicada de EspoCRM. Se enfoca en resolver la discrepancia entre el ciclo comercial y la ejecución operativa, calculando rentabilidad en tiempo real y garantizando la integridad de los datos de cara a la sincronización con WhatsApp y servicios externos.

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Cotización, Margen Automático y Transparencia Comercial
Como agente de ventas, quiero registrar los costos de proveedores, fijar un margen comercial porcentual y visualizar inmediatamente el margen bruto monetario en pantalla para negociar con el cliente teniendo claridad absoluta de la rentabilidad del paquete.

* **Given** una línea de presupuesto (`BudgetLine`) vinculada a un `Itinerario`.
* **When** el usuario ingresa `costPrice = 1000` y `marginRate = 20`.
* **Then** el sistema calcula automáticamente `sellingPrice = 1200` y expone el campo `grossProfit = 200` (`sellingPrice - costPrice`), el cual es visible para roles `Agent`, `Manager` y `Admin`.
* **Given** un `Itinerario` cuyo estado es distinto a "Cotización" (ej. "Confirmado", "En Viaje").
* **When** un usuario con rol `Agent` intente editar `costPrice` o `marginRate`.
* **Then** el sistema bloquea la mutación lanzando una excepción de validación que impide la alteración financiera post-confirmación.

### HU-02: Gestión de Pasajeros y Control de Documentación
Como asesor comercial, quiero registrar múltiples pasajeros con sus números y vigencias de documentos dentro del itinerario para asegurar que los servicios (vuelos, hoteles) se emitan conforme a las normativas de viaje.

* **Given** un `Itinerario` con una fecha de finalización (`endDate`).
* **When** se crea o vincula un registro de `Passenger` cuya fecha de expiración de documento (`documentExpiration`) sea anterior a `endDate`.
* **Then** el sistema rechaza el guardado mediante una alerta bloqueante: "El documento del pasajero expira antes del término del itinerario".

### HU-03: Sincronización Unidireccional de Pipeline e Itinerario
Como director comercial, quiero que el embudo de ventas (`Opportunity.stage`) refleje la realidad del servicio (`Itinerario.status`) de forma automática pero desacoplada, evitando que cierres manuales en la oportunidad generen inconsistencias en la operación turística.

* **Given** una `Opportunity` vinculada a uno o más registros de `Itinerario`.
* **When** el `Itinerario` transiciona a estado "Confirmado" y supera las validaciones financieras.
* **Then** el sistema transiciona automáticamente la `Opportunity` a etapa "Closed Won" (Cerrada Ganada) y sincroniza el monto total ganado con el consolidado de venta del itinerario.
* **Given** una `Opportunity` en etapa comercial previa (ej. "Proposal/Quote").
* **When** un usuario intenta modificar manualmente el campo `stage` a "Closed Won" sin que exista al menos un `Itinerario` vinculado en estado "Confirmado".
* **Then** el sistema intercepta la acción antes de persistir, bloquea el guardado y emite el error: "No es posible cerrar como ganada una oportunidad sin un itinerario confirmado".
* **Given** un `Itinerario` que pasa a estado "Cancelado".
* **When** no existen otros itinerarios activos o confirmados asociados a la misma `Opportunity`.
* **Then** la `Opportunity` transiciona automáticamente a etapa "Closed Lost" (Cerrada Perdida).

### HU-04: Cronograma de Pagos y Expiración de Cotización
Como manager de agencia, quiero programar cobros parciales (seña y saldo) y monitorear la vigencia de la cotización para evitar congelar tarifas desactualizadas.

* **Given** una cotización con fecha y hora `quoteValidUntil` anterior a la fecha actual.
* **When** el agente intenta cambiar el estado del `Itinerario` a "Confirmado".
* **Then** el sistema rechaza la transición y solicita extender la vigencia o recotizar costos.
* **Given** un `Itinerario` con un monto total de venta consolidado $S$.
* **When** el agente intenta confirmar el itinerario y la suma acumulada de los registros `PaymentSchedule` asociados difiere del total de venta $S$.
* **Then** el sistema alerta sobre la discrepancia financiera y solicita cuadrar el cronograma antes de confirmar.

## 3. Entidades Afectadas y Extensiones

### Entidades Custom Creadas:
1. **Itinerario:** Entidad raíz del expediente turístico (`name`, `status`, `destination`, `startDate`, `endDate`, `quoteValidUntil`, `whatsappStatus`, `notes`).
2. **ItineraryItem:** Componentes individuales del viaje vinculados a proveedores (`name`, `serviceType`, `serviceDate`, `confirmationCode`, `status`, `notes`).
3. **BudgetLine:** Líneas financieras de costo y margen (`name`, `costPrice`, `marginRate`, `sellingPrice`, `grossProfit`, `notes`).
4. **Passenger:** Pasajeros individuales asociados al viaje y al contacto (`name`, `documentType`, `documentNumber`, `documentExpiration`, `birthDate`, `nationality`, `dietaryRestrictions`, `notes`).
5. **PaymentSchedule:** Hitos y cuotas de cobro (`name`, `dueDate`, `amount`, `status`, `paymentMethod`, `transactionId`).
6. **Supplier:** Catálogo de proveedores y operadores (`name`, `supplierType`, `contactEmail`, `contactPhone`, `paymentTerms`).
7. **PackageTemplate:** Plantillas base reutilizables (`name`, `destination`, `durationDays`, `basePrice`, `description`).

### Extensiones a Entidades Nativas de EspoCRM:
* **Opportunity:** Campos añadidos `whatsappChatId` (varchar) y `lastActivepiecesSync` (datetime). Enlace `cItinerarios` (1 a N hacia `Itinerario`).
* **Contact:** Enlace `passengers` (1 a N hacia `Passenger`) para conservar el historial de documentos y preferencias del cliente.

## 4. Reglas de Negocio y Permisos (ACL)

| Rol | Itinerario / ItineraryItem | BudgetLine | Supplier | PaymentSchedule |
| :--- | :--- | :--- | :--- | :--- |
| **Admin** | CRUD total | CRUD total | CRUD total | CRUD total |
| **Manager** | CRUD total | CRUD total | CRUD total | CRUD total |
| **Agent** | CRUD (bloqueo de edición al confirmar) | Lectura/Creación inicial; `costPrice`, `marginRate` y `sellingPrice` pasan a solo lectura al confirmar. Visibilidad habilitada de `grossProfit`. | Solo lectura de catálogo y contactos | Creación y actualización de cronogramas asignados |

## 5. Puntos de Integración
* **Respond.io (WhatsApp):** Identificador de chat en `Opportunity.whatsappChatId` para vincular conversaciones activas y enviar resúmenes automáticos del itinerario.
* **Activepieces & Claude API:** Webhooks salientes al registrar cambios en `PaymentSchedule` (recordatorio de pagos vía WhatsApp) y al generar nuevos borradores de cotización para refinamiento de texto por IA.

## 6. Fuera de Alcance (Out of Scope)
* Pasarela transaccional de cobro directo integrada en la UI de EspoCRM (los cobros se concilian registrando el identificador en `PaymentSchedule.transactionId`).
* Conexión directa mediante protocolo NDC o GDS (Amadeus, Sabre) para emisión automática de PNR.
* Facturación electrónica fiscal directa (se delega a sincronización contable externa vía webhook).

## 7. Preguntas y Puntos de Decisión Resueltos
1. **Sincronización `Opportunity.stage` vs. `Itinerario.status`:** Resuelto. Se implementa sincronización unidireccional ascendente (`Itinerario` actualiza `Opportunity`). Se establece una guardia de integridad en el backend que prohíbe pasar manualmente `Opportunity` a "Closed Won" si no existe un `Itinerario` confirmado que respalde la venta.
2. **Visibilidad de Margen para Asesores (`Agent`):** Resuelto. Los agentes tienen acceso visual al margen comercial bruto (`grossProfit`) en las listas y vistas detalladas de `BudgetLine` para facilitar la toma de decisiones comerciales durante la negociación.