# Plan Técnico 006: Conciliación de Cobros, Cuentas Bancarias y Verificación de Constancias

* **Especificación de Referencia:** `/docs/specs/payment-reconciliation.spec.md`
* **Módulo:** 06 — Conciliación de Cobros y Cuentas Bancarias (Transferencias & Pasarelas Extensibles)
* **Metodología:** Spec-Driven Development (SDD)
* **Stack Tecnológico:** PHP 8.2+ (EspoCRM Custom Extensions, ORM Hooks, API Controllers), MySQL 8.0 (Transacciones ACID InnoDB), Node.js / HTML5 (Micrositio `travel-web` con vistas de pago), Docker Compose
* **Regla de Aislamiento:** Todo desarrollo backend se restringe a `custom/Espo/Custom/`. Se reutiliza la infraestructura de archivos nativa (`Attachment`) y los accesos públicos por token del Módulo 05 sin tocar `application/` ni el core de EspoCRM.

---

## 1. Arquitectura del Sistema y Flujo de Conciliación

```text
[Cliente en travel-web]                       [Asesor Comercial / Cajero en EspoCRM]
│                                                     │
│ 1. GET /p/:token/pay/:paymentId                     │
▼                                                     │
[EspoCRM Public Payment API]                                 │
│ (Filtra BankAccount por moneda exacta: USD/PEN)     │
▼                                                     │
[Visualización de Cuentas & Ref]                              │
│                                                     │
│ 2. POST /p/:token/pay/:paymentId/upload-proof       │
│    (Monto, código operación, archivo voucher)       │
▼                                                     │
[EspoCRM Ingestion Hook]                                     │
│                                                     │
│ Crea entidad 'Attachment' nativa                    │
│ Payment status -> 'UnderReview'                     │
│ NO muta a 'Confirmed'                               │
│                                                     │
└──────────────────────────┬──────────────────────────┘
                           │
                           ▼
              [Auditoría en Detalle de Payment]
              (Previsualización de constancia)
                           │
              ┌────────────┴────────────┐
              ▼ (Clic 'Rechazar')       ▼ (Clic 'Confirmar')
      [Modal: Rejection Reason]   [Transacción ACID MySQL]

      status -> 'Rejected'        - status -> 'Confirmed'
      Registra motivo             - verifiedById = UID, verifiedAt = NOW()
      Saldos intactos             - Abona a PaymentSchedule
                                  - Recalcula saldos en Opportunity
                                  - ¿Saldo == 0? -> Closed Won
```

### Principios de Ingeniería y Psicología de Interacción (Leyes de UX & Heurísticas)
* **Integridad Transaccional ACID (MySQL):** Toda mutación de estado a `"Confirmed"` se ejecuta dentro de un bloque transaccional InnoDB (`START TRANSACTION ... COMMIT`), asegurando el recálculo atómico de los acumuladores monetarios en `Opportunity` y `PaymentSchedule`.
* **Heurística 5: Prevención de Errores (NN/g):**
  * La interfaz solo renderiza cuentas bancarias que coincidan con la moneda del cobro (`USD` o `PEN`), eliminando depósitos en moneda errónea.
  * La subida de un comprobante por el cliente **nunca** muta automáticamente el cobro a `"Confirmed"`; requiere verificación explícita por personal autorizado.
* **Heurística 1: Visibilidad del Estado del Sistema (NN/g):** Comunicación inmediata del estado (`"UnderReview"`) tras subir el voucher, con alertas visuales de feedback dentro de los primeros 400 ms (*Umbral de Doherty*).
* **Ley de Fitts:** Botones de copiado de alta proximidad y tamaño accesible ($\ge 44 \times 44\text{ pt}$) junto al número de cuenta, CCI y referencia comercial (`RES-2026-XXXX`) para acelerar la transferencia desde aplicativos móviles.
* **Ley de Tesler (Conservación de la Complejidad):** El recálculo de montos pagados, saldos restantes y transiciones de etapas comerciales se absorbe en hooks de backend, eliminando el cuadre manual en hojas de cálculo.

---

## 2. Definición del Esquema de Datos y Metadatos en EspoCRM

### 2.1. Entidad `BankAccount`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/BankAccount.json`

```json
{
  "fields": {
    "name": {
      "type": "varchar",
      "required": true,
      "maxLength": 150
    },
    "bankName": {
      "type": "enum",
      "required": true,
      "options": ["BCP", "BBVA", "Interbank", "Scotiabank", "Otro"],
      "default": "BCP"
    },
    "country": {
      "type": "varchar",
      "maxLength": 3,
      "default": "PER"
    },
    "currency": {
      "type": "enum",
      "required": true,
      "options": ["USD", "PEN"],
      "index": true
    },
    "accountHolder": {
      "type": "varchar",
      "required": true,
      "maxLength": 150
    },
    "accountType": {
      "type": "enum",
      "options": ["Corriente", "Ahorros"],
      "default": "Corriente"
    },
    "accountNumber": {
      "type": "varchar",
      "required": true,
      "maxLength": 50
    },
    "cci": {
      "type": "varchar",
      "required": true,
      "maxLength": 50
    },
    "swiftBic": {
      "type": "varchar",
      "maxLength": 20
    },
    "instructions": {
      "type": "text"
    },
    "isActive": {
      "type": "bool",
      "default": true,
      "index": true
    }
  }
}
```

### 2.2. Entidad `Payment`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/Payment.json`

```json
{
  "fields": {
    "name": {
      "type": "varchar",
      "readOnly": true,
      "maxLength": 100
    },
    "paymentReference": {
      "type": "varchar",
      "readOnly": true,
      "index": true,
      "maxLength": 100
    },
    "opportunity": {
      "type": "link",
      "entity": "Opportunity",
      "required": true,
      "index": true
    },
    "paymentSchedule": {
      "type": "link",
      "entity": "PaymentSchedule",
      "index": true
    },
    "amount": {
      "type": "currency",
      "required": true
    },
    "currency": {
      "type": "enum",
      "required": true,
      "options": ["USD", "PEN"]
    },
    "status": {
      "type": "enum",
      "options": ["Draft", "Pending", "UnderReview", "Confirmed", "Rejected", "Expired", "Canceled"],
      "default": "Pending",
      "index": true
    },
    "method": {
      "type": "enum",
      "options": ["bank_transfer", "payment_gateway", "cash", "other"],
      "default": "bank_transfer"
    },
    "gatewayProvider": {
      "type": "enum",
      "options": ["none", "stripe", "culqi", "mercadopago"],
      "default": "none"
    },
    "destinationBankAccount": {
      "type": "link",
      "entity": "BankAccount"
    },
    "clientDeclaredAmount": {
      "type": "currency"
    },
    "clientDeclaredDate": {
      "type": "date"
    },
    "clientOperationNumber": {
      "type": "varchar",
      "maxLength": 100
    },
    "proofAttachment": {
      "type": "link",
      "entity": "Attachment"
    },
    "verifiedBy": {
      "type": "link",
      "entity": "User",
      "readOnly": true
    },
    "verifiedAt": {
      "type": "datetime",
      "readOnly": true
    },
    "rejectionReason": {
      "type": "enum",
      "options": [
        "wrong_amount",
        "transfer_not_found",
        "invalid_account",
        "unreadable_voucher",
        "duplicate_operation",
        "other"
      ]
    },
    "verificationNotes": {
      "type": "text"
    },
    "dueDate": {
      "type": "date"
    }
  }
}
```

### 2.3. Extensiones en la Entidad `Opportunity`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/Opportunity.json`

```json
{
  "fields": {
    "amountPaid": {
      "type": "currency",
      "default": 0,
      "readOnly": true
    },
    "pendingBalance": {
      "type": "currency",
      "readOnly": true
    },
    "financialStatus": {
      "type": "enum",
      "options": ["Unpaid", "PartiallyPaid", "PaidInFull", "Overpaid"],
      "default": "Unpaid",
      "readOnly": true
    }
  }
}
```

---

## 3. Implementación Backend: Hooks y Controladores

### 3.1. Hook de Transaccionalidad e Integridad Financiera
Archivo: `custom/Espo/Custom/Hooks/Payment/FinancialReconciliation.php`

* **Punto de ejecución:** `beforeSave` y `afterSave`.
* **Lógica:**
  * Si un cobro ya está en estado `"Confirmed"`, se impide cualquier mutación sobre campos críticos (`amount`, `currency`, `opportunityId`) para preservar la inmutabilidad contable.
  * Al detectar la transición a `"Confirmed"` en `afterSave`, se dispara el servicio de conciliación bajo transacción MySQL:
    $$\text{pendingBalance} = \text{Opportunity.amount} - \sum \text{Payment.amount (Confirmed)}$$
  * Si $\text{pendingBalance} \le 0$, se actualiza `Opportunity.financialStatus = 'PaidInFull'` y `Opportunity.stage = 'Closed Won'`.
  * Si $\text{pendingBalance} > 0$, se actualiza `Opportunity.financialStatus = 'PartiallyPaid'`.

```php
<?php
namespace Espo\Custom\Hooks\Payment;

use Espo\ORM\Entity;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;

class FinancialReconciliation
{
    public function beforeSave(Entity $entity, array $options = []): void
    {
        // Regla: Generación automática de referencia comercial
        if (!$entity->get('paymentReference') && $entity->get('opportunityId')) {
            $entity->set('paymentReference', 'PAY-' . strtoupper(substr(uniqid(), -6)));
        }

        // Regla de Inmutabilidad: Prohibir alterar pagos confirmados
        if ($entity->isAttributeChanged('status') && $entity->getFetched('status') === 'Confirmed') {
            throw new Forbidden("No es posible modificar un pago que ya ha sido confirmado.");
        }

        // Regla de Validación: Rechazo exige motivo
        if ($entity->get('status') === 'Rejected' && !$entity->get('rejectionReason')) {
            throw new BadRequest("Debe especificar un motivo de rechazo válido.");
        }
    }
}
```

### 3.2. Controlador de Acción de Verificación Interna
Archivo: `custom/Espo/Custom/Controllers/PaymentController.php`

* **Rutas:**
  * `POST /Payment/action/confirm`: Recibe `{ id: "..." }`. Verifica rol de cajero/finanzas, asigna `verifiedById = $user->getId()`, `verifiedAt = date('Y-m-d H:i:s')`, `status = 'Confirmed'` y comete la transacción.
  * `POST /Payment/action/reject`: Recibe `{ id: "...", rejectionReason: "...", notes: "..." }`. Valida motivo y muta estado a `'Rejected'`.

### 3.3. Endpoint Público Seguro para el Cliente (Micrositio)
Archivo: `custom/Espo/Custom/Controllers/PublicPaymentController.php`

* **Ruta de Consulta:** `GET /PublicPayment/options/{publicAccessToken}/{paymentId}`
  * Valida existencia y vigencia de la reserva vinculada al `publicAccessToken`.
  * Identifica la moneda del cobro (`Payment.currency`).
  * Realiza query sobre `BankAccount` filtrando por `currency = :currency AND isActive = 1`.
  * Retorna datos seguros de las cuentas (sin datos bancarios internos no autorizados).

* **Ruta de Carga de Comprobante:** `POST /PublicPayment/report/{publicAccessToken}/{paymentId}`
  * **Payload:** `multipart/form-data` con archivo de imagen/PDF, `operationNumber`, `declaredAmount`, `declaredDate`.
  * Valida tamaño de archivo ($\le 10\text{ MB}$) y MIME types (`image/jpeg`, `image/png`, `application/pdf`).
  * Persiste el archivo mediante el servicio de `Attachment` de EspoCRM.
  * Muta `Payment.status = 'UnderReview'` y asocia `proofAttachmentId`.

---

## 4. Integración Frontend en el Micrositio (travel-web)

### 4.1. Vista de Cobro por Transferencia (`PaymentCheckoutView.html`)
Se integra dentro de `travel-web` reutilizando los componentes creados en el Módulo 05:

* **Cabecera de Resumen:** Monto exacto, divisa, fecha límite y referencia de pago destacada con botón de copiado (Efecto von Restorff).
* **Selector Dinámico de Bancos:** Contenedores tipo tarjeta con logotipo del banco, titular, cuenta y CCI. Cada dato cuenta con acción `[Copiar]` accesible con feedback visual inmediato ("¡Copiado!") dentro de los 200 ms (Umbral de Doherty).
* **Formulario Reactivo de Declaración:**
  * Campo numérico para código de operación bancaria.
  * Selector de fecha de transferencia.
  * Drag & Drop / Input file nativo para subir la captura o constancia PDF.
  * Botón primario: "Informar Transferencia".

---

## 5. Estrategia de Pruebas y Validación (Fases SDD 5 y 6)

### 5.1. Prueba Unitaria de Filtrado de Cuentas por Moneda
* Crear cuentas bancarias: BCP USD, BBVA USD, BCP PEN, Interbank PEN.
* Ejecutar endpoint para un cobro emitido en USD.
* **Aserción:** La respuesta JSON contiene exactamente las 2 cuentas USD; ninguna cuenta PEN es transmitida al cliente.

### 5.2. Prueba de Ingesta de Voucher y Transición Segura
* Enviar payload público con archivo JPG válido a un cobro en estado `Pending`.
* **Aserción:**
  * `Payment.status` pasa a `UnderReview`.
  * `proofAttachmentId` apunta a un registro real en la tabla `attachment`.
  * El estado de la `Opportunity` no se altera a `Closed Won`.

### 5.3. Prueba Transaccional de Conciliación y Closed Won
* Crear `Opportunity` por $1,000.00 USD con dos cobros de $500.00 USD.
* Ejecutar confirmación sobre el Cobro 1.
* **Aserción:** `Opportunity.amountPaid` = $500.00, `pendingBalance` = $500.00, `financialStatus` = `'PartiallyPaid'`, etapa comercial permanece inalterada.
* Ejecutar confirmación sobre el Cobro 2.
* **Aserción:** `Opportunity.amountPaid` = $1,000.00, `pendingBalance` = $0.00, `financialStatus` = `'PaidInFull'`, `Opportunity.stage` muta atómicamente a `'Closed Won'`.

### 5.4. Prueba de Bloqueo contra Manipulación e Inmutabilidad
* Intentar mutar `amount` o `currency` vía API sobre un `Payment` en estado `Confirmed`.
* **Aserción:** El ORM rechaza la transacción arrojando una excepción HTTP 403 Forbidden.