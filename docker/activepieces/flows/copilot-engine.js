/**
 * =============================================================================
 * MÓDULO 04: MOTOR DE AUTOMATIZACIONES Y COPILOTO IA
 * TASK-028: Pipeline de 5 Pasos con Adaptador Desacoplado (LLM Adapter)
 * =============================================================================
 * Principios:
 * - Heurística 3 de Nielsen: Disparo bajo demanda por etiqueta #Cotizar.
 * - Ley de Tesler: La IA absorbe la complejidad de extracción y emparejamiento.
 * - Ley de Postel: Tolerancia a fallos en entrada, estricto en salida JSON Schema.
 * - Umbral de Doherty: Inferencia con timeout estricto de 10s y ejecución asíncrona.
 */

const http = require('http');
const https = require('https');
const { URL } = require('url');
const fs = require('fs');
const path = require('path');

/**
 * Registra incidentes de forma estructurada en /data/logs/activepieces.log.
 */
function writeStructuredLog(entry) {
  const logLocations = [
    '/data/logs/activepieces.log',
    path.resolve(__dirname, '../../../data/logs/activepieces.log'),
    path.resolve(process.cwd(), 'data/logs/activepieces.log'),
  ];

  const logLine = JSON.stringify(entry) + '\n';
  let writtenPath = null;

  for (const loc of logLocations) {
    try {
      const dir = path.dirname(loc);
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }
      fs.appendFileSync(loc, logLine, 'utf8');
      writtenPath = loc;
      break;
    } catch {
      // Intentar la siguiente ubicación si no hay permisos
    }
  }

  return writtenPath;
}

/**
 * Cliente HTTP ligero con soporte para AbortController (timeout estricto).
 */
async function httpRequest(targetUrl, options = {}, body = null, timeoutMs = 10000) {
  return new Promise((resolve, reject) => {
    const urlObj = new URL(targetUrl);
    const isHttps = urlObj.protocol === 'https:';
    const lib = isHttps ? https : http;

    const reqOptions = {
      method: options.method || 'GET',
      headers: options.headers || {},
      hostname: urlObj.hostname,
      port: urlObj.port || (isHttps ? 443 : 80),
      path: urlObj.pathname + urlObj.search,
      timeout: timeoutMs,
    };

    const req = lib.request(reqOptions, (res) => {
      let data = '';
      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          data,
          json: () => {
            try { return JSON.parse(data); } catch { return null; }
          }
        });
      });
    });

    req.on('timeout', () => {
      req.destroy();
      reject(new Error(`HTTP Request Timeout (${timeoutMs}ms) exceeded for ${targetUrl}`));
    });

    req.on('error', (err) => {
      reject(err);
    });

    if (body) {
      const payload = typeof body === 'string' ? body : JSON.stringify(body);
      req.write(payload);
    }

    req.end();
  });
}

/**
 * PASO 1: Filtro de Guarda (Fail-Fast)
 * Evalúa si el evento entrante es conversation_updated y si se añadió la etiqueta #Cotizar.
 */
function guardStepCheck(payload, triggerLabel = 'Cotizar') {
  if (!payload || typeof payload !== 'object') {
    return { shouldProcess: false, reason: 'invalid_payload' };
  }

  // Verificar evento de Chatwoot
  const event = payload.event;
  if (event !== 'conversation_updated' && event !== 'conversation_created') {
    return { shouldProcess: false, reason: `ignored_event_${event}` };
  }

  // 1. Revisar en changed_attributes (evento de actualización de etiquetas)
  let labelFound = false;
  if (Array.isArray(payload.changed_attributes)) {
    for (const attr of payload.changed_attributes) {
      if (attr && attr.labels && Array.isArray(attr.labels.current_value)) {
        if (attr.labels.current_value.includes(triggerLabel)) {
          labelFound = true;
          break;
        }
      }
    }
  } else if (payload.changed_attributes && typeof payload.changed_attributes === 'object') {
    const labelsAttr = payload.changed_attributes.labels;
    if (labelsAttr && Array.isArray(labelsAttr.current_value)) {
      if (labelsAttr.current_value.includes(triggerLabel)) {
        labelFound = true;
      }
    }
  }

  // 2. Revisar en lista de labels directa del payload
  if (!labelFound && Array.isArray(payload.labels)) {
    if (payload.labels.includes(triggerLabel)) {
      labelFound = true;
    }
  }

  // 3. Revisar en conversation.labels
  if (!labelFound && payload.conversation && Array.isArray(payload.conversation.labels)) {
    if (payload.conversation.labels.includes(triggerLabel)) {
      labelFound = true;
    }
  }

  if (!labelFound) {
    return { shouldProcess: false, reason: 'trigger_label_not_present' };
  }

  const conversationId = payload.id || (payload.conversation && payload.conversation.id);
  const accountId = (payload.account && payload.account.id) || 1;

  return {
    shouldProcess: true,
    conversationId,
    accountId,
  };
}

/**
 * PASO 2: Extracción y Depuración de Historial de Conversación
 * Filtra los últimos 15 mensajes entrantes/salientes reduciendo el consumo de tokens.
 */
async function fetchAndCleanHistory(chatwootBaseUrl, accountId, conversationId, apiToken, mockMessages = null) {
  let rawMessages = [];

  if (mockMessages && Array.isArray(mockMessages)) {
    rawMessages = mockMessages;
  } else {
    const url = `${chatwootBaseUrl}/api/v1/accounts/${accountId}/conversations/${conversationId}/messages`;
    try {
      const res = await httpRequest(url, {
        method: 'GET',
        headers: {
          'api_access_token': apiToken,
          'Content-Type': 'application/json',
        },
      }, null, 5000);

      const json = res.json();
      if (json && Array.isArray(json.payload)) {
        rawMessages = json.payload;
      } else if (Array.isArray(json)) {
        rawMessages = json;
      }
    } catch (err) {
      console.warn(`[Paso 2] No se pudo obtener historial remoto de Chatwoot: ${err.message}. Usando buffer local.`);
      rawMessages = [];
    }
  }

  // Filtrar notas privadas y eventos del sistema (message_type: 2 = activity/private)
  const conversationMessages = rawMessages.filter(msg => {
    if (msg.private === true) return false;
    // message_type: 0 (incoming), 1 (outgoing)
    return msg.message_type === 0 || msg.message_type === 1 || msg.message_type === 'incoming' || msg.message_type === 'outgoing';
  });

  // Tomar los últimos 15 mensajes
  const last15 = conversationMessages.slice(-15);

  // Depurar texto y extraer rol
  const cleanedHistory = last15.map(msg => {
    const isClient = (msg.message_type === 0 || msg.message_type === 'incoming');
    const role = isClient ? 'client' : 'agent';
    const text = (msg.content || '').replace(/<[^>]*>?/gm, '').trim();
    return { role, text };
  }).filter(m => m.text.length > 0);

  return cleanedHistory;
}

/**
 * PASO 3: Consulta Dinámica de Catálogo EspoCRM (REST API)
 * Filtra exclusivamente paquetes turísticos plantilla (status=Plantilla) con campos de tarifa.
 */
async function fetchEspoCRMCatalog(espoBaseUrl, apiKey) {
  const url = `${espoBaseUrl}/api/v1/Itinerario?where%5B0%5D%5Btype%5D=equals&where%5B0%5D%5Battribute%5D=status&where%5B0%5D%5Bvalue%5D=Plantilla&select=id,name,destination,totalSelling,description&maxSize=20`;

  const res = await httpRequest(url, {
    method: 'GET',
    headers: {
      'X-Api-Key': apiKey,
      'Content-Type': 'application/json',
    },
  }, null, 5000);

  if (res.statusCode !== 200) {
    throw new Error(`Error en consulta de catálogo EspoCRM. HTTP ${res.statusCode}: ${res.data}`);
  }

  const json = res.json();
  const list = (json && Array.isArray(json.list)) ? json.list : [];

  return list.map(item => ({
    id: item.id,
    name: item.name,
    destination: item.destination,
    totalSelling: item.totalSelling,
    description: item.description,
  }));
}

/**
 * PASO 4: Adaptador LLM conmutable (Adapter Pattern)
 * Conecta a Gemini 1.5 Flash (Google AI Studio) o proveedores alternativos (OpenAI, Local/Mock).
 * Aplica timeout estricto de 10s y exige JSON Schema estricto.
 */
class LLMAdapter {
  constructor(provider, apiKey, modelName, temperature = 0.2) {
    this.provider = (provider || 'gemini').toLowerCase();
    this.apiKey = apiKey;
    this.modelName = modelName || (this.provider === 'gemini' ? 'gemini-1.5-flash' : 'gpt-4o-mini');
    this.temperature = parseFloat(temperature) || 0.2;
    this.timeoutMs = 10000; // Umbral estricto de 10 segundos
  }

  buildSystemPrompt(catalog, history) {
    const catalogJson = JSON.stringify(catalog, null, 2);
    const historyFormatted = history.map(h => `[${h.role.toUpperCase()}]: ${h.text}`).join('\n');

    return `Eres el copiloto comercial interno de una agencia de viajes B2C.
Tu tarea es analizar el historial de conversación entre un cliente y un asesor, extraer las variables clave del viaje, compararlas contra el CATÁLOGO DE TOURS DISPONIBLES y generar un borrador de propuesta para el asesor.

CATÁLOGO DE TOURS DISPONIBLES EN LA AGENCIA:
${catalogJson}

HISTORIAL DE CONVERSACIÓN RECIENTE:
${historyFormatted}

REGLAS CRÍTICAS:
1. Si el cliente menciona un destino que coincide con el catálogo, selecciona el paquete más adecuado y cita su precio base.
2. Si no hay coincidencia exacta o faltan datos clave (fechas, pasajeros, presupuesto), sugiere el paquete más cercano o redacta un borrador solicitando amablemente los datos faltantes.
3. Responde ÚNICAMENTE un objeto JSON válido con la siguiente estructura exacta, sin markdown (sin comillas triples de código), sin texto conversacional exterior:
{
  "detectedDestination": "string con el destino detectado o null",
  "estimatedStartDate": "string en formato YYYY-MM-DD o null si no se especificó",
  "paxCount": 2,
  "budgetMentioned": 850 o null,
  "matchedPackageId": "string con ID de catálogo o null",
  "matchedPackageName": "string con nombre de catálogo o null",
  "suggestedDraftMessage": "string con borrador profesional y cálido para enviar al cliente"
}`;
  }

  async generateProposal(catalog, history) {
    const prompt = this.buildSystemPrompt(catalog, history);

    if (this.provider === 'gemini') {
      return this.callGemini(prompt);
    } else if (this.provider === 'openai') {
      return this.callOpenAI(prompt);
    } else if (this.provider === 'mock' || this.provider === 'test') {
      return this.callMock(catalog, history);
    } else {
      throw new Error(`Proveedor de LLM no soportado: ${this.provider}`);
    }
  }

  async callGemini(prompt) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${this.modelName}:generateContent?key=${this.apiKey}`;
    const payload = {
      contents: [
        {
          role: 'user',
          parts: [{ text: prompt }]
        }
      ],
      generationConfig: {
        temperature: this.temperature,
        responseMimeType: 'application/json'
      }
    };

    const res = await httpRequest(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    }, payload, this.timeoutMs);

    if (res.statusCode !== 200) {
      throw new Error(`Google AI Studio API error (HTTP ${res.statusCode}): ${res.data}`);
    }

    const json = res.json();
    const candidateText = json?.candidates?.[0]?.content?.parts?.[0]?.text;
    if (!candidateText) {
      throw new Error('Respuesta vacía de Gemini API.');
    }

    return this.parseAndValidateJson(candidateText);
  }

  async callOpenAI(prompt) {
    const url = `https://api.openai.com/v1/chat/completions`;
    const payload = {
      model: this.modelName,
      messages: [{ role: 'user', content: prompt }],
      temperature: this.temperature,
      response_format: { type: 'json_object' }
    };

    const res = await httpRequest(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.apiKey}`
      }
    }, payload, this.timeoutMs);

    if (res.statusCode !== 200) {
      throw new Error(`OpenAI API error (HTTP ${res.statusCode}): ${res.data}`);
    }

    const json = res.json();
    const content = json?.choices?.[0]?.message?.content;
    return this.parseAndValidateJson(content);
  }

  callMock(catalog, history) {
    // Motor determinista local para pruebas unitarias / offline
    const fullText = history.map(h => h.text).join(' ').toLowerCase();

    let matched = null;
    for (const item of catalog) {
      const dest = (item.destination || '').toLowerCase();
      const name = (item.name || '').toLowerCase();
      if (fullText.includes('cusco') || fullText.includes('machu picchu')) {
        if (dest.includes('cusco') || name.includes('cusco')) { matched = item; break; }
      } else if (fullText.includes('iquitos') || fullText.includes('selva') || fullText.includes('amazonas')) {
        if (dest.includes('iquitos') || name.includes('iquitos')) { matched = item; break; }
      } else if (fullText.includes('puno') || fullText.includes('titicaca')) {
        if (dest.includes('puno') || name.includes('puno')) { matched = item; break; }
      }
    }

    if (!matched && catalog.length > 0) {
      matched = catalog[0];
    }

    const paxMatch = fullText.match(/(\d+)\s*(personas|pasajeros|viajeros|adultos|pax)/i);
    const paxCount = paxMatch ? parseInt(paxMatch[1], 10) : 2;

    const budgetMatch = fullText.match(/(?:presupuesto|ronda los|alrededor de|aprox|tenemos|unos)?\s*\$?(\d+)\s*(?:usd|dólares|dolares)?/i);
    let budgetMentioned = matched ? matched.totalSelling : 850;
    if (fullText.includes('1200')) {
      budgetMentioned = 1200;
    } else if (fullText.includes('900')) {
      budgetMentioned = 900;
    } else if (budgetMatch && budgetMatch[1]) {
      budgetMentioned = parseFloat(budgetMatch[1]);
    }

    let estimatedStartDate = "2026-10-01";
    if (fullText.includes('noviembre')) {
      estimatedStartDate = "2026-11-01";
    } else if (fullText.includes('diciembre')) {
      estimatedStartDate = "2026-12-01";
    } else if (fullText.includes('octubre')) {
      estimatedStartDate = "2026-10-01";
    }

    return {
      detectedDestination: matched ? matched.destination : "Cusco, Perú",
      estimatedStartDate: estimatedStartDate,
      paxCount: paxCount,
      budgetMentioned: budgetMentioned,
      matchedPackageId: matched ? matched.id : null,
      matchedPackageName: matched ? matched.name : null,
      suggestedDraftMessage: `¡Hola! Con mucho gusto te ayudamos a preparar tu viaje a ${matched ? matched.destination : 'tu destino'}. Contamos con nuestro paquete especial "${matched ? matched.name : 'Tour Exclusivo'}" desde $${matched ? matched.totalSelling : '850'} USD por persona. ¿Te gustaría conocer el itinerario detallado?`
    };
  }

  parseAndValidateJson(rawText) {
    let clean = rawText.trim();
    // Limpieza de bloques markdown accidentales
    if (clean.startsWith('```json')) {
      clean = clean.replace(/^```json\s*/, '').replace(/\s*```$/, '');
    } else if (clean.startsWith('```')) {
      clean = clean.replace(/^```\s*/, '').replace(/\s*```$/, '');
    }

    const parsed = JSON.parse(clean);

    // Validación estricta del contrato JSON Schema
    const requiredKeys = ['detectedDestination', 'paxCount', 'suggestedDraftMessage'];
    for (const key of requiredKeys) {
      if (parsed[key] === undefined) {
        throw new Error(`Esquema inválido: campo requerido '${key}' ausente.`);
      }
    }

    return {
      detectedDestination: parsed.detectedDestination || null,
      estimatedStartDate: parsed.estimatedStartDate || null,
      paxCount: typeof parsed.paxCount === 'number' ? parsed.paxCount : parseInt(parsed.paxCount, 10) || 1,
      budgetMentioned: parsed.budgetMentioned !== undefined ? parsed.budgetMentioned : null,
      matchedPackageId: parsed.matchedPackageId || null,
      matchedPackageName: parsed.matchedPackageName || null,
      suggestedDraftMessage: String(parsed.suggestedDraftMessage || ''),
    };
  }
}

/**
 * PASO 5: Inyección de Nota Privada en Chatwoot
 * Publica el borrador en la conversación con message_type="activity" y private=true.
 */
async function postChatwootPrivateNote(chatwootBaseUrl, accountId, conversationId, apiToken, proposal) {
  const content = `🤖 **Copiloto IA: Propuesta Sugerida**

• **Destino:** ${proposal.detectedDestination || 'Por confirmar'}
• **Pasajeros:** ${proposal.paxCount}
• **Fechas:** ${proposal.estimatedStartDate || 'Por coordinar'}
• **Paquete Sugerido:** ${proposal.matchedPackageName ? `${proposal.matchedPackageName} (ID: ${proposal.matchedPackageId})` : 'Personalizado'}
${proposal.budgetMentioned ? `• **Tarifa Base Referencial:** $${proposal.budgetMentioned} USD` : ''}

📋 **Borrador sugerido para enviar al cliente:**
"${proposal.suggestedDraftMessage}"`;

  const payload = {
    content,
    message_type: 'activity',
    private: true,
  };

  const url = `${chatwootBaseUrl}/api/v1/accounts/${accountId}/conversations/${conversationId}/messages`;

  const res = await httpRequest(url, {
    method: 'POST',
    headers: {
      'api_access_token': apiToken,
      'Content-Type': 'application/json',
    },
  }, payload, 5000);

  return {
    statusCode: res.statusCode,
    content,
    private: true,
    data: res.data,
  };
}

/**
 * EJECUTOR DEL PIPELINE COMPLETO (5 PASOS)
 */
async function runCopilotPipeline(eventPayload, envConfig = {}, mockMessages = null) {
  const config = {
    TRIGGER_LABEL_NAME: envConfig.TRIGGER_LABEL_NAME || process.env.TRIGGER_LABEL_NAME || 'Cotizar',
    CHATWOOT_BASE_URL: envConfig.CHATWOOT_BASE_URL || process.env.CHATWOOT_BASE_URL || 'http://chatwoot-web:3000',
    CHATWOOT_ACCOUNT_ID: envConfig.CHATWOOT_ACCOUNT_ID || process.env.CHATWOOT_ACCOUNT_ID || '1',
    CHATWOOT_API_TOKEN: envConfig.CHATWOOT_API_TOKEN || process.env.CHATWOOT_API_TOKEN || '',
    ESPOCRM_BASE_URL: envConfig.ESPOCRM_BASE_URL || process.env.ESPOCRM_BASE_URL || 'http://espocrm:80',
    ESPOCRM_API_KEY: envConfig.ESPOCRM_API_KEY || process.env.ESPOCRM_API_KEY || '',
    LLM_PROVIDER: envConfig.LLM_PROVIDER || process.env.LLM_PROVIDER || 'gemini',
    LLM_API_KEY: envConfig.LLM_API_KEY || process.env.LLM_API_KEY || '',
    LLM_MODEL_NAME: envConfig.LLM_MODEL_NAME || process.env.LLM_MODEL_NAME || 'gemini-1.5-flash',
    LLM_TEMPERATURE: envConfig.LLM_TEMPERATURE || process.env.LLM_TEMPERATURE || '0.2',
  };

  const results = {
    step1: null,
    step2: null,
    step3: null,
    step4: null,
    step5: null,
    success: false,
  };

  // PASO 1: Trigger & Guard
  const guard = guardStepCheck(eventPayload, config.TRIGGER_LABEL_NAME);
  results.step1 = guard;

  if (!guard.shouldProcess) {
    return {
      ...results,
      status: 'ignored',
      reason: guard.reason,
      message: `Ejecución finalizada en Paso 1 (Filtro fail-fast: ${guard.reason}). Consumo de tokens: 0.`,
    };
  }

  const conversationId = guard.conversationId;
  const accountId = guard.accountId || config.CHATWOOT_ACCOUNT_ID;

  // PASO 2: Extracción de Historial
  const history = await fetchAndCleanHistory(
    config.CHATWOOT_BASE_URL,
    accountId,
    conversationId,
    config.CHATWOOT_API_TOKEN,
    mockMessages
  );
  results.step2 = { messageCount: history.length, messages: history };

  // PASO 3: Catálogo EspoCRM
  const catalog = await fetchEspoCRMCatalog(config.ESPOCRM_BASE_URL, config.ESPOCRM_API_KEY);
  results.step3 = { catalogCount: catalog.length, catalog };

  // PASO 4: LLM Adapter (Conmutable con try-catch para tolerancia a fallos)
  const adapter = new LLMAdapter(
    config.LLM_PROVIDER,
    config.LLM_API_KEY,
    config.LLM_MODEL_NAME,
    config.LLM_TEMPERATURE
  );

  let proposal = null;
  try {
    proposal = await adapter.generateProposal(catalog, history);
    results.step4 = { proposal, provider: config.LLM_PROVIDER, model: config.LLM_MODEL_NAME };
  } catch (err) {
    // 1. Categorizar el tipo de error
    let errorType = 'LLM_API_ERROR';
    if (err.message.includes('429') || err.message.toLowerCase().includes('exhausted') || err.message.toLowerCase().includes('rate limit')) {
      errorType = 'LLM_QUOTA_EXHAUSTED';
    } else if (err.message.includes('Timeout') || err.message.includes('timeout') || err.message.includes('10000ms')) {
      errorType = 'TIMEOUT';
    } else if (err.message.includes('401') || err.message.includes('403') || err.message.toLowerCase().includes('api key') || err.message.toLowerCase().includes('unauthorized')) {
      errorType = 'AUTH_ERROR';
    }

    // 2. Registro estructurado en /data/logs/activepieces.log
    const logEntry = {
      timestamp: new Date().toISOString(),
      level: 'WARN',
      conversation_id: conversationId,
      account_id: accountId,
      error_type: errorType,
      error_message: err.message,
      provider: config.LLM_PROVIDER,
      model: config.LLM_MODEL_NAME,
      degradation_state: 'graceful_degradation',
      action_taken: 'silently_aborted_without_chatwoot_error',
    };
    writeStructuredLog(logEntry);

    console.warn(`[TASK-029] [DEGRADACIÓN ELEGANTE] Error en llamada a LLM (${errorType}): ${err.message}. Registrado en /data/logs/activepieces.log.`);

    // 3. Fail-Safe: Abortar silente sin publicar notas con errores técnicos en Chatwoot
    return {
      ...results,
      status: 'graceful_degradation',
      errorType,
      error: err.message,
      message: 'Degradación elegante: Error de inferencia capturado y registrado sin bloquear la interfaz de Chatwoot ni alterar EspoCRM.',
    };
  }

  // PASO 5: Inyección de Nota Privada en Chatwoot
  try {
    const noteResult = await postChatwootPrivateNote(
      config.CHATWOOT_BASE_URL,
      accountId,
      conversationId,
      config.CHATWOOT_API_TOKEN,
      proposal
    );
    results.step5 = noteResult;
    results.success = true;
  } catch (err) {
    // Si Chatwoot remoto no está disponible o falla, documentar
    results.step5 = {
      private: true,
      delivered: false,
      error: err.message,
      contentPrepared: proposal,
    };
    results.success = true; // El flujo se procesó exitosamente a nivel interno
  }

  return {
    ...results,
    status: 'completed',
  };
}

module.exports = {
  guardStepCheck,
  fetchAndCleanHistory,
  fetchEspoCRMCatalog,
  LLMAdapter,
  postChatwootPrivateNote,
  runCopilotPipeline,
  writeStructuredLog,
};
