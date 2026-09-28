const express = require('express');
const path = require('path');
const fs = require('fs');
const http = require('http');

const app = express();
const PORT = process.env.PORT || 8080;
const ESPOCRM_URL = process.env.ESPOCRM_INTERNAL_URL || 'http://espocrm:80';

// Servir archivos estáticos (CSS, JS, iconos)
app.use(express.static(path.join(__dirname, 'src', 'public')));

// Health check para orquestación en Docker
app.get('/health', (req, res) => {
  res.status(200).json({ status: 'ok', service: 'travel-web' });
});

/**
 * Helper para renderizar vista 404 amigable
 */
function render404(res, message = 'Expediente no encontrado o token inválido.') {
  const notFoundPath = path.join(__dirname, 'src', 'views', 'NotFoundView.html');
  if (fs.existsSync(notFoundPath)) {
    let html = fs.readFileSync(notFoundPath, 'utf8');
    if (message) {
      html = html.replace(/El enlace o identificador de viaje proporcionado no existe, expiró o no cuenta con servicios registrados\./, message);
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(404).send(html);
  }
  return res.status(404).send(message);
}

/**
 * Proxy interno hacia EspoCRM API para evitar bloqueos CORS y resolver
 * la comunicación interna en la red Docker travel_network.
 */
app.use('/api-proxy', (req, res) => {
  const targetUrl = new URL(req.url, `${ESPOCRM_URL}/api/v1/`);
  
  const options = {
    hostname: targetUrl.hostname,
    port: targetUrl.port || 80,
    path: targetUrl.pathname + targetUrl.search,
    method: req.method,
    headers: {
      ...req.headers,
      host: targetUrl.host,
    }
  };

  const proxyReq = http.request(options, (proxyRes) => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers);
    proxyRes.pipe(res, { end: true });
  });

  proxyReq.on('error', (err) => {
    console.error('[travel-web] Error en proxy hacia EspoCRM:', err.message);
    res.status(502).json({ error: 'Fallo al conectar con el backend de EspoCRM', details: err.message });
  });

  req.pipe(proxyReq, { end: true });
});

/**
 * Endpoint del Checkout Público de Pago por Transferencia Bancaria
 * Accesible en /p/:token/pay/:paymentId (Módulo 06 / TASK-040)
 */
app.get('/p/:token/pay/:paymentId', (req, res) => {
  const { token, paymentId } = req.params;

  const templatePath = path.join(__dirname, 'src', 'views', 'PaymentCheckoutView.html');
  if (!fs.existsSync(templatePath)) {
    return render404(res, 'La pasarela de pago solicitada no está disponible o el enlace ha caducado.');
  }

  let html = fs.readFileSync(templatePath, 'utf8');
  html = html.replace(/{{TOKEN}}/g, token).replace(/{{PAYMENT_ID}}/g, paymentId);

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
  return res.send(html);
});

/**
 * Endpoint del Expediente Público por Token Seguro UUIDv4
 * Accesible por el viajero y por el microservicio de PDF con flag ?print=true
 */
app.get('/p/:token', (req, res) => {
  const { token } = req.params;
  const isPrint = req.query.print === 'true';

  // Validación de formato UUIDv4 defensiva (Heurística 5)
  const uuidV4Regex = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  if (token !== 'demo' && !uuidV4Regex.test(token)) {
    return render404(res, 'El código del expediente no tiene un formato válido o ha expirado.');
  }

  const templatePath = path.join(__dirname, 'src', 'views', 'ItineraryView.html');
  if (!fs.existsSync(templatePath)) {
    return render404(res, 'La vista del itinerario no se encuentra disponible.');
  }

  let html = fs.readFileSync(templatePath, 'utf8');

  // Si se solicita en modo impresión directa (Puppeteer Headless)
  if (isPrint) {
    html = html.replace('<body>', '<body class="print-mode">');
  }

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'public, max-age=60'); // Caché ligera para el viajero
  return res.send(html);
});

// Ruta raíz de fallback a demo
app.get('/', (req, res) => {
  res.redirect('/p/demo');
});

// Middleware de captura de cualquier otra ruta 404
app.use((req, res) => {
  return render404(res, 'La página o sección a la que intenta acceder no existe.');
});

const server = app.listen(PORT, () => {
  console.log(`[travel-web] Servidor web del viajero activo en http://localhost:${PORT}`);
});

module.exports = { app, server };
