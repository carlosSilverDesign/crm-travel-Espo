# Tasks 006: Conciliación de Cobros, Cuentas Bancarias y Verificación de Constancias

* **Módulo:** 06 — Conciliación de Cobros y Cuentas Bancarias (Transferencias & Pasarelas Extensibles)
* **Especificación de Referencia:** `/docs/specs/payment-reconciliation.spec.md`
* **Plan Técnico de Referencia:** `/docs/plans/payment-reconciliation.plan.md`
* **Metodología:** Spec-Driven Development (SDD)
* **Regla de Oro de Aislamiento:** Todo el desarrollo backend reside bajo `custom/Espo/Custom/` sin alterar el core en `application/`.
* **Principio Transaccional y de Seguridad:** La constancia subida por el cliente **nunca** muta automáticamente el cobro a confirmado. Toda confirmación exige verificación autorizada y transacción ACID en MySQL.

---

## 1. Matriz de Dependencias

```text
[TASK-037: Entidad BankAccount & Seed Cuentas USD/PEN]
│
├───► [TASK-038: Entidad Payment & Extensión de Opportunity]
│             │
│             ▼
├───► [TASK-039: Hook Transaccional & FinancialReconciliationService]
│             │
│             ▼
├───► [TASK-040: API Pública & Pantalla de Cobro en travel-web]
│             │
│             ▼
├───► [TASK-041: Modal & Flujo de Verificación / Rechazo en EspoCRM]
│             │
│             ▼
└───► [TASK-042: Suite de Pruebas Unitarias & E2E de Conciliación]
```

---

## 2. Definición Atómica de Tareas

### Épica 1: Catálogo Seguro de Cuentas Bancarias Empresariales

#### `TASK-037` — Creación de Entidad `BankAccount` y Poblado por Moneda
* **Archivos:**
  * `custom/Espo/Custom/Resources/metadata/entityDefs/BankAccount.json`
  * `custom/Espo/Custom/Resources/metadata/scopes/BankAccount.json`
  * `custom/Espo/Custom/Resources/metadata/clientDefs/BankAccount.json`
* **Descripción:**
  * Declarar campos del esquema: `name`, `bankName` (enum: BCP, BBVA, Interbank, Scotiabank, Otro), `country` (default: `"PER"`), `currency` (enum: USD, PEN [index: true]), `accountHolder`, `accountType` (Corriente, Ahorros), `accountNumber`, `cci`, `swiftBic`, `instructions`, `isActive` (bool, default: true).
  * Configurar listas, filtros de búsqueda y vistas administrativas (`Record`, `List`, `Detail`).
  * Establecer permisos RBAC para que solo usuarios con rol de finanzas o administración puedan crear/editar cuentas.
* **Criterios de Aceptación (DoD):**
  * `php command.php rebuild` genera la tabla `bank_account` en MySQL sin advertencias.
  * Creación exitosa desde interfaz/seed de al menos una cuenta en USD y una cuenta en PEN.

---

### Épica 2: Modelo Transaccional de Cobros y Vínculo Comercial

#### `TASK-038` — Entidad `Payment` y Extensiones Financieras en `Opportunity`
* **Archivos:**
  * `custom/Espo/Custom/Resources/metadata/entityDefs/Payment.json`
  * `custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`
  * `custom/Espo/Custom/Resources/metadata/entityDefs/PaymentSchedule.json`
  * `custom/Espo/Custom/Resources/metadata/relationships/OpportunityPayment.json`
* **Descripción:**
  * Declarar campos de `Payment`: `name`, `paymentReference` (index: true), `opportunityId`, `paymentScheduleId`, `amount`, `currency` (USD/PEN), `status` (Draft, Pending, UnderReview, Confirmed, Rejected, Expired, Canceled), `method` (default: bank_transfer), `gatewayProvider` (none, stripe, culqi, mercadopago), `destinationBankAccountId`, `clientDeclaredAmount`, `clientDeclaredDate`, `clientOperationNumber`, `proofAttachmentId` (link a `Attachment`), `verifiedById`, `verifiedAt`, `rejectionReason`, `verificationNotes`, `dueDate`.
  * Extender `Opportunity` con campos derivados de solo lectura: `amountPaid` (currency), `pendingBalance` (currency), `financialStatus` (enum: Unpaid, PartiallyPaid, PaidInFull, Overpaid).
  * Configurar relaciones 1:N entre `Opportunity` $\to$ `Payment` y 1:N entre `PaymentSchedule` $\to$ `Payment`.
* **Criterios de Aceptación (DoD):**
  * Esquema persistido en base de datos.
  * Relaciones visibles en paneles de EspoCRM sin dependencias circulares rotas.

---

### Épica 3: Motor de Conciliación e Integridad Contable

#### `TASK-039` — Hook `FinancialReconciliation` y Transaccionalidad ACID
* **Archivos:**
  * `custom/Espo/Custom/Hooks/Payment/FinancialReconciliation.php`
  * `custom/Espo/Custom/Services/FinancialReconciliationService.php`
* **Descripción:**
  * En `beforeSave`:
    * Generar automáticamente `paymentReference` (formato `"PAY-"` + 6 alfanuméricos en mayúscula) si es nueva entidad.
    * Prohibir mutaciones en `amount`, `currency` o `opportunityId` si el registro ya se encuentra en `status == 'Confirmed'` (Inmutabilidad financiera / Heurística 5).
    * Validar que si `status == 'Rejected'`, el campo `rejectionReason` no esté vacío.
  * En `afterSave`:
    * Al transicionar a `Confirmed`, ejecutar bajo transacción ACID en MySQL:
      1. Sumar los montos de todos los `Payment` confirmados vinculados a la `Opportunity`.
      2. Actualizar `amountPaid` y `pendingBalance` en `Opportunity`.
      3. Determinar `financialStatus`: si `pendingBalance <= 0` $\to$ `'PaidInFull'` y transicionar `Opportunity.stage = 'Closed Won'`; si no $\to$ `'PartiallyPaid'`.
      4. Si existe `paymentScheduleId`, mutar el estado de la cuota a `'Pagada'`.
* **Criterios de Aceptación (DoD):**
  * La confirmación de un cobro actualiza el balance de la oportunidad en una única transacción atómica.
  * Intentar modificar un pago confirmado arroja excepción `403 Forbidden`.

---

### Épica 4: Frontend del Cliente y Reporte de Pagos en Micrositio

#### `TASK-040` — API Pública de Pagos y Pantalla de Cobro en `travel-web`
* **Archivos:**
  * `custom/Espo/Custom/Controllers/PublicPaymentController.php`
  * `custom/Espo/Custom/Resources/routes.json`
  * `docker/travel-web/src/views/PaymentCheckoutView.html`
  * `docker/travel-web/src/public/js/payment-checkout.js`
* **Descripción:**
  * Implementar endpoint `GET /PublicPayment/options/{publicAccessToken}/{paymentId}`:
    * Validar vigencia de token público del itinerario/oportunidad.
    * Obtener divisa del cobro y retornar **únicamente** las cuentas de `BankAccount` activas que coincidan con `currency`.
  * Implementar endpoint `POST /PublicPayment/report/{publicAccessToken}/{paymentId}`:
    * Recibir `multipart/form-data`: comprobante adjunto, `operationNumber`, `declaredAmount`, `declaredDate`.
    * Validar tipos MIME (`.jpg`, `.png`, `.pdf`) y tamaño $\le 10\text{ MB}$.
    * Crear entidad `Attachment` nativa y mutar `Payment.status = 'UnderReview'`.
  * En el frontend (`travel-web`):
    * Renderizar tarjetas bancarias según moneda con botones `[Copiar Cuenta]` y `[Copiar CCI]` con feedback visual inmediato (<200 ms / Doherty Threshold / Ley de Fitts).
    * Destacar la referencia de pago obligatoria (`paymentReference`).
    * Formulario de reporte con feedback explícito de estado: *"Constancia enviada y pendiente de verificación"*.
* **Criterios de Aceptación (DoD):**
  * Un cobro en USD muestra únicamente cuentas USD en la web.
  * La subida de voucher muta el estado exclusivamente a `UnderReview` y no confirma la reserva.

---

### Épica 5: Mesa de Control y Validación para Cajeros/Asesores

#### `TASK-041` — Panel de Auditoría, Visualizador de Voucher y Acciones de Decisión [COMPLETADO]
* **Archivos:**
  * `custom/Espo/Custom/Controllers/PaymentController.php`
  * `custom/Espo/Custom/Controllers/Payment.php`
  * `custom/Espo/Custom/Resources/routes.json`
  * `custom/Espo/Custom/Resources/metadata/clientDefs/Payment.json`
  * `custom/Espo/Custom/Resources/layouts/Payment/detail.json`
  * `custom/Espo/Custom/Resources/metadata/app/client.json`
  * `custom/Espo/Custom/Resources/client/payment.js`
  * `client/custom/modules/travel/payment.js`
  * `custom/Espo/Custom/Tests/Unit/PaymentControllerTest.php`
* **Descripción:**
  * Implementar acciones de cabecera en el detalle de `Payment`: `[Confirmar Pago]` y `[Rechazar Pago]` visibles solo cuando `status === 'UnderReview'` para usuarios autorizados (Admin, Finanzas, Cajero).
  * Incorporar panel lateral de previsualización de constancia (`Attachment` de imagen o visor PDF embebido) junto a los datos comparativos (Monto Esperado vs. Monto Declarado y Operación) (Heurística 6 y Ley de Miller).
  * Controlador `POST /Payment/action/confirm`:
    * Valida permisos RBAC, asigna `verifiedById = currentUserId`, `verifiedAt = NOW()`, `status = 'Confirmed'`.
    * Dispara automáticamente el hook atómico ACID de MySQL (`FinancialReconciliationService`).
  * Controlador `POST /Payment/action/reject`:
    * Modal para captura obligatoria de `rejectionReason` (enum estructurado) y `verificationNotes`, pasando a estado `'Rejected'`.
* **Criterios de Aceptación (DoD):**
  * [x] El asesor puede previsualizar el voucher sin descargarlo y confirmar o rechazar en 1 clic.
  * [x] Auditoría completa registrada con fecha, hora y usuario verificador.
  * [x] Suite de pruebas unitarias al 100% (11 tests, 47 aserciones).

---

### Épica 6: Pruebas Automatizadas y Validación Transaccional

#### `TASK-042` — Suite de Pruebas Unitarias, Integración ACID y Resiliencia [COMPLETADO]
* **Archivos:**
  * `custom/Espo/Custom/Tests/Unit/FinancialReconciliationTest.php`
  * `tests/integration/PaymentLifecycleE2ETest.php`
  * `custom/Espo/Custom/Tests/Integration/PaymentLifecycleE2ETest.php`
* **Descripción:**
  * **Test Unitario 1 (Filtro Cuentas):** Comprobar que cobros en PEN nunca reciban cuentas en USD desde el endpoint público, y cobros en USD reciban exclusivamente cuentas en USD.
  * **Test Unitario 2 (Inmutabilidad):** Intentar alterar campos financieros de un cobro confirmado y validar captura de `ForbiddenException`.
  * **Test Unitario 3 (Validación de Motivos):** Rechazo sin motivo estructurado arroja `BadRequestException`.
  * **Test E2E 4 (Flujo Completo & Pagos Parciales):**
    1. Reserva por $1,000 USD dividida en dos cuotas de $500 USD.
    2. Subida de voucher cuota 1 $\to$ verificar estado `UnderReview` y saldo pendiente $1,000 USD.
    3. Confirmación manual cuota 1 $\to$ verificar saldo $500 USD y estado `PartiallyPaid`.
    4. Subida y confirmación cuota 2 $\to$ verificar saldo $0.00 USD, estado `PaidInFull` y transición automática de la `Opportunity` a `Closed Won`.
* **Criterios de Aceptación (DoD):**
  * [x] Cero modificaciones al core (`application/`).
  * [x] Cobertura de aislamiento por divisa al 100% (cero cuentas cruzadas).
  * [x] Inmutabilidad y motivos validados (Heurística 5 y 9: Forbidden y BadRequest).
  * [x] Transaccionalidad ACID y pagos parciales comprobados (500 USD $\to$ 1000 USD, Closed Won automático).
  * [x] 100% de aserciones en verde ejecutadas con PHPUnit/CLI (73 tests, 506 aserciones en total).