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
 * Función para consultar datos del itinerario en EspoCRM
 */
function fetchItineraryData(token) {
  return new Promise((resolve) => {
    const targetUrl = new URL(`${ESPOCRM_URL}/api/v1/Itinerario/public/${encodeURIComponent(token)}`);
    const req = http.get({
      hostname: targetUrl.hostname,
      port: targetUrl.port || 80,
      path: targetUrl.pathname,
      timeout: 3000
    }, (res) => {
      if (res.statusCode !== 200) {
        return resolve(null);
      }
      let body = '';
      res.setEncoding('utf8');
      res.on('data', chunk => { body += chunk; });
      res.on('end', () => {
        try {
          resolve(JSON.parse(body));
        } catch (e) {
          resolve(null);
        }
      });
    });

    req.on('error', () => { resolve(null); });
    req.on('timeout', () => { req.destroy(); resolve(null); });
  });
}

/**
 * Genera el HTML dinámico para un servicio individual
 */
function renderServiceBlock(service) {
  const type = service.serviceType || 'Otro';
  let badgeClass = 'badge-tour';
  let badgeLabel = type;
  let iconSvg = '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>';

  if (type === 'Vuelo') {
    badgeClass = 'badge-flight';
    badgeLabel = 'Vuelo Comercial';
    iconSvg = '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>';
  } else if (type === 'Hotel') {
    badgeClass = 'badge-hotel';
    badgeLabel = 'Alojamiento';
    iconSvg = '<path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>';
  } else if (type === 'Traslado') {
    badgeClass = 'badge-transfer';
    badgeLabel = 'Traslado';
    iconSvg = '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>';
  } else if (type === 'Tour') {
    badgeClass = 'badge-tour';
    badgeLabel = 'Excursión / Tour';
    iconSvg = '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>';
  } else if (type === 'Seguro') {
    badgeClass = 'badge-tour';
    badgeLabel = 'Asistencia en Viaje';
    iconSvg = '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>';
  }

  let timeText = 'Horario programado';
  if (service.serviceDate) {
    const d = new Date(service.serviceDate);
    if (!isNaN(d.getTime())) {
      timeText = d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
    }
  }

  const pnrCode = service.confirmationCode || 'CONFIRMADO';
  const supplier = service.supplierName || 'Operador Oficial';
  const phone = service.emergencyPhone;

  let phoneHtml = '';
  if (phone) {
    phoneHtml = `
      <div class="meta-row">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        <div><span class="meta-label">Coordinación Local:</span> <a href="tel:${encodeURIComponent(phone)}" class="emergency-phone-link">${phone}</a></div>
      </div>`;
  }

  let routeOrDetails = '';
  if (service.origin && service.destination) {
    const airlineInfo = service.carrier ? ` (${service.carrier}${service.flightNumber ? ' ' + service.flightNumber : ''}${service.cabin ? ' • Cabina ' + service.cabin : ''})` : '';
    routeOrDetails = `<p style="margin:4px 0 8px 0; font-size:13px; font-weight:700; color:#0369a1;"><i class="fas fa-arrow-right"></i> Tramo: ${service.origin} ➔ ${service.destination}${airlineInfo}</p>`;
  }

  return `
    <section class="service-item-block" aria-label="Servicio de ${type}">
      <div class="service-header">
        <div class="service-time-block">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          <time>${timeText}</time>
        </div>
        <span class="service-badge ${badgeClass}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            ${iconSvg}
          </svg>
          <span>${badgeLabel}</span>
        </span>
      </div>

      <div class="service-content">
        <h3 class="service-name">${service.name}</h3>
        ${routeOrDetails}
        <p class="service-description">${service.notes || 'Servicio emitido y confirmado conforme al expediente de viaje.'}</p>
      </div>

      <div class="service-meta-grid">
        <div class="meta-row">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/></svg>
          <div><span class="meta-label">Localizador / Ticket:</span> <span class="meta-code">${pnrCode}</span></div>
        </div>
        <div class="meta-row">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          <div><span class="meta-label">Proveedor:</span> ${supplier}</div>
        </div>
        ${phoneHtml}
      </div>
    </section>`;
}

/**
 * Endpoint del Expediente Público por Token Seguro UUIDv4
 * Accesible por el viajero y por el microservicio de PDF con flag ?print=true
 */
app.get('/p/:token', async (req, res) => {
  const { token } = req.params;
  const isPrint = req.query.print === 'true';

  // Validación de formato UUIDv4 defensiva
  const uuidV4Regex = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  if (token !== 'demo' && !uuidV4Regex.test(token)) {
    return render404(res, 'El código del expediente no tiene un formato válido o ha expirado.');
  }

  const templatePath = path.join(__dirname, 'src', 'views', 'ItineraryView.html');
  if (!fs.existsSync(templatePath)) {
    return render404(res, 'La vista del itinerario no se encuentra disponible.');
  }

  let html = fs.readFileSync(templatePath, 'utf8');

  try {
    const data = await fetchItineraryData(token);
    if (data && data.name) {
      // 1. Sustituir metadatos principales del Hero
      const destinationText = data.origin ? `${data.origin} ➔ ${data.destination}` : (data.destination || 'Por definir');
      const datesText = (data.startDate && data.endDate) ? `${data.startDate} al ${data.endDate}` : (data.startDate || 'Fechas por confirmar');
      const passengerText = `${data.leadPassenger || 'Viajero'} (${data.passengersCount || 1} Pasajeros)`;
      const statusLabel = data.status || 'Confirmado';
      const pnrCode = data.generalPnr || ('PNR-' + (data.id ? data.id.substring(0, 6).toUpperCase() : 'CONFIRMADO'));

      html = html.replace(/<title>.*?<\/title>/, `<title>Expediente de Viaje: ${data.name} | Destinos Agencia</title>`);
      html = html.replace(/id="heroTitle">.*?<\/h1>/, `id="heroTitle">${data.name}</h1>`);
      html = html.replace(/id="heroPnr">.*?<\/strong>/, `id="heroPnr">${pnrCode}</strong>`);
      html = html.replace(/id="passengerName">.*?<\/strong>/, `id="passengerName">${passengerText}</strong>`);
      html = html.replace(/id="destinationValue">.*?<\/span>/, `id="destinationValue">${destinationText}</span>`);
      html = html.replace(/id="datesValue">.*?<\/span>/, `id="datesValue">${datesText}</span>`);

      // 2. Sustituir botón flotante de WhatsApp con datos reales
      const waText = encodeURIComponent(`Hola, necesito asistencia con mi itinerario ${data.name} (${pnrCode})`);
      html = html.replace(/https:\/\/wa\.me\/[0-9]+\?text=.*?"/, `https://wa.me/51987654321?text=${waText}"`);

      // 3. Generar sección dinámica del Timeline
      let timelineHtml = '';

      if (Array.isArray(data.services) && data.services.length > 0) {
        // Agrupar servicios por fecha
        const dayGroups = {};
        data.services.forEach((s) => {
          let dayKey = 'General';
          if (s.serviceDate) {
            dayKey = s.serviceDate.split(' ')[0] || s.serviceDate.split('T')[0];
          }
          if (!dayGroups[dayKey]) dayGroups[dayKey] = [];
          dayGroups[dayKey].push(s);
        });

        timelineHtml = '<div class="timeline-stream" id="timelineStream" role="feed" aria-label="Días del Itinerario">';
        let dayIndex = 1;

        for (const [dayKey, services] of Object.entries(dayGroups)) {
          const dayNum = String(dayIndex).padStart(2, '0');
          const dayDateLabel = dayKey !== 'General' ? dayKey : 'Servicios Programados';
          const dayTitle = services[0].name || `Actividades del Día ${dayIndex}`;

          timelineHtml += `
            <article class="itinerary-day-card">
              <header class="day-header">
                <div class="day-badge-wrap">
                  <span class="day-number-badge">DÍA ${dayNum}</span>
                  <h2 class="day-title-text">${dayTitle}</h2>
                </div>
                <div class="day-date-text">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <span>${dayDateLabel}</span>
                </div>
              </header>
              <div class="services-list">
                ${services.map(s => renderServiceBlock(s)).join('')}
              </div>
            </article>`;
          dayIndex++;
        }
        timelineHtml += '</div>';
      } else {
        // Estado limpio cuando aún no hay servicios individuales cargados (p. ej. en cotización inicial)
        timelineHtml = `
          <div class="timeline-stream" id="timelineStream" role="feed" aria-label="Días del Itinerario">
            <article class="itinerary-day-card" style="text-align: center; padding: 40px 24px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
              <div style="max-width: 520px; margin: 0 auto;">
                <div style="width: 56px; height: 56px; background: #e0f2fe; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto;">
                  <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="4" rx="2" ry="2"/>
                    <line x1="16" x2="16" y1="2" y2="6"/>
                    <line x1="8" x2="8" y1="2" y2="6"/>
                    <line x1="3" x2="21" y1="10" y2="10"/>
                  </svg>
                </div>
                <h2 style="font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px;">Servicios en Proceso de Programación</h2>
                <p style="font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 12px;">
                  Este expediente para <strong>${destinationText}</strong> se encuentra en estado <strong>${statusLabel}</strong>.
                  Los tramos aéreos, vouchers de hotel y actividades confirmadas se actualizarán automáticamente aquí en tiempo real.
                </p>
                <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: #0284c7; background: #f0f9ff; padding: 8px 14px; border-radius: 20px; font-weight: 600;">
                  <i class="fas fa-info-circle"></i> Consulte con su asesor de viajes para la emisión de vouchers
                </div>
              </div>
            </article>
          </div>`;
      }

      // Sustituir la sección timeline usando los marcadores exactos
      const timelinePattern = /<!-- TIMELINE_STREAM_START -->[\s\S]*?<!-- TIMELINE_STREAM_END -->/;
      if (timelinePattern.test(html)) {
        html = html.replace(timelinePattern, `<!-- TIMELINE_STREAM_START -->\n${timelineHtml}\n<!-- TIMELINE_STREAM_END -->`);
      } else {
        // Fallback por clase
        html = html.replace(/<div class="timeline-stream"[\s\S]*?<\/div>\s*(?=<footer)/, `${timelineHtml}\n`);
      }
    }
  } catch (err) {
    console.warn('[travel-web] Fallback a vista base por error de hidratación:', err.message);
  }

  // Si se solicita en modo impresión directa (Puppeteer Headless)
  if (isPrint) {
    html = html.replace('<body>', '<body class="print-mode">');
  }

  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
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
