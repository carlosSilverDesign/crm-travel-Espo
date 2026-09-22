# Plan Técnico 005: Expediente Digital, Vouchers e Itinerarios para Cliente (Puppeteer Headless + Web Responsive)

* **Especificación de Referencia:** `/docs/specs/client-itinerary-expediente.spec.md`
* **Módulo:** 05 — Expediente Digital, Vouchers e Itinerarios para Cliente
* **Metodología:** Spec-Driven Development (SDD)
* **Stack Tecnológico:** Node.js (Express + Puppeteer Core + Chromium Alpine), Docker Compose, PHP 8.2+ (EspoCRM Client API Extension), Nginx Reverse Proxy, Redis (Caché de buffers PDF)
* **Regla de Oro de Aislamiento:** Todo desarrollo en EspoCRM debe quedar confinado en `custom/Espo/Custom/`. El core del CRM no se modifica. El microservicio de renderizado PDF reside en un contenedor independiente (`pdf-service`) dentro de la red privada de Docker.

---

## 1. Arquitectura del Sistema y Flujo de Datos

```text
[Viajero / Mobile Browser]                    [Asesor de Ventas en EspoCRM]
           │                                               │
           │ (GET /p/:publicAccessToken)                   │ (Click "Generar Expediente PDF")
           ▼                                               ▼
[Nginx Reverse Proxy]                     [EspoCRM Backend: ItinerarioService]
           │                                               │
           ├──► [travel-web:8080]                          │ 1. Verifica token / caché
           │    (Micrositio SSR/HTML)                      │ 2. POST /api/v1/generate
           │           │                                   │    (URL interna + Token HMAC)
           │           │                                   ▼
           │           └──────────────────────────► [pdf-service:3000]
           │                                        (Node.js + Puppeteer)
           │                                               │
           │                                               │ Puppeteer abre travel-web
           │                                               │ con flag ?print=true
           │                                               ▼
           │                                        [Buffer Binario PDF]
           │                                               │
           ◄───────────────────────────────────────────────┴ Retorna stream PDF
```

### Principios de Ingeniería y Experiencia de Usuario (Leyes de UX & Heurísticas)
* **Ley de Miller & Chunking Cognitivo:** El motor de plantillas estructura la información agrupada por jornadas (`Día 01`, `Día 02`), evitando saturación mental y facilitando la búsqueda rápida de datos críticos en destino.
* **Aesthetic-Usability Effect:** El diseño responsive y el PDF comparten una identidad visual armónica, jerarquía tipográfica limpia y contrastes WCAG AA, aumentando la confianza del usuario ante contingencias de viaje.
* **Umbral de Doherty (<400 ms):** Las solicitudes de consulta del expediente web responden por debajo de 200 ms. Para el PDF, tras la primera generación, el buffer se almacena en caché en el sistema de archivos/Redis, garantizando descargas instantáneas en solicitudes subsecuentes.
* **Ley de Postel (Principio de Robustez):** Si el microservicio Node.js experimenta sobrecarga o caída temporal, el CRM no se bloquea; arroja una excepción controlada (`HTTP 503`) con un mensaje en lenguaje natural (*Heurística 9*) sugiriendo al cliente utilizar la versión web interactiva.
* **Heurística 5: Prevención de Errores (NN/g):** El endpoint público de consulta valida criptographically el `publicAccessToken` mediante UUIDv4. Cualquier intento de inyección o parámetro erróneo devuelve `HTTP 404/403` inmediato sin exponer trazas del servidor.

---

## 2. Microservicio Headless: Node.js + Puppeteer (`pdf-service`)

### 2.1. Configuración de Contenedor (`docker/pdf-service/Dockerfile`)
Se utiliza una imagen base ligera con dependencias de Chromium preinstaladas para mitigar problemas de fuentes y memoria compartida:

```dockerfile
FROM node:20-alpine

# Instalación de Chromium y dependencias nativas del sistema
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    font-noto-emoji

ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

WORKDIR /usr/src/app

COPY package*.json ./
RUN npm ci --only=production

COPY . .

EXPOSE 3000
USER node

CMD ["node", "server.js"]
```

### 2.2. Implementación del Servidor de Renderizado (`server.js`)
* **Gestión de Recursos:** Reutilización de una instancia única de browser con apertura de `browser.newPage()` por petición para evitar fugas de memoria (OOM).
* **Parámetros de Impresión:** Soporte para emulación de medios (`page.emulateMediaType('print')`), márgenes consistentes y cabeceras dinámicas.

```javascript
const express = require('express');
const puppeteer = require('puppeteer');
const app = express();

app.use(express.json());

let browserPromise = puppeteer.launch({
  headless: 'new',
  executablePath: process.env.PUPPETEER_EXECUTABLE_PATH,
  args: [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu'
  ]
});

app.post('/api/v1/generate', async (req, res) => {
  const { url, secret } = req.body;

  if (secret !== process.env.PDF_SERVICE_SECRET) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  let page;
  try {
    const browser = await browserPromise;
    page = await browser.newPage();

    // Timeout de 15 segundos para evitar bloqueos por assets lentos
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 15000 });
    await page.emulateMediaType('print');

    const pdfBuffer = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: {
        top: '15mm',
        bottom: '15mm',
        left: '12mm',
        right: '12mm'
      }
    });

    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', 'inline; filename="expediente.pdf"');
    return res.send(pdfBuffer);
  } catch (error) {
    console.error('Error generando PDF:', error);
    return res.status(500).json({ error: 'Fallo al procesar el documento PDF' });
  } finally {
    if (page) await page.close();
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`PDF Service listening on port ${PORT}`));
```

---

## 3. Extensión de EspoCRM (Módulo 05 Backend)

### 3.1. Esquema y Metadatos de la Entidad Itinerario
* **Archivo:** `custom/Espo/Custom/Resources/metadata/entityDefs/Itinerario.json`

```json
{
  "fields": {
    "publicAccessToken": {
      "type": "varchar",
      "maxLength": 64,
      "index": true,
      "unique": true,
      "readOnly": true
    },
    "webAccessCount": {
      "type": "int",
      "default": 0,
      "readOnly": true
    },
    "lastWebAccessAt": {
      "type": "datetime",
      "readOnly": true
    },
    "pdfCacheFileId": {
      "type": "varchar",
      "maxLength": 100,
      "readOnly": true
    }
  }
}
```

### 3.2. Hook del Ciclo de Vida: Generación de UUIDv4
* **Archivo:** `custom/Espo/Custom/Hooks/Itinerario/GeneratePublicToken.php`
* **Trigger:** `beforeSave`
* **Lógica:** Si el registro carece de `publicAccessToken`, genera un UUIDv4 criptográficamente seguro antes de persistir en MySQL.

```php
<?php
namespace Espo\Custom\Hooks\Itinerario;

use Espo\ORM\Entity;
use Ramsey\Uuid\Uuid;

class GeneratePublicToken
{
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if (!$entity->get('publicAccessToken')) {
            $entity->set('publicAccessToken', Uuid::uuid4()->toString());
        }
    }
}
```

### 3.3. Endpoint de Integración con Microservicio PDF
* **Archivo:** `custom/Espo/Custom/Controllers/ItinerarioPdfController.php`
* **Ruta:** `POST /Itinerario/action/generatePdf`
* **Lógica:**
  1. Extrae el `itinerarioId` y valida permisos de lectura del asesor comercial.
  2. Verifica si existe un buffer en caché no expirado; si existe, lo retorna en menos de 100 ms (Doherty Threshold).
  3. Si no existe, realiza la llamada HTTP interna hacia `http://pdf-service:3000/api/v1/generate` enviando la URL interna de renderizado (`http://travel-web:8080/p/{token}?print=true`).
  4. Recibe el stream binario, almacena la referencia en caché y retorna el archivo al cliente.

---

## 4. Frontend del Expediente Web (`travel-web`)

### 4.1. Maquetación Modular (Chunking & Jerarquía Visual)
* **Header:** Destino principal, nombre del titular de la reserva, fechas globales del viaje y código PNR general.
* **Componente de Cronograma Diario (`DayTimeline`):**
  * Separadores visuales por día con indicador de fecha.
  * Tarjetas de servicio diferenciadas por código cromático e iconografía con texto descriptivo (*Heurística 4: Consistencia y Estándares*).
  * Horarios de recogida (*Pick-up time*) destacados con tipografía agrandada (*Ley de Fitts para escaneo táctil*).
* **Bloque de Contactos Locales y Asistencia:** Teléfonos de guías locales, operadores receptivos y el número de WhatsApp oficial de la agencia persistido en la barra inferior en mobile.

### 4.2. Estilos para Medios Impresos (`@media print`)

```css
@media print {
  /* Ocultar elementos de navegación y acciones de pantalla */
  .no-print, .btn-download-pdf, .chat-floating-button {
    display: none !important;
  }

  /* Evitar quiebres de página accidentales en medio de un tour */
  .itinerary-day-card, .service-item-block {
    break-inside: avoid;
    page-break-inside: avoid;
  }

  /* Garantizar fidelidad tipográfica y de color */
  body {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    font-size: 11pt;
    color: #1a1a1a;
  }
}
```

---

## 5. Estrategia de Pruebas y Validación (Fases SDD 5 y 6)

### 5.1. Prueba Unitaria del Generador de Tokens
* Instanciar hook `GeneratePublicToken` con una entidad sin token.
* **Aserción:** Se asigna una cadena UUIDv4 válida (patrón regex `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).

### 5.2. Prueba de Aislamiento del Microservicio Puppeteer
* Enviar petición `POST` a `http://localhost:3000/api/v1/generate` con un HTML sintético de prueba.
* **Aserción:** Retorno con encabezado `application/pdf`, status `200 OK` y buffer no vacío de tamaño superior a 10 KB.

### 5.3. Prueba de Resiliencia ante Caída del Renderizador
* Apagar temporalmente el contenedor `pdf-service` (`docker compose stop pdf-service`).
* Solicitar generación desde EspoCRM.
* **Aserción:** El sistema captura el fallo de conexión (*Connection Refused*), no produce un Fatal Error en PHP, responde HTTP 503 estructurado y muestra una notificación amigable al asesor indicando la indisponibilidad del motor de impresión.

### 5.4. Prueba E2E de Consumo del Viajero
* Crear una oportunidad completa en EspoCRM con 3 días de itinerario y 4 servicios asociados.
* Abrir la URL `https://viaje.agencia.com/p/{token}` en navegador móvil emulado.
* **Aserción:** Renderizado completo de las tarjetas de servicio en menos de 400 ms, ausencia de datos sensibles internos (márgenes comerciales ocultos), y descarga del PDF idéntica a la vista en pantalla.