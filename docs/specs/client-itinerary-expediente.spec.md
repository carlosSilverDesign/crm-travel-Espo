# Spec 005: Expediente Digital, Vouchers e Itinerarios para Cliente (Puppeteer Headless + Web Responsive)

## 1. Visión General
Especificación funcional y técnica para la generación, consulta y distribución del expediente digital de viaje. El sistema transforma la información comercial y operativa almacenada en EspoCRM (`Opportunity`, `Itinerario`, `ItemItinerario`, `Pasajero`) en dos artefactos de consumo directo:
1. **Micrositio Web Interactivo del Viajero:** Consulta responsive accesible mediante token seguro UUIDv4 de solo lectura (sin requerir login).
2. **Generador PDF Headless (Puppeteer):** Microservicio desacoplado en Node.js que renderiza la vista web en un documento PDF de alta fidelidad para uso offline.

El diseño sigue estrictamente las leyes de UX (Miller, Postel, Aesthetic-Usability, Doherty Threshold) y las 10 heurísticas de Nielsen Norman Group.

---

## 2. Historias de Usuario y Criterios de Aceptación

### HU-01: Generación y Consulta de Micrositio por Token Seguro (Heurística 10 & 6)
Como viajero, quiero acceder a mi itinerario completo desde mi teléfono mediante un enlace único sin necesidad de crear contraseñas o iniciar sesión, para consultar mis vuelos, hoteles y tours de forma inmediata.

* **Given** una `Opportunity` en etapa comercial confirmada (`Proposal`, `Negotiation` o `Closed Won`).
* **When** se crea o actualiza el registro de `Itinerario`.
* **Then** el backend genera un token criptográfico `publicAccessToken` (UUIDv4) y lo asocia al itinerario.
* **And** expone la URL pública: `https://viaje.agencia.com/p/{publicAccessToken}`.
* **And** el acceso mediante este token es de solo lectura estricta, devolviendo únicamente la información pública del itinerario y pasajeros asociados (sin exponer comisiones, márgenes ni notas internas).

### HU-02: Renderizado Visual Diarizado (Ley de Miller & Chunking Cognitivo)
Como viajero en destino, quiero ver mis actividades organizadas día por día en bloques visuales claros, para no saturarme de texto y ubicar rápidamente mis horarios de recogida y números de reserva.

* **Given** la vista del itinerario (web o PDF).
* **When** se cargan los servicios asociados.
* **Then** la interfaz agrupa los elementos cronológicamente por día (`Día 01: Llegada y Traslado`, `Día 02: Tour Arqueológico`).
* **And** cada tarjeta de servicio desglosa:
  * Horario de inicio / fin.
  * Tipo de servicio (Vuelo, Hotel, Tour, Traslado) con iconografía accesible acompañada de etiquetas de texto.
  * Código de confirmación / voucher del proveedor.
  * Indicaciones clave (punto de encuentro, política de equipaje, teléfono de emergencia local).

### HU-03: Generación Asíncrona de PDF Headless (Puppeteer & Doherty Threshold)
Como asesor comercial, quiero generar el voucher/itinerario en PDF con un solo clic desde EspoCRM y obtenerlo de forma casi instantánea, sin que el servidor CRM se congele durante el proceso.

* **Given** la ficha de un `Itinerario` en EspoCRM.
* **When** el asesor presiona la acción `"Descargar Expediente PDF"`.
* **Then** EspoCRM solicita al microservicio Node.js (`POST http://pdf-service:3000/generate-pdf`) pasando la URL interna del expediente o su payload JSON.
* **And** el microservicio abre una instancia headless de Chromium con Puppeteer, compila la vista usando `@media print`, inyecta encabezados/pies de página con numeración y genera el buffer PDF.
* **And** EspoCRM retorna el archivo al usuario en un tiempo inferior a 2.5 segundos, almacenando una copia en caché temporal para descargas sucesivas inmediatas (<400 ms).

### HU-04: Degradación Elegante y Acceso Offline (Ley de Postel & Heurística 9)
Como viajero en un aeropuerto con mala conexión, quiero poder descargar el PDF o almacenar la versión web en caché para consultarla sin datos móviles.

* **Given** una conexión móvil inestable en destino.
* **When** el cliente accede al micrositio.
* **Then** el micrositio cuenta con Service Worker / PWA caching básico que permite ver la última versión consultada sin pantalla en blanco.
* **And** la interfaz muestra de forma prominente un botón flotante: `"Descargar Copia Offline (PDF)"`.
* **And** si el microservicio de PDF llegase a estar temporalmente fuera de servicio, la web muestra un mensaje claro sugiriendo usar la vista web o captura sin bloquear el acceso al contenido.

---

## 3. Modelo de Datos y Contratos de Integración

### 3.1. Extensión de Entidad `Itinerario` en EspoCRM
Campos adicionales en `custom/Espo/Custom/Resources/metadata/entityDefs/Itinerario.json`:
* `publicAccessToken`: varchar(64), index: true, unique: true, readOnly: true (UUIDv4).
* `webAccessCount`: int, default: 0 (métrica de visualizaciones del cliente).
* `lastWebAccessAt`: datetime, readOnly: true.
* `pdfFileId`: foreign link a `Attachment` (para caché de última versión generada).

### 3.2. Contrato API del Microservicio Puppeteer (`pdf-service`)
* **Endpoint:** `POST /api/v1/generate`
* **Autenticación:** Cabecera `Authorization: Bearer <PDF_SERVICE_SECRET>`
* **Request Body:**
  ```json
  {
    "url": "http://travel-web:8080/p/f47ac10b-58cc-4372-a567-0e02b2c3d479?print=true",
    "pdfOptions": {
      "format": "A4",
      "printBackground": true,
      "margin": {
        "top": "15mm",
        "bottom": "15mm",
        "left": "12mm",
        "right": "12mm"
      }
    }
  }
  ```
* **Response:** Stream binario `application/pdf` con cabecera `Content-Disposition: attachment; filename="Expediente-Viaje.pdf"`.

---

## 4. Topología de Red en Docker Compose

```text
             [Internet / Nginx Reverse Proxy]
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
      [espocrm:80]              [travel-web:8080] (Micrositio SSR/SPA)
      (Gestión Interna)         (Expediente Público del Viajero)
             │                           │
             │ (POST /generate)          │ (GET /p/:token)
             ▼                           │
      [pdf-service:3000] ◄───────────────┘
      (Node.js + Puppeteer)
```

---

## 5. Principios de Interacción y Psicología Aplicada

* **Ley de Miller (Chunking):** Separación tajante entre "Resumen General del Viaje" (fechas, pax, hoteles base), "Cronograma Diario" y "Políticas / Vouchers".
* **Aesthetic-Usability Effect:** Tipografía legible (inter/sans-serif), jerarquía de contrastes WCAG AA, uso de tarjetas limpias con sombras suaves que transmiten solidez técnica y seguridad en la compra.
* **Fitts's Law:** Botones de descarga y contacto rápido de asistencia (WhatsApp de guardia de la agencia) en zonas accesibles tanto en mobile (barra inferior fija) como en desktop.
* **Heurística 6 (Reconocimiento antes que Recuerdo):** Los teléfonos de emergencia, direcciones de hoteles y códigos PNR de aerolíneas se muestran en texto destacado seleccionable y copiable en un clic.