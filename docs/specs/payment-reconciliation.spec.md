# Spec 006: Conciliación de Cobros, Cuentas Bancarias y Verificación de Constancias

* **Módulo:** 06 — Conciliación de Cobros y Cuentas Bancarias (Transferencias & Pasarelas Extensibles)
* **Fecha:** Septiembre 2026
* **Estado:** Borrador de Especificación Formal (SDD)
* **Aislamiento Arquitectural:** 100% de extensiones bajo `custom/Espo/Custom/`. Sin modificaciones sobre `application/` ni el core de EspoCRM.

---

## 1. Visión General y Alcance del Módulo

Especificación funcional y técnica para la administración, recaudación y conciliación de cobros en una agencia de viajes B2C. El sistema soporta operaciones financieras en **Dólares Americanos (USD)** y **Soles Peruanos (PEN)** mediante **transferencia bancaria manual** con verificación humana obligatoria de constancias/vouchers, manteniendo desacoplamiento arquitectónico para admitir pasarelas de pago automatizadas (Stripe, Culqi, Mercado Pago) en fases posteriores.

El diseño preserva la integridad transaccional ACID en MySQL, incorpora compuertas de seguridad para prevenir confirmaciones no autorizadas y aprovecha la infraestructura del micrositio público (`travel-web`) ya desarrollada en el Módulo 05.

---

## 2. Historias de Usuario y Criterios de Aceptación (Gherkin)

### HU-01: Visualización y Filtrado de Cuentas Bancarias por Moneda (Heurística 5 & 6)
Como viajero con una reserva pendiente, quiero consultar únicamente las cuentas bancarias que corresponden a la moneda exacta de mi cobro para evitar comisiones por tipo de cambio no deseadas o errores de transferencia bancaria.

* **Given** un registro de `Payment` con moneda `"USD"` asociado a una `Opportunity` por un monto de $1,250.00 USD.
* **When** el cliente accede a la vista de pago en el micrositio web (`/p/{publicAccessToken}/pay/{paymentId}`).
* **Then** el sistema consulta y lista **exclusivamente** las entidades `BankAccount` activas cuya moneda sea `"USD"`.
* **And** no expone cuentas configuradas en `"PEN"`.
* **And** cada cuenta muestra Banco, Titular, Número de Cuenta, CCI y SWIFT con acciones de copiado en un clic (*Ley de Fitts*).
* **And** destaca la referencia obligatoria de transferencia (ej. `"RES-2026-00452"`) con su respectivo botón de copiado.

### HU-02: Envío de Constancia de Pago por el Cliente (Heurística 1 & Ley de Postel)
Como viajero que realizó la transferencia, quiero informar a la agencia los datos de mi operación y adjuntar una fotografía o PDF del voucher para que inicien la validación de mi reserva.

* **Given** un cobro en estado `"Pending"` o `"ProofUploaded"`.
* **When** el cliente ingresa el número/código de operación bancaria, fecha de transferencia, banco origen (opcional), monto transferido y adjunta un archivo (`.jpg`, `.png`, `.pdf`).
* **Then** el backend valida el tipo MIME y peso del archivo ($\le 10$ MB), creando un registro seguro en la entidad nativa `Attachment`.
* **And** actualiza el `Payment` al estado `"UnderReview"` (En verificación).
* **And** asocia la constancia al campo relacional `proofAttachmentId`.
* **And** muestra al cliente una notificación explícita de estado: *"Tu constancia fue enviada y está pendiente de verificación. El pago será confirmado una vez que la agencia valide el depósito."*
* **And** bajo ninguna circunstancia el estado transiciona a `"Confirmed"` de forma automática tras esta acción.

### HU-03: Verificación y Conciliación Transaccional por Personal Autorizado (Transacciones ACID & Heurística 5)
Como cajero o asesor administrativo autorizado, quiero auditar el comprobante bancario contra la cuenta empresarial de la agencia para confirmar o rechazar el cobro con total trazabilidad.

* **Given** un `Payment` en estado `"UnderReview"` con voucher adjunto.
* **When** un usuario con rol de permisos financieros presiona la acción `"Confirmar Pago"` en EspoCRM:
  * El sistema ejecuta una transacción ACID en MySQL.
  * Cambia el estado del `Payment` a `"Confirmed"`.
  * Registra `verifiedById = $currentUser->id`, `verifiedAt = NOW()`, y notas de auditoría.
  * Reduce el campo `pendingBalance` de la `Opportunity` y abona la cuota correspondiente en `PaymentSchedule`.
  * Si el saldo restante de la `Opportunity` es igual a $0.00, la oportunidad transiciona automáticamente a `"Closed Won"`.
* **When** el usuario autorizado presiona la acción `"Rechazar Pago"`:
  * Exige la captura de un motivo estructurado (`rejectionReason`: *"Monto incorrecto"*, *"Transferencia no encontrada"*, *"Cuenta incorrecta"*, *"Constancia ilegible"*, *"Operación duplicada"*, *"Otro"*).
  * El estado cambia a `"Rejected"`.
  * La `Opportunity` y el `PaymentSchedule` mantienen sus saldos intactos.

### HU-04: Soporte de Pagos Parciales y Múltiples Cuotas
Como director comercial, quiero dividir el costo de una reserva en múltiples cuotas y pagos parciales para facilitar el plan de financiamiento al cliente.

* **Given** una `Opportunity` con monto total de $1,250.00 USD.
* **When** se generan las cuotas comerciales:
  * Cobro 1: $500.00 USD (Confirmado).
  * Cobro 2: $500.00 USD (Confirmado).
  * Cobro 3: $250.00 USD (Pendiente).
* **Then** la `Opportunity` calcula y expone dinámicamente:
  * `totalAmount`: $1,250.00 USD.
  * `amountPaid`: $1,000.00 USD.
  * `pendingBalance`: $250.00 USD.
* **And** prohíbe transaccionalmente que la sumatoria de cobros confirmados supere el monto total pactado (salvo sobrepago explícitamente autorizado).

---

## 3. Máquina de Estados y Matriz de Transiciones (`Payment`)

Para cumplir con la *Heurística 5 (Prevención de Errores)*, el ciclo de vida del cobro se rige por transiciones cerradas y validadas en el backend.

[Pending] ───────────────► [Expired]
       │                          ▲
       │ (Cliente sube voucher)   │ (Supera fecha límite)
       ▼                          │
[UnderReview] ────────────────────┘
   │       │
   │       └───► [Rejected] ──► (Permite reintentar / nuevo voucher)
   │
   └───► [Confirmed] (Transaccional / Inmutable)

| Estado Actual | Transición Permitida Hacia | Acción / Disparador | Requiere Autorización |
| :--- | :--- | :--- | :--- |
| `Draft` | `Pending` | Emisión del cobro por el asesor | Asesor / Sistema |
| `Pending` | `UnderReview` | Cliente informa pago y sube voucher | Público (vía Token) |
| `Pending` | `Expired` | Cron scheduler tras vencer `dueDate` | Sistema |
| `Pending` | `Canceled` | Cancelación de cuota o cotización | Asesor Comercial |
| `UnderReview` | `Confirmed` | Aprobación tras cotejo bancario | Auditor / Cajero |
| `UnderReview` | `Rejected` | Rechazo con registro de motivo | Auditor / Cajero |
| `UnderReview` | `Expired` | Cron scheduler si no se auditó a tiempo | Sistema |
| `Rejected` | `UnderReview` | Cliente reenvía voucher corregido | Público (vía Token) |
| `Confirmed` | *Inmutable* | No admite edición ni retroceso de estado | Bloqueo ORM |

---

## 4. Modelo de Datos y Entidades en EspoCRM

### 4.1. Entidad `BankAccount` (Nueva Entidad Custom)
Almacena las cuentas corrientes de la agencia. Las cuentas no se queman en código frontend.
* `id`: varchar(24) [PK]
* `name`: varchar(150) (ej. "BCP Dólares Corriente Empresa")
* `bankName`: enum(`"BCP"`, `"BBVA"`, `"Interbank"`, `"Scotiabank"`, `"Otro"`)
* `country`: varchar(3) [ISO-3166-1 alpha-3, default: `"PER"`]
* `currency`: enum(`"USD"`, `"PEN"`) [Index: true]
* `accountHolder`: varchar(150) (ej. "DESTINOS VIAJES S.A.C.")
* `accountType`: enum(`"Corriente"`, `"Ahorros"`)
* `accountNumber`: varchar(50)
* `cci`: varchar(50) (Código de Cuenta Interbancario)
* `swiftBic`: varchar(20) [Nullable]
* `intermediaryBankInfo`: text [Nullable]
* `instructions`: text [Nullable]
* `isActive`: bool [default: true]

### 4.2. Entidad `Payment` (Nueva Entidad Custom Transaccional)
Representa cada evento o intención de pago vinculado a la venta.
* `id`: varchar(24) [PK]
* `name`: varchar(100) (Código único, ej. `"PAY-2026-00102"`)
* `paymentReference`: varchar(100) (Referencia pública para el cliente, ej. `"RES-2026-00452-C1"`)
* `opportunityId`: link (`Opportunity`) [Obligatorio, Index: true]
* `paymentScheduleId`: link (`PaymentSchedule`) [Nullable / Cuota asignada]
* `amount`: currency (Monto esperado / exigido)
* `currency`: enum(`"USD"`, `"PEN"`)
* `status`: enum(`"Draft"`, `"Pending"`, `"UnderReview"`, `"Confirmed"`, `"Rejected"`, `"Expired"`, `"Canceled"`)
* `method`: enum(`"bank_transfer"`, `"payment_gateway"`, `"cash"`, `"other"`)
* `gatewayProvider`: enum(`"none"`, `"stripe"`, `"culqi"`, `"mercadopago"`) [Preparación fase futura]
* `destinationBankAccountId`: link (`BankAccount`) [Nullable, asignado por el cliente o sugerido]
* `clientDeclaredAmount`: currency [Monto reportado por el cliente]
* `clientDeclaredDate`: date [Fecha de transferencia reportada]
* `clientOperationNumber`: varchar(100) [Número de operación bancaria]
* `proofAttachmentId`: link (`Attachment`) [Constancia/Voucher almacenado]
* `verifiedById`: link (`User`) [Auditor que aprobó/rechazó]
* `verifiedAt`: datetime [Timestamp de conciliación]
* `rejectionReason`: enum(`"wrong_amount"`, `"transfer_not_found"`, `"invalid_account"`, `"unreadable_voucher"`, `"duplicate_operation"`, `"other"`)
* `verificationNotes`: text [Observaciones internas]
* `dueDate`: date [Fecha límite de pago]

### 4.3. Modificaciones sobre Entidad `Opportunity`
Campos derivados y acumuladores auditables:
* `amountPaidUSD`: currency [Calculado por Hooks tras pagos confirmados en USD]
* `amountPaidPEN`: currency [Calculado por Hooks tras pagos confirmados en PEN]
* `pendingBalance`: currency [Calculado: `amount` - pagos confirmados equivalentes]
* `financialStatus`: enum(`"Unpaid"`, `"PartiallyPaid"`, `"PaidInFull"`, `"Overpaid"`)

---

## 5. Diseño de Interacción y Experiencia de Usuario (UX)

### 5.1. Pantalla de Pago en `travel-web` (Micrositio del Cliente)
* **Chunking y Jerarquía Clara (*Ley de Miller*):**
  1. **Tarjeta de Resumen:** Número de Reserva, Monto Exigido, Moneda y Fecha Límite.
  2. **Selector de Cuenta Destino:** Tarjetas limpias de las cuentas en la moneda exacta. Cada tarjeta incluye botones `Copiar Cuenta` y `Copiar CCI` (*Ley de Fitts*).
  3. **Referencia de Pago Destacada (*Efecto von Restorff*):** Bloque visual diferenciado indicando: *"Coloca esta referencia exacta en el concepto de tu transferencia: `RES-2026-00452-C1`"*.
  4. **Formulario de Declaración:** Botón prominente *"Ya realicé mi transferencia"*, desplegando los campos de Número de Operación, Monto y Subida de Voucher.
* **Tolerancia a Formatos (*Ley de Postel*):** Aceptación de imágenes directas desde la cámara del smartphone (`.jpg`, `.png`) o documentos `.pdf` de aplicativos bancarios móviles.

### 5.2. Panel de Conciliación en EspoCRM (Mesa de Control del Asesor/Cajero)
* **Reconocimiento Visual (*Heurística 6*):** En la vista de detalle de `Payment`, la constancia bancaria se previsualiza directamente en un panel lateral (imagen o visor de PDF incrustado) junto con los datos esperados vs. los declarados por el cliente.
* **Acciones de Decisión Visibles:** Botones de acción contextuales en la cabecera: `[ Confirmar Pago ]` (estilo verde/éxito) y `[ Rechazar Pago ]` (estilo alerta/peligro con modal obligatorio de motivo).

---

## 6. Seguridad y Restricciones de Dominio

1. **Inmutabilidad Financiera:** Un registro de `Payment` con `status == "Confirmed"` bloquea toda mutación mediante Hooks de ORM (`beforeSave`), salvo para administradores de sistema mediante permisos especiales.
2. **Autoridad de Montos:** El monto esperado (`amount`) proviene estrictamente de la entidad en base de datos. Los endpoints públicos rechazan cualquier mutación directa sobre el valor por pagar.
3. **Control de Acceso (RBAC):** La creación y modificación de entidades `BankAccount` queda restringida a roles de administración/finanzas.