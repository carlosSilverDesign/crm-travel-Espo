# Plan Técnico 002: Adaptación del Pipeline Comercial de Viajes

* **Especificación de Referencia:** `/docs/specs/travel-sales-pipeline.spec.md`
* **Módulo:** 02 - Pipeline Comercial Adaptado (Opportunity / Deals)
* **Objetivo:** Adaptar la entidad nativa `Opportunity` al ciclo de ventas turísticas B2C, configurar la vista Kanban con chunking cognitivo y habilitar compuertas de seguridad transaccional e integridad financiera.

---

## 1. Arquitectura de Metadatos y Extensiones de Datos

Toda modificación sobre `Opportunity` se implementa de forma aditiva sobre el directorio `custom/Espo/Custom/Resources/metadata/` para preservar la inmunidad ante actualizaciones del núcleo.

### 1.1. Campos Extendidos en `entityDefs/Opportunity.json`
* `stage`: Modificación de la lista de selección para reflejar el ciclo turístico.
  * Opciones: `['Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'PaymentPending', 'Closed Won', 'Closed Lost']`.
  * Parámetro `probabilityMap`:
    * `Prospecting`: 10
    * `Qualification`: 25
    * `Proposal`: 50
    * `Negotiation`: 75
    * `PaymentPending`: 90
    * `Closed Won`: 100
    * `Closed Lost`: 0
* `destination`: `varchar(100)` — Destino principal solicitado en la prospección inicial.
* `travelStartDate`: `date` — Fecha tentativa o confirmada de salida.
* `travelEndDate`: `date` — Fecha tentativa o confirmada de regreso.
* `leadSource`: `enum` — Origen del prospecto (`['WhatsApp', 'Instagram', 'Facebook', 'Referido', 'Web', 'Presencial']`).
* `projectedGrossProfit`: `currency` (`readOnly: true`) — Margen bruto total calculado desde el itinerario activo.
* `lostReason`: `enum` (`['Precio / Presupuesto Alto', 'Eligió Competencia', 'Canceló Viaje', 'Sin Respuesta / Fantasma', 'Fechas / Cupos No Disponibles', 'Otro']`).
* `lostReasonDetails`: `text` — Justificación cualitativa de descarte.

---

## 2. Decisiones de Diseño Técnico y Lógica de Negocio

### D1: Compuerta de Integridad en "Espera de Pago" (`PaymentPendingGate.php`)
* **Principio:** Heurística 5 (Prevención de Errores) y Heurística 9 (Recuperación con Lenguaje Claro)[cite: 4].
* **Ubicación:** `custom/Espo/Custom/Hooks/Opportunity/PaymentPendingGate.php`
* **Evento:** `beforeSave(Entity $entity, array $options = []): void`
* **Lógica:**
  1. Si `stage` cambia a `'PaymentPending'`:
  2. Consultar mediante el `EntityManager` si existe al menos un `Itinerario` vinculado (`opportunityId == $entity->getId()`) con `status == 'Cotización'`.
  3. Si no existe ninguno, arrojar `\Espo\Core\Exceptions\BadRequest` con el mensaje:
     *"No es posible pasar a Espera de Pago sin un Itinerario en cotización vinculado. Cree o asocie un itinerario primero."*

### D2: Modelo Híbrido de Autoridad Financiera (`FinancialSyncHook.php`)
* **Principio:** Ley de Tesler (Conservación de la Complejidad) y Transacciones ACID[cite: 1, 3].
* **Lógica Bidireccional:**
  * **Etapas Iniciales sin Itinerario:** Si no existen itinerarios asociados a la oportunidad, el asesor puede registrar manualmente el campo `amount` (presupuesto estimado del cliente).
  * **Sincronización Automática tras Creación de Itinerario:**
    * Archivo a extender: `custom/Espo/Custom/Hooks/Itinerario/OpportunitySync.php` (TASK-006 previo).
    * Al guardar un `Itinerario` (nuevo o actualizado en `totalSelling` / `grossProfit`):
      1. Sincronizar en la `Opportunity` vinculada:
         * `amount` = `Itinerario.totalSelling`
         * `projectedGrossProfit` = `Itinerario.grossProfit`
         * `destination` = `Itinerario.destination` (si este no estaba fijado)
         * `travelStartDate` = `Itinerario.startDate`
         * `travelEndDate` = `Itinerario.endDate`
      2. Persistir mediante `$this->getEntityManager()->saveEntity($opportunity, ['skipHooks' => true])`.

### D3: Regla de Tipificación Obligatoria de Pérdida (`LostReasonGuard.php`)
* **Principio:** Integridad de datos para reportería comercial y Heurística 5 (Prevención de Errores)[cite: 1, 4].
* **Ubicación:** `custom/Espo/Custom/Hooks/Opportunity/LostReasonGuard.php`
* **Evento:** `beforeSave(Entity $entity, array $options = []): void`
* **Lógica:**
  1. Si `stage` cambia a `'Closed Lost'`:
  2. Verificar que `lostReason` no sea nulo ni vacío.
  3. Si está vacío, lanzar `\Espo\Core\Exceptions\BadRequest`:
     *"Debe especificar el motivo de pérdida (lostReason) para cerrar la oportunidad como perdida."*

### D4: Control de Acceso y Edición Dinámica (ACL de Campo)
* **Ubicación:** `custom/Espo/Custom/Acl/Opportunity.php`
* **Lógica:**
  * Método `checkReadOnlyField(\Espo\ORM\Entity $entity, string $field, \Espo\Entities\User $user): bool`:
    * Si `$user->isAdmin()`, retornar `false`.
    * Si `$field === 'amount'`:
      * Consultar si existen itinerarios vinculados a la oportunidad.
      * Si existen itinerarios, retornar `true` (modo solo-lectura, gestionado por backend).
      * Si no existen itinerarios, retornar `false` (permite ingresar presupuesto estimado).
    * Si `$field === 'projectedGrossProfit'`, retornar siempre `true`.

---

## 3. Configuración Visual e Interfaz (Kanban y Detalle)

### 3.1. Layout Kanban Turístico (`custom/Espo/Custom/Resources/layouts/Opportunity/kanban.json`)
* **Principio:** Ley de Miller (*chunking* cognitivo) y Reconocimiento antes que Recuerdo (Heurística 6)[cite: 3, 4].
* **Estructura de la tarjeta:**
  * Encabezado: `name` (enlace al registro)
  * Línea 1: `contact` + `destination`
  * Línea 2: `travelStartDate` - `travelEndDate`
  * Línea 3: `amount` (destacado) + `projectedGrossProfit` (etiqueta secundaria)
  * Pie: `leadSource`

### 3.2. Vistas de Detalle y Edición (`layouts/Opportunity/detail.json`)
* **Panel 1: Información Comercial (General)**
  * `name`, `stage`, `contact`, `leadSource`, `destination`, `travelStartDate`, `travelEndDate`.
* **Panel 2: Resumen Financiero y Rentabilidad**
  * `amount`, `projectedGrossProfit`, `probability`.
* **Panel 3: Cierre y Descarte (Condicional)**
  * `lostReason`, `lostReasonDetails`.

---

## 4. Plan de Pruebas y Validación

1. **Unit Tests (PHPUnit):**
   * Validación de `PaymentPendingGate` (rechazo si no hay itinerario en cotización).
   * Validación de `LostReasonGuard` (rechazo si `stage == Closed Lost` sin motivo).
2. **Integration / E2E:**
   * Crear Oportunidad con `amount = 3000` (manual).
   * Intentar mover a `PaymentPending` -> Falla con error 400.
   * Crear `Itinerario` en Cotización (`totalSelling = 2850`, `grossProfit = 400`).
   * Verificar que `Opportunity.amount` se recalculó a 2850 y `projectedGrossProfit` a 400.
   * Mover a `PaymentPending` -> Éxito.
   * Confirmar `Itinerario` -> Sincroniza a `Closed Won` automáticamente.