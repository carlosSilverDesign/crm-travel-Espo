/**
 * Suite de Pruebas Unitarias y Funcionales para el Microservicio Headless pdf-service
 *
 * Módulo 05: Expediente Digital, Vouchers e Itinerarios para Cliente
 * Metodología: Spec-Driven Development (SDD) — TASK-035
 * Principios: Ley de Postel (Robustez), Heurística 5 (Seguridad) & Umbral de Doherty
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');

// Configuración determinista de entorno para pruebas unitarias
process.env.PORT = '3099';
process.env.PDF_SERVICE_SECRET = 'test_secret_qa_2026';

const { app, server, getBrowser } = require('../server');

/**
 * Servidor HTTP sintético local para proveer una página HTML real sin dependencias externas
 */
let staticServer = null;
const STATIC_PORT = 3098;

function startStaticServer() {
  return new Promise((resolve) => {
    staticServer = http.createServer((req, res) => {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(`
        <!DOCTYPE html>
        <html lang="es">
          <head>
            <meta charset="UTF-8">
            <title>Expediente de Viaje Sintético</title>
            <style>
              body { font-family: -apple-system, sans-serif; padding: 30px; color: #0f172a; }
              h1 { color: #0f2b48; font-size: 24px; }
              .card { border: 1px solid #cbd5e1; padding: 15px; margin-bottom: 15px; border-radius: 8px; }
              .badge { background: #eff6ff; color: #1d4ed8; padding: 4px 8px; border-radius: 4px; font-weight: bold; }
            </style>
          </head>
          <body>
            <h1>Cusco Mágico & Machu Picchu - Expediente de Prueba</h1>
            <p>Titular: Carlos Aventura • PNR: CUZ-94821X • Fechas: 15 Oct - 18 Oct 2026</p>
            <div class="card">
              <span class="badge">Vuelo Comercial</span>
              <h2>Día 01: Llegada y Traslado a Cusco</h2>
              <p>Vuelo directo LATAM LA2045 con equipaje de bodega incluido. Traslado privado al hotel.</p>
            </div>
            <div class="card">
              <span class="badge">Tour Guiado</span>
              <h2>Día 02: Valle Sagrado & Fortaleza de Ollantaytambo</h2>
              <p>Excursión de día completo con guía oficial bilingüe y almuerzo buffet andino.</p>
            </div>
            <div class="card">
              <span class="badge">Visita Arqueológica</span>
              <h2>Día 03: Santuario Histórico de Machu Picchu</h2>
              <p>Tren panorámico Vistadome y ascenso en bus ecológico a la ciudadela inca.</p>
            </div>
          </body>
        </html>
      `);
    });

    staticServer.listen(STATIC_PORT, '127.0.0.1', () => {
      resolve();
    });
  });
}

function makeRequest(options, body) {
  return new Promise((resolve, reject) => {
    const req = http.request(options, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const data = Buffer.concat(chunks);
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body: data
        });
      });
    });

    req.on('error', reject);

    if (body) {
      req.write(typeof body === 'string' ? body : JSON.stringify(body));
    }
    req.end();
  });
}

// Inicialización de servidor estático auxiliar
test.before(async () => {
  await startStaticServer();
});

test.after(async () => {
  if (staticServer) {
    staticServer.close();
  }
  if (server) {
    server.close();
  }
  try {
    const browser = await getBrowser();
    if (browser) {
      await browser.close();
    }
  } catch (err) {
    // Ignorar si el browser ya estaba cerrado
  }
});

// =============================================================================
// CASO DE PRUEBA 0: Verificación de Salud del Microservicio
// =============================================================================
test('PDF Service - Health Check endpoint retorna status OK', async () => {
  const res = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/health',
    method: 'GET'
  });

  assert.equal(res.statusCode, 200);
  const json = JSON.parse(res.body.toString('utf8'));
  assert.equal(json.status, 'ok');
  assert.equal(json.service, 'pdf-service');
});

// =============================================================================
// CASO DE PRUEBA 1: Seguridad, Rechazo y Validación de Secreto (Heurística 5)
// =============================================================================
test('Caso 1.1: Retorna 401 Unauthorized ante petición sin secreto', async () => {
  const res = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/api/v1/generate',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    }
  }, { url: `http://127.0.0.1:${STATIC_PORT}/test` });

  assert.equal(res.statusCode, 401);
  const json = JSON.parse(res.body.toString('utf8'));
  assert.equal(json.error, 'Unauthorized');
});

test('Caso 1.2: Retorna 401 Unauthorized ante secreto incorrecto', async () => {
  const res = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/api/v1/generate',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PDF-Service-Secret': 'secreto_invalido_hacker'
    }
  }, { url: `http://127.0.0.1:${STATIC_PORT}/test` });

  assert.equal(res.statusCode, 401);
  const json = JSON.parse(res.body.toString('utf8'));
  assert.equal(json.error, 'Unauthorized');
});

test('Caso 1.3: Retorna 400 Bad Request ante payload sin URL o formato inválido', async () => {
  const resMissingUrl = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/api/v1/generate',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PDF-Service-Secret': 'test_secret_qa_2026'
    }
  }, {});

  assert.equal(resMissingUrl.statusCode, 400);

  const resInvalidUrl = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/api/v1/generate',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PDF-Service-Secret': 'test_secret_qa_2026'
    }
  }, { url: 'cadena-no-url' });

  assert.equal(resInvalidUrl.statusCode, 400);
});

// =============================================================================
// CASO DE PRUEBA 2: Renderizado Exitoso y Stream Binario PDF (Ley de Postel)
// =============================================================================
test('Caso 2: Renderizado Exitoso genera stream application/pdf real superior a 10 KB', async () => {
  const res = await makeRequest({
    hostname: '127.0.0.1',
    port: 3099,
    path: '/api/v1/generate',
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-PDF-Service-Secret': 'test_secret_qa_2026'
    }
  }, {
    url: `http://127.0.0.1:${STATIC_PORT}/test`,
    secret: 'test_secret_qa_2026'
  });

  // 1. Status 200 OK
  assert.equal(res.statusCode, 200, 'El status debe ser 200 OK');

  // 2. Cabecera Content-Type correcta
  assert.equal(
    res.headers['content-type'],
    'application/pdf',
    'El encabezado Content-Type debe ser application/pdf'
  );

  // 3. Firma mágica de archivo PDF (%PDF-)
  const magicBytes = res.body.subarray(0, 4).toString('utf8');
  assert.equal(magicBytes, '%PDF', 'El archivo generado debe comenzar con la firma binaria %PDF');

  // 4. Umbral de tamaño superior a 10 KB (10,240 bytes)
  const sizeBytes = res.body.length;
  assert.ok(
    sizeBytes > 10240,
    `El buffer del PDF (${sizeBytes} bytes) debe ser mayor a 10 KB (10240 bytes)`
  );
});
