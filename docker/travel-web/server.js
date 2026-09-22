const express = require('express');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 8080;

// Servir archivos estáticos (CSS, JS, iconos)
app.use(express.static(path.join(__dirname, 'src', 'public')));

// Health check para orquestación en Docker
app.get('/health', (req, res) => {
  res.status(200).json({ status: 'ok', service: 'travel-web' });
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
    return res.status(404).send('Expediente no encontrado o token inválido.');
  }

  const templatePath = path.join(__dirname, 'src', 'views', 'ItineraryView.html');
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

const server = app.listen(PORT, () => {
  console.log(`[travel-web] Servidor web del viajero activo en http://localhost:${PORT}`);
});

module.exports = { app, server };
