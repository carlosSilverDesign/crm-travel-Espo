const express = require('express');

// Soporte desacoplado para puppeteer-core o puppeteer estándar
const puppeteer = (() => {
  try {
    return require('puppeteer-core');
  } catch (e) {
    return require('puppeteer');
  }
})();

const app = express();
app.use(express.json({ limit: '5mb' }));

const PORT = process.env.PORT || 3000;
const PDF_SERVICE_SECRET = process.env.PDF_SERVICE_SECRET;

// Instancia compartida del navegador Chromium (Mitigación de latencia y Doherty Threshold)
let browserInstance = null;

async function getBrowser() {
  if (!browserInstance || !browserInstance.isConnected()) {
    browserInstance = await puppeteer.launch({
      headless: 'new',
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium-browser',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--no-first-run',
        '--no-zygote'
      ]
    });

    browserInstance.on('disconnected', () => {
      console.warn('[pdf-service] Conexión con Chromium cerrada. Se relanzará en la próxima solicitud.');
      browserInstance = null;
    });
  }
  return browserInstance;
}

/**
 * Health check para orquestación en Docker/Kubernetes
 */
app.get('/health', (req, res) => {
  res.status(200).json({
    status: 'ok',
    service: 'pdf-service',
    uptime: process.uptime(),
    browserConnected: Boolean(browserInstance && browserInstance.isConnected())
  });
});

/**
 * Endpoint principal de compilación y renderizado PDF
 * Heurística 5: Validación criptográfica del secreto y parámetros de entrada.
 * Heurística 9: Manejo de errores defensivo sin exponer trazas internas del servidor.
 * Ley de Postel: Retorno estricto del stream binario application/pdf.
 */
app.post('/api/v1/generate', async (req, res) => {
  // 1. Verificación de Seguridad y Secreto Compartido
  const providedSecret =
    req.headers['x-pdf-service-secret'] ||
    req.headers['x-pdf-secret'] ||
    (req.headers.authorization ? req.headers.authorization.replace(/^Bearer\s+/i, '') : null) ||
    req.body?.secret;

  if (!PDF_SERVICE_SECRET || providedSecret !== PDF_SERVICE_SECRET) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  // 2. Validación Defensiva de Parámetros de Entrada
  const { url } = req.body || {};
  if (!url || typeof url !== 'string' || !url.trim()) {
    return res.status(400).json({ error: 'URL requerida y debe ser una cadena válida' });
  }

  try {
    new URL(url);
  } catch {
    return res.status(400).json({ error: 'Formato de URL inválido' });
  }

  let page = null;
  try {
    const browser = await getBrowser();
    // Nueva pestaña por solicitud para garantizar aislamiento y prevenir fugas de memoria
    page = await browser.newPage();

    // Timeout defensivo de 15 segundos para evitar bloqueos por assets externos lentos
    await page.goto(url, {
      waitUntil: 'networkidle0',
      timeout: 15000
    });

    // Emular medio impreso para respetar estilos @media print del frontend
    await page.emulateMediaType('print');

    // Compilar documento con márgenes consistentes y formato estándar A4
    const rawPdf = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: {
        top: '15mm',
        bottom: '15mm',
        left: '12mm',
        right: '12mm'
      }
    });

    // En Puppeteer moderno page.pdf() retorna Uint8Array; convertir a Buffer de Node.js
    // para evitar que Express lo serialice erróneamente como objeto JSON {"0": 37, ...}
    const pdfBuffer = Buffer.isBuffer(rawPdf) ? rawPdf : Buffer.from(rawPdf);

    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', 'inline; filename="expediente.pdf"');
    res.setHeader('Content-Length', pdfBuffer.length);

    return res.status(200).end(pdfBuffer);
  } catch (error) {
    console.error('[pdf-service] Error durante la generación del PDF:', error.message);

    // Heurística 9: No filtrar stack traces ni rutas internas de Alpine/Node
    return res.status(500).json({ error: 'Fallo al procesar el documento PDF' });
  } finally {
    if (page) {
      try {
        await page.close();
      } catch (closeErr) {
        console.error('[pdf-service] Error cerrando la pestaña de Chromium:', closeErr.message);
      }
    }
  }
});

const server = app.listen(PORT, () => {
  console.log(`[pdf-service] Microservicio de renderizado PDF activo en puerto ${PORT}`);
});

// Cierre ordenado (Graceful Shutdown)
process.on('SIGTERM', async () => {
  console.log('[pdf-service] Señal SIGTERM recibida. Cerrando servidor y Chromium...');
  server.close(async () => {
    if (browserInstance) {
      await browserInstance.close();
    }
    process.exit(0);
  });
});

module.exports = { app, server, getBrowser };
