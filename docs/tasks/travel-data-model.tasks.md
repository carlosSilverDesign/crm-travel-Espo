# Backlog de Tareas: Travel Core Data Model & Business Rules

* **Spec de Origen:** `/docs/specs/travel-data-model.spec.md`
* **Plan Técnico:** `/docs/plans/travel-data-model.plan.md`
* **Estrategia de Ejecución:** Tareas atómicas, secuenciales y testeables de manera independiente.

---

## Épica 1: Generación de Metadatos y Estructura Base de Entidades

### TASK-001: Ejecución y verificación de inicialización de metadatos (setup-crm.js)
* **Descripción:** Ejecutar `setup-crm.js` para generar los JSON de configuración bajo `custom/Espo/Custom/Resources/` para las 7 entidades (`Itinerario`, `ItineraryItem`, `BudgetLine`, `Passenger`, `PaymentSchedule`, `Supplier`, `PackageTemplate`) y las extensiones nativas (`Opportunity`, `Contact`).
* **Dependencias:** Ninguna.
* **Definition of Done (DoD):**
  1. Script ejecutado exitosamente sin errores de sintaxis o permisos.
  2. Archivos JSON verificados en `metadata/scopes/`, `metadata/entityDefs/` y `layouts/`.
  3. Ejecutar comando de EspoCRM: `php command.php clear-cache`. Las nuevas entidades son reconocidas por el ORM nativo.

### TASK-002: Configuración de Fórmulas Nativas en BudgetLine
* **Descripción:** Declarar la fórmula matemática en `metadata/app/formula.json` para el cálculo reactivo del precio de venta y el margen bruto en `BudgetLine`.
* **Dependencias:** `TASK-001`.
* **Definition of Done (DoD):**
  1. Registro de prueba en `BudgetLine` persistido con `costPrice = 1000` y `marginRate = 20`.
  2. Comprobar que `sellingPrice` se persiste como `1200` y `grossProfit` como `200`.

---

## Épica 2: Validaciones de Integridad y Lógica de Negocio en PHP (Hooks BeforeSave)

### TASK-003: Hook de Validación de Documentación de Pasajeros
* **Descripción:** Crear la clase `custom/Espo/Custom/Hooks/Passenger/DocumentValidation.php` implementando el evento `beforeSave`.
* **Regla:** Bloquear el guardado si `documentExpiration <= itinerario.endDate`.
* **Dependencias:** `TASK-001`.
* **Definition of Done (DoD):**
  1. Si un pasajero tiene fecha de vencimiento menor o igual al fin del itinerario, EspoCRM arroja una excepción `BadRequest` con mensaje claro.
  2. Si la fecha es superior o nula, el registro se guarda normalmente.

### TASK-004: Hook de Puerta de Entrada para Confirmación de Itinerario
* **Descripción:** Crear la clase `custom/Espo/Custom/Hooks/Itinerario/ConfirmationGate.php` implementando el evento `beforeSave`.
* **Reglas:**
  1. Si `status` cambia a "Confirmado", verificar que `quoteValidUntil >= now()`.
  2. Verificar que la suma acumulada de los registros `PaymentSchedule.amount` asociados sea exactamente igual a `totalSelling`.
* **Dependencias:** `TASK-001`.
* **Definition of Done (DoD):**
  1. Intento de confirmación con fecha expirada arroja HTTP 400.
  2. Intento de confirmación con cronograma de pagos descuadrado arroja HTTP 400.
  3. Confirmación exitosa cuando ambas condiciones se cumplen.

---

## Épica 3: Sincronización Unidireccional y Agregaciones Financieras

### TASK-005: Hook de Agregación Financiera en Itinerario
* **Descripción:** Crear la clase `custom/Espo/Custom/Hooks/BudgetLine/FinancialAggregation.php` implementando `afterSave` y `afterRemove`.
* **Regla:** Recalcular y persistir de forma transaccional `totalCost`, `totalSelling` y `grossProfit` en el `Itinerario` padre cada vez que una línea de costo se altere.
* **Dependencias:** `TASK-002`.
* **Definition of Done (DoD):**
  1. Agregar dos `BudgetLine` de $500 y $300 actualiza automáticamente el `totalCost` del `Itinerario` a $800.
  2. Eliminar una línea descuenta el saldo automáticamente.

### TASK-006: Hook de Sincronización Ascendente (Itinerario -> Opportunity)
* **Descripción:** Crear la clase `custom/Espo/Custom/Hooks/Itinerario/OpportunitySync.php` implementando el evento `afterSave`.
* **Reglas:**
  1. Al cambiar `Itinerario.status` a "Confirmado", actualizar la `Opportunity` vinculada a `stage = "Closed Won"` y `amount = totalSelling`.
  2. Al cambiar a "Cancelado" (sin otros itinerarios activos), cambiar la `Opportunity` a `stage = "Closed Lost"`.
* **Dependencias:** `TASK-004`, `TASK-005`.
* **Definition of Done (DoD):**
  1. Confirmar un itinerario actualiza inmediatamente la etapa de la oportunidad relacionada a "Closed Won" sin intervención manual.

### TASK-007: Guardia de Integridad Comercial en Opportunity
* **Descripción:** Crear la clase `custom/Espo/Custom/Hooks/Opportunity/ClosedWonGuard.php` implementando el evento `beforeSave`.
* **Regla:** Bloquear cualquier mutación manual que intente colocar `stage = "Closed Won"` si no existe al menos un `Itinerario` vinculado en estado "Confirmado".
* **Dependencias:** `TASK-001`.
* **Definition of Done (DoD):**
  1. Intento de marcar "Closed Won" vía UI o API REST sin itinerario confirmado devuelve excepción HTTP 400: "No es posible cerrar como ganada una oportunidad sin un itinerario confirmado".

---

## Épica 4: Permisos (ACL) e Interfaces Visuales (Layouts)

### TASK-008: Configuración de Políticas de Control de Acceso (ACL)
* **Descripción:** Configurar los roles de usuario (`Agent`, `Manager`, `Admin`) para garantizar que `grossProfit` sea visible para todos, mientras que `costPrice` y `marginRate` pasen a solo lectura cuando el `Itinerario` esté en estado "Confirmado".
* **Dependencias:** `TASK-001`, `TASK-004`.
* **Definition of Done (DoD):**
  1. Un usuario con rol `Agent` puede ver el campo `grossProfit` en las listas y vistas de detalle.
  2. Un usuario con rol `Agent` no puede editar costos ni márgenes si el estado es "Confirmado".

### TASK-009: Layouts de Detalle, Edición y Listas
* **Descripción:** Generar los archivos `detail.json`, `list.json` y `record.json` para las entidades del expediente de viaje, garantizando paneles inferiores limpios y ordenados según principios de chunking cognitivo.
* **Dependencias:** `TASK-001`, `TASK-008`.
* **Definition of Done (DoD):**
  1. Las vistas de detalle en `Itinerario` exponen claramente los paneles inferiores para Pasajeros, Servicios, Finanzas y Cronograma de Cobros sin sobrecargar la pantalla principal.

---

## Épica 5: Suite de Pruebas Automatizadas

### TASK-010: Pruebas Unitarias de Cálculos y Validaciones
* **Descripción:** Crear suite PHPUnit bajo `custom/Espo/Custom/Tests/Unit/` que verifique las fórmulas de márgenes y la validación de pasaportes.
* **Dependencias:** `TASK-002`, `TASK-003`.
* **Definition of Done (DoD):**
  1. Test de cálculo de venta y margen ejecutado en verde.
  2. Test de validación de documento de pasajero ejecutado en verde.

### TASK-011: Prueba de Integración E2E del Ciclo de Venta Turístico
* **Descripción:** Script de integración que ejecute el flujo completo vía API de EspoCRM: Crear Oportunidad -> Crear Itinerario -> Asignar Pasajero -> Cargar Costos -> Cuadrar Pagos -> Confirmar Itinerario -> Verificar estado de Oportunidad y bloqueo de mutaciones.
* **Dependencias:** Todas las tareas anteriores completadas.
* **Definition of Done (DoD):**
  1. Ciclo completo verificado de inicio a fin con salida 100% exitosa.