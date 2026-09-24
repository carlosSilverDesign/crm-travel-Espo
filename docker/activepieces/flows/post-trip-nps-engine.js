/**
 * =============================================================================
 * MÓDULO 07: OPERACIÓN EN DESTINO, INCIDENTES Y POST-VENTA
 * TASK-047: Flujo de Ingesta y Disparo en Activepieces (Post-Trip NPS Survey)
 * =============================================================================
 */

const http = require('http');
const https = require('https');
const { URL } = require('url');

/**
 * Cliente HTTP ligero y desacoplado con soporte para timeout y cabeceras.
 */
async function httpRequest(targetUrl, options = {}, body = null, timeoutMs = 8000) {
  return new Promise((resolve, reject) => {
    const urlObj = new URL(targetUrl);
    const isHttps = urlObj.protocol === 'https:';
    const lib = isHttps ? https : http;

    const reqOptions = {
      method: options.method || 'GET',
      headers: options.headers || {},
      timeout: timeoutMs,
    };

    let postData = null;
    if (body) {
      if (typeof body === 'object') {
        postData = JSON.stringify(body);
        reqOptions.headers['Content-Type'] = 'application/json';
        reqOptions.headers['Content-Length'] = Buffer.byteLength(postData);
      } else {
        postData = String(body);
        reqOptions.headers['Content-Length'] = Buffer.byteLength(postData);
      }
    }

    const req = lib.request(urlObj, reqOptions, (res) => {
      let data = '';
      res.on('data', (chunk) => {
        data += chunk;
      });
      res.on('end', () => {
        let parsed = data;
        const contentType = res.headers['content-type'] || '';
        if (contentType.includes('application/json')) {
          try {
            parsed = JSON.parse(data);
          } catch {
            // Mantener como string si no es JSON válido
          }
        }
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body: parsed,
          rawBody: data,
        });
      });
    });

    req.on('timeout', () => {
      req.destroy();
      reject(new Error(`Timeout de petición HTTP tras ${timeoutMs}ms en ${targetUrl}`));
    });

    req.on('error', (err) => {
      reject(err);
    });

    if (postData) {
      req.write(postData);
    }
    req.end();
  });
}

/**
 * Paso 3: Parser de Respuesta y Extracción de Score (Ley de Postel).
 *
 * Procesa respuestas heterogéneas de clientes en WhatsApp, tales como:
 * - "10!"
 * - "Me encantó, les doy un 9"
 * - "5 puntos"
 * - "10/10 todo espectacular"
 * - "8 de 10, pero el transfer se demoró"
 * - "3 - pésimo hotel sin agua caliente"
 *
 * @param {string} rawText Texto recibido del pasajero en WhatsApp
 * @param {object} context Contexto previo con datos del cliente y reserva
 * @returns {object} { hasValidScore, npsScore, comments, clientName, contactId, opportunityId }
 */
function parseNpsResponse(rawText, context = {}) {
  if (!rawText || typeof rawText !== 'string') {
    return {
      hasValidScore: false,
      npsScore: null,
      comments: '',
      clientName: context.clientName || 'Viajero',
      contactId: context.contactId || null,
      opportunityId: context.opportunityId || null,
      itineraryId: context.itineraryId || null,
      fallbackRequired: true,
    };
  }

  const trimmed = rawText.trim();
  let extractedScore = null;
  let remainingComments = trimmed;

  // 1. Patrón tipo "10/10" o "9 de 10" o "8 / 10"
  const fractionMatch = trimmed.match(/\b([1-9]|10)\s*(?:\/|\bde\b)\s*10\b/i);
  if (fractionMatch) {
    extractedScore = parseInt(fractionMatch[1], 10);
    remainingComments = trimmed.replace(fractionMatch[0], '').trim();
  }

  // 2. Patrón de calificación al inicio: "10!", "9.", "4 - comentarios", "8 todo bien"
  if (extractedScore === null) {
    const startMatch = trimmed.match(/^([1-9]|10)\b(?:\s*[\.\!\-\:]*\s*|\s+)/);
    if (startMatch) {
      extractedScore = parseInt(startMatch[1], 10);
      remainingComments = trimmed.substring(startMatch[0].length).trim();
    }
  }

  // 3. Patrón semántico: "les doy un 9", "mi nota es 8", "califico con 7", "un 10", "le pongo 5"
  if (extractedScore === null) {
    const semanticMatch = trimmed.match(/(?:les\s+)?(?:doy|pongo|califico|nota(?:\s+es)?|puntaje(?:\s+es)?|calificaci[oó]n(?:\s+es)?|un|una)\s*(?:de\s*)?(?:un\s*)?([1-9]|10)\b/i);
    if (semanticMatch) {
      extractedScore = parseInt(semanticMatch[1], 10);
      remainingComments = trimmed.replace(semanticMatch[0], '').trim();
    }
  }

  // 4. Patrón numérico con sufijo "puntos" / "estrellas": "5 puntos", "3 estrellas"
  if (extractedScore === null) {
    const pointsMatch = trimmed.match(/\b([1-9]|10)\s*(?:puntos?|estrellas?|pts?)\b/i);
    if (pointsMatch) {
      extractedScore = parseInt(pointsMatch[1], 10);
      remainingComments = trimmed.replace(pointsMatch[0], '').trim();
    }
  }

  // 5. Búsqueda simple de dígito aislado entre 1 y 10 si el mensaje es muy corto (ej: "10", "8", "4")
  if (extractedScore === null) {
    const isolatedMatch = trimmed.match(/\b([1-9]|10)\b/);
    if (isolatedMatch) {
      extractedScore = parseInt(isolatedMatch[1], 10);
      remainingComments = trimmed.replace(isolatedMatch[0], '').trim();
    }
  }

  // Limpiar palabras complementarias de sufijos o puntuaciones residuales al inicio
  remainingComments = remainingComments
    .replace(/^(?:estrellas?|puntos?|pts?)\s*[\,\.\-\:\!\?]*\s*/i, '')
    .replace(/^[\.\,\-\:\!\?]\s*/, '')
    .trim();

  // Si no había comentarios adicionales más allá de la nota
  if (!remainingComments) {
    remainingComments = `Calificación directa vía WhatsApp: ${extractedScore}`;
  }

  return {
    hasValidScore: extractedScore !== null && extractedScore >= 1 && extractedScore <= 10,
    npsScore: extractedScore,
    comments: remainingComments,
    clientName: context.clientName || 'Viajero',
    contactId: context.contactId || null,
    opportunityId: context.opportunityId || null,
    itineraryId: context.itineraryId || null,
    fallbackRequired: extractedScore === null,
  };
}

/**
 * Paso 2: Despacho de Plantilla WhatsApp vía Chatwoot / Meta WhatsApp Cloud API.
 */
async function sendWhatsAppSurveyTemplate(chatwootConfig, surveyData) {
  const { baseUrl, apiToken, accountId } = chatwootConfig;

  const contentMessage =
    `¡Hola ${surveyData.clientName}! Bienvenido de regreso de tu viaje a ${surveyData.destination}. ` +
    `En nuestra agencia queremos garantizar tu satisfacción total. Del 1 al 10, ¿qué tan probable es que nos recomiendes ` +
    `con amigos o familiares? Puedes responder con tu puntaje y cualquier comentario que nos ayude a mejorar.`;

  const payload = {
    content: contentMessage,
    message_type: 'outgoing',
    template_name: 'post_trip_nps_survey',
    phone: surveyData.phone,
    custom_attributes: {
      itineraryId: surveyData.itineraryId,
      contactId: surveyData.contactId,
    },
  };

  if (chatwootConfig.mockSender) {
    return chatwootConfig.mockSender(payload);
  }

  const endpoint = `${baseUrl}/api/v1/accounts/${accountId}/conversations/${surveyData.conversationId || 'new'}/messages`;
  const res = await httpRequest(endpoint, {
    method: 'POST',
    headers: {
      'api_access_token': apiToken,
    },
  }, payload);

  return res;
}

/**
 * Paso 4: Ingesta REST en EspoCRM (POST /api/v1/Feedback).
 */
async function ingestFeedbackToEspoCRM(espocrmConfig, feedbackData) {
  const { baseUrl, apiKey } = espocrmConfig;

  const payload = {
    name: `NPS-WA: ${feedbackData.clientName}`,
    contactId: feedbackData.contactId,
    opportunityId: feedbackData.opportunityId,
    npsScore: feedbackData.npsScore,
    comments: feedbackData.comments,
    channel: 'WhatsApp',
  };

  if (espocrmConfig.mockSender) {
    return espocrmConfig.mockSender(payload);
  }

  const endpoint = `${baseUrl.replace(/\/+$/, '')}/api/v1/Feedback`;
  const res = await httpRequest(endpoint, {
    method: 'POST',
    headers: {
      'X-Api-Key': apiKey,
    },
  }, payload);

  if (res.statusCode >= 400) {
    const errMsg = res.body && res.body.message ? res.body.message : res.rawBody;
    throw new Error(`Fallo de ingesta en EspoCRM (${res.statusCode}): ${errMsg}`);
  }

  return res.body;
}

/**
 * Orquestador Integral del Flujo E2E (Pipeline de 4 Pasos).
 */
async function runPostTripPipeline(webhookPayload, customerResponseText, options = {}) {
  const startTime = Date.now();

  const chatwootConfig = options.chatwootConfig || {
    baseUrl: process.env.CHATWOOT_BASE_URL || 'http://localhost:3000',
    apiToken: process.env.CHATWOOT_API_TOKEN || 'test_token',
    accountId: process.env.CHATWOOT_ACCOUNT_ID || '1',
    mockSender: options.chatwootMockSender,
  };

  const espocrmConfig = options.espocrmConfig || {
    baseUrl: process.env.ESPOCRM_BASE_URL || 'http://localhost:8080',
    apiKey: process.env.ESPOCRM_API_KEY || 'espocrm_api_key_module_04_secret',
    mockSender: options.espocrmMockSender,
  };

  // Paso 1: Recepción del webhook con payload validado
  if (!webhookPayload || !webhookPayload.itineraryId || !webhookPayload.phone) {
    throw new Error('Payload del webhook inválido: falta itineraryId o phone.');
  }

  // Paso 2: Despacho de la plantilla WhatsApp vía Chatwoot
  const templateResult = await sendWhatsAppSurveyTemplate(chatwootConfig, webhookPayload);

  // Paso 3: Parser de respuesta del viajero (Ley de Postel)
  const parsedData = parseNpsResponse(customerResponseText, {
    clientName: webhookPayload.clientName,
    contactId: webhookPayload.contactId,
    opportunityId: webhookPayload.opportunityId,
    itineraryId: webhookPayload.itineraryId,
  });

  // Si no se obtuvo score numérico, gestionar fallback
  if (!parsedData.hasValidScore) {
    const elapsedMs = Date.now() - startTime;
    return {
      success: false,
      reason: 'no_valid_nps_score',
      parsedData,
      templateResult,
      elapsedMs,
    };
  }

  // Paso 4: Ingesta en EspoCRM
  const espocrmRecord = await ingestFeedbackToEspoCRM(espocrmConfig, parsedData);

  const elapsedMs = Date.now() - startTime;

  return {
    success: true,
    feedbackId: espocrmRecord.id,
    sentiment: espocrmRecord.sentiment,
    followUpRequired: espocrmRecord.followUpRequired,
    followUpStatus: espocrmRecord.followUpStatus,
    espocrmRecord,
    parsedData,
    elapsedMs,
    dohertyCompliant: elapsedMs < 5000,
  };
}

module.exports = {
  httpRequest,
  parseNpsResponse,
  sendWhatsAppSurveyTemplate,
  ingestFeedbackToEspoCRM,
  runPostTripPipeline,
};
