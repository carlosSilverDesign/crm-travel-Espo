# Plan Técnico 007: Operación en Destino, Incidentes y Post-Venta

* **Especificación de Referencia:** `/docs/specs/in-trip-post-trip-operations.spec.md`
* **Módulo:** 07 — Operación en Destino y Post-Venta
* **Metodología:** Spec-Driven Development (SDD)
* **Stack Tecnológico:** PHP 8.2+ (EspoCRM Custom Entities, Controllers, Hooks, Scheduled Jobs), MySQL 8.0 (InnoDB), Activepieces Community Edition (Docker), Chatwoot API (WhatsApp Cloud API Integration)
* **Regla de Oro de Aislamiento:** Todo el desarrollo backend debe residir estrictamente bajo `custom/Espo/Custom/`. No se modifican archivos del núcleo en `application/`.

---

## 1. Arquitectura del Flujo Operativo y Automatización

```text
                [EspoCRM Scheduled Job (Cron)]
                              │
                              ▼ (Ejecución Diaria 09:00 UTC)
             [InTripOperationsService::processDailyBatches()]
                              │
     ┌────────────────────────┴────────────────────────┐
     ▼                                                 ▼
[1. Evaluar Pasajeros "En Viaje"]             [2. Disparo Post-Viaje NPS]
- Actualiza estado operativo                  - Filtra Itinerarios con endDate == ayer
- Alimenta caché de Tablero Operativo         - Emite Webhook a Activepieces
                                                       │
                                                       ▼
                                             [Activepieces Flow]
                                             - Envía Template WhatsApp vía Chatwoot
                                             - Escucha respuesta (1-10)
                                             - POST /api/v1/Feedback en EspoCRM
```

### Principios de Ingeniería y Psicología UX
* **Peak-End Rule:** La percepción global del viaje se fija en los puntos de máxima emoción y en el retorno. El contacto estructurado dentro de las primeras 24 horas del regreso incrementa las tasas de respuesta y permite mitigar detractores antes de la exposición de quejas públicas.
* **Heurística 6: Reconocimiento antes que Recuerdo (NN/g):** La consola operativa centraliza en una sola vista los vouchers, servicios del día y teléfonos de proveedores locales para que el operador de guardia no dependa de memoria ni búsquedas dispersas ante una emergencia.
* **Umbral de Doherty (<400 ms):** Las consultas del tablero operativo se resuelven mediante índices compuestos sobre `startDate`, `endDate` y `status`, garantizando respuestas instantáneas.
* **Heurística 5: Prevención de Errores (NN/g):** El registro y solución de disrupciones operativas mediante `Incident` no interviene ni muta la contabilidad de la `Opportunity` ni los cobros en `Payment`.

---

## 2. Definición del Esquema de Datos y Metadatos en EspoCRM

### 2.1. Metadatos de la Entidad `Incident`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/Incident.json`

```json
{
  "fields": {
    "name": {
      "type": "varchar",
      "required": true,
      "maxLength": 150
    },
    "opportunity": {
      "type": "link",
      "entity": "Opportunity",
      "required": true,
      "index": true
    },
    "itinerario": {
      "type": "link",
      "entity": "Itinerario",
      "index": true
    },
    "itemItinerario": {
      "type": "link",
      "entity": "ItemItinerario"
    },
    "severity": {
      "type": "enum",
      "options": ["Low", "Medium", "High", "Critical"],
      "default": "Medium",
      "required": true
    },
    "category": {
      "type": "enum",
      "options": [
        "FlightDelay",
        "SupplierFailure",
        "HealthEmergency",
        "WeatherForceMajeure",
        "CustomerComplaint",
        "Other"
      ],
      "required": true
    },
    "status": {
      "type": "enum",
      "options": ["Reported", "InInvestigation", "Resolved", "Escalated"],
      "default": "Reported",
      "index": true
    },
    "costImpact": {
      "type": "currency",
      "default": 0.0
    },
    "resolutionPlan": {
      "type": "text"
    },
    "resolvedAt": {
      "type": "datetime"
    },
    "reportedBy": {
      "type": "link",
      "entity": "User"
    }
  }
}
```

### 2.2. Metadatos de la Entidad `Feedback`
Archivo: `custom/Espo/Custom/Resources/metadata/entityDefs/Feedback.json`

```json
{
  "fields": {
    "name": {
      "type": "varchar",
      "required": true,
      "maxLength": 100
    },
    "contact": {
      "type": "link",
      "entity": "Contact",
      "required": true,
      "index": true
    },
    "opportunity": {
      "type": "link",
      "entity": "Opportunity",
      "index": true
    },
    "npsScore": {
      "type": "int",
      "required": true,
      "min": 1,
      "max": 10
    },
    "sentiment": {
      "type": "enum",
      "options": ["Promoter", "Passive", "Detractor"],
      "readOnly": true,
      "index": true
    },
    "comments": {
      "type": "text"
    },
    "channel": {
      "type": "varchar",
      "default": "WhatsApp",
      "readOnly": true
    },
    "followUpRequired": {
      "type": "bool",
      "default": false
    },
    "followUpStatus": {
      "type": "enum",
      "options": ["Pending", "Contacted", "Resolved", "NotNeeded"],
      "default": "NotNeeded"
    }
  }
}
```

---

## 3. Implementación Backend: Hooks, Servicios y Scheduled Jobs

### 3.1. Hook de Clasificación Automática de NPS y Manejo de Detractores
Archivo: `custom/Espo/Custom/Hooks/Feedback/ClassifyNpsScore.php`

* **Trigger:** `beforeSave` y `afterSave`.
* **Lógica:**
  * Valida que `npsScore` esté comprendido entre 1 y 10.
  * Si `npsScore >= 9`: `sentiment = 'Promoter'`, `followUpRequired = false`, `followUpStatus = 'NotNeeded'`.
  * Si `npsScore` es 7 u 8: `sentiment = 'Passive'`, `followUpRequired = false`, `followUpStatus = 'NotNeeded'`.
  * Si `npsScore <= 6`: `sentiment = 'Detractor'`, `followUpRequired = true`, `followUpStatus = 'Pending'`.
  * En `afterSave`, si es detractor, crea automáticamente una entidad `Task` urgente asignada al supervisor de calidad con SLA de 2 horas.

```php
<?php
namespace Espo\Custom\Hooks\Feedback;

use Espo\ORM\Entity;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;

class ClassifyNpsScore
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, array $options = []): void
    {
        $score = (int) $entity->get('npsScore');
        if ($score < 1 || $score > 10) {
            throw new BadRequest("El NPS Score debe ubicarse estrictamente entre 1 y 10.");
        }

        if ($score >= 9) {
            $entity->set('sentiment', 'Promoter');
            $entity->set('followUpRequired', false);
            $entity->set('followUpStatus', 'NotNeeded');
        } elseif ($score >= 7) {
            $entity->set('sentiment', 'Passive');
            $entity->set('followUpRequired', false);
            $entity->set('followUpStatus', 'NotNeeded');
        } else {
            $entity->set('sentiment', 'Detractor');
            $entity->set('followUpRequired', true);
            $entity->set('followUpStatus', 'Pending');
        }
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        if ($entity->get('sentiment') === 'Detractor' && $entity->isAttributeChanged('sentiment')) {
            $task = $this->entityManager->getEntity('Task');
            $task->set([
                'name' => 'Atención Urgente Detractor: ' . $entity->get('name'),
                'priority' => 'Urgent',
                'status' => 'Not Started',
                'parentType' => 'Feedback',
                'parentId' => $entity->getId(),
                'description' => 'Calificación: ' . $entity->get('npsScore') . "\nComentarios: " . $entity->get('comments')
            ]);
            $this->entityManager->saveEntity($task);
        }
    }
}
```

### 3.2. Scheduled Job de Detección Post-Viaje
Archivo: `custom/Espo/Custom/Jobs/TriggerPostTripSurveyJob.php`

* **Programación:** Cron diario a las 09:00 UTC.
* **Lógica:**
  * Consulta itinerarios con `status = 'Confirmado'` y `endDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)`.
  * Obtiene el `Contact` titular y su teléfono normalizado en formato E.164.
  * Despacha payload HTTP hacia Activepieces (`POST http://activepieces:3000/api/v1/webhooks/...`):

```json
{
  "itineraryId": "uuid-itinerario",
  "contactId": "uuid-contacto",
  "phone": "+51999888777",
  "clientName": "Juan Pérez",
  "destination": "Cusco Mágico"
}
```

### 3.3. Controlador y SelectManager para el Tablero Operativo
Archivo: `custom/Espo/Custom/SelectManagers/Itinerario.php`

* **Filtro Primario (`currentInDestination`):** Aplica la condición de rango de fechas activa:

```sql
WHERE status = 'Confirmado'
  AND startDate <= CURDATE()
  AND endDate >= CURDATE()
```

---

## 4. Orquestación Activepieces: Flujo de Encuesta WhatsApp

* **Trigger:** Webhook POST receptor proveniente del Cron de EspoCRM.
* **Paso 1:** Envía plantilla aprobada de WhatsApp vía Chatwoot / Meta Cloud API con mensaje de bienvenida y solicitud de puntaje del 1 al 10.
* **Paso 2:** Espera mensaje entrante de respuesta en el hilo del cliente en Chatwoot.
* **Paso 3 (Data Parser):** Extrae el primer dígito numérico del mensaje recibido.
* **Paso 4 (Ingesta EspoCRM):** Ejecuta llamada a la API REST de EspoCRM:
  `POST /api/v1/Feedback`
  Payload: `{ contactId, opportunityId, npsScore, comments, channel: "WhatsApp" }`

---

## 5. Estrategia de Pruebas y Validación (Fases SDD 5 y 6)

### 5.1. Prueba Unitaria del Hook de Clasificación
* Probar asignaciones con puntajes 10, 8 y 5.
* **Aserción:** 10 produce `Promoter`, 8 produce `Passive`, 5 produce `Detractor` y dispara la creación de la entidad `Task` con prioridad `Urgent`.

### 5.2. Prueba de Consulta y Desempeño del Tablero Operativo
* Cargar itinerarios con rangos temporales pasados, vigentes y futuros.
* Invocar el filtro `currentInDestination`.
* **Aserción:** La consulta excluye viajes pasados y futuros, retornando únicamente registros vigentes en un tiempo menor a 200 ms (Umbral de Doherty).

### 5.3. Prueba de Integración del Job de Retorno
* Ejecutar el Scheduled Job manualmente sobre base de pruebas con un itinerario completado ayer.
* **Aserción:** Webhook emitido a Activepieces con código de respuesta 200 OK y payload con teléfono y nombres válidos.
