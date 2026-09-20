# Validación de Aceptación: Feature 002 - Pipeline Comercial Adaptado

* **Especificación:** `/docs/specs/travel-sales-pipeline.spec.md`
* **Plan Técnico:** `/docs/plans/travel-sales-pipeline.plan.md`
* **Backlog de Tareas:** `/docs/tasks/travel-sales-pipeline.tasks.md`
* **Fecha de Validación:** 19 de Septiembre de 2026
* **Entorno de Prueba:** Docker Contenerizado (EspoCRM CLI, MySQL 8, PHPUnit)

---

## 1. Matriz de Trazabilidad y Criterios de Aceptación

| ID Historia | Criterio de Aceptación (Spec) | Tarea de Origen | Validación Técnica / Heurística | Estado |
| :--- | :--- | :--- | :--- | :--- |
| **HU-01** | Etapas turísticas estandarizadas en `Opportunity` con `probabilityMap`. | `TASK-012` | Mapeo 10% a 100% verificado. *Match between system and real world* (Heurística 2 NN/g). | ✅ PASADO |
| **HU-02** | Compuerta de integridad: Bloqueo de paso a `PaymentPending` sin `Itinerario` en 'Cotización'. | `TASK-013`, `TASK-018`, `TASK-019` | HTTP 400 (`BadRequest`) ante avance en frío. *Error Prevention* (Heurística 5 NN/g). | ✅ PASADO |
| **HU-03** | Autoridad del monto: Presupuesto manual en prospección y cálculo reactivo tras vincular itinerario. | `TASK-014`, `TASK-016`, `TASK-019` | Hook sincroniza `amount` y `projectedGrossProfit`; ACL conmuta campo a `readOnly`. *Ley de Tesler*. | ✅ PASADO |
| **HU-04** | Tarjetas Kanban con datos esenciales visibles (destino, fechas, montos, márgenes). | `TASK-017` | Layout `kanban.json` verificado. *Chunking cognitivo* (Ley de Miller) y *Reconocimiento antes que recuerdo* (Heurística 6 NN/g). | ✅ PASADO |
| **HU-05** | Tipificación obligatoria de descarte: Bloqueo de paso a `Closed Lost` si `lostReason` está vacío. | `TASK-015`, `TASK-018`, `TASK-019` | HTTP 400 ante descarte sin motivo. Comunicación en lenguaje claro (Heurística 9 NN/g). | ✅ PASADO |

---

## 2. Verificación de Principios No Negociables
* **Inmunidad de Actualización:** 100% de los metadatos, hooks, layouts y clases ACL residen bajo `custom/Espo/Custom/`. El core de EspoCRM permanece intacto.
* **Integridad Transaccional:** Validación y sincronización ejecutadas en el ORM con soporte de transacciones ACID en MySQL.