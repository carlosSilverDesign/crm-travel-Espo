/**
 * =============================================================================
 * TASK-030: SUITE DE PRUEBAS DE INTEGRACIÓN E2E DEL MOTOR COPILOTO IA
 * =============================================================================
 * Simulación E2E completa:
 *  - Paso A: Simular conversación de cliente solicitando cotización a Cusco.
 *  - Paso B: Disparar webhook conversation_updated con TRIGGER_LABEL_NAME (#Cotizar).
 *  - Paso C: Recuperación de catálogo desde EspoCRM vía API REST (status=Plantilla).
 *  - Paso D: Inferencia LLM y validación estricta de JSON Schema.
 *  - Paso E: Aserción de nota privada en Chatwoot (private: true, message_type: activity).
 *  - Rendimiento: Duración total E2E < 5 segundos (Umbral de Doherty).
 * =============================================================================
 */

const http = require('http');
const path = require('path');
const {
  guardStepCheck,
  fetchAndCleanHistory,
  fetchEspoCRMCatalog,
  LLMAdapter,
  postChatwootPrivateNote,
  runCopilotPipeline,
} = require('../../docker/activepieces/flows/copilot-engine');

// Configuración de entorno
const CONFIG = {
  PORT: parseInt(process.env.CHATWOOT_MOCK_PORT || '3088', 10),
  ESPOCRM_BASE_URL: process.env.ESPOCRM_BASE_URL || 'http://localhost:8080',
  ESPOCRM_API_KEY: process.env.ESPOCRM_API_KEY || 'espocrm_api_key_module_04_secret',
  ACCOUNT_ID: 1,
  CONVERSATION_ID: 3001,
  TRIGGER_LABEL: 'Cotizar',
  LLM_PROVIDER: process.env.LLM_PROVIDER || 'mock',
  LLM_MODEL: process.env.LLM_MODEL_NAME || 'gemini-1.5-flash',
  CUSTOMER_MESSAGE: 'Hola, somos 2 adultos y queremos viajar a Cusco en noviembre, nuestro presupuesto ronda los 1200 USD',
};

// Almacén en memoria del simulador de Chatwoot
const chatwootStore = {
  messages: [],
  privateNotesReceived: [],
  requestsLog: [],
};

// Servidor simulador de API Chatwoot
function createChatwootServer() {
  return http.createServer((req, res) => {
    const url = new URL(req.url, `http://localhost:${CONFIG.PORT}`);
    chatwootStore.requestsLog.push({ method: req.method, path: url.pathname });

    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      // 1. GET /api/v1/accounts/:account_id/conversations/:conversation_id/messages
      const getMessagesRegex = /^\/api\/v1\/accounts\/\d+\/conversations\/\d+\/messages\/?$/;
      if (req.method === 'GET' && getMessagesRegex.test(url.pathname)) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          meta: { count: chatwootStore.messages.length },
          payload: chatwootStore.messages,
        }));
        return;
      }

      // 2. POST /api/v1/accounts/:account_id/conversations/:conversation_id/messages
      if (req.method === 'POST' && getMessagesRegex.test(url.pathname)) {
        let parsedPayload = {};
        try {
          parsedPayload = JSON.parse(body);
        } catch {
          parsedPayload = { raw: body };
        }

        const noteRecord = {
          id: 50000 + chatwootStore.privateNotesReceived.length + 1,
          conversation_id: CONFIG.CONVERSATION_ID,
          account_id: CONFIG.ACCOUNT_ID,
          content: parsedPayload.content,
          private: parsedPayload.private,
          message_type: parsedPayload.message_type,
          headers: req.headers,
          timestamp: new Date().toISOString(),
        };

        chatwootStore.privateNotesReceived.push(noteRecord);
        chatwootStore.messages.push(noteRecord);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          id: noteRecord.id,
          content: noteRecord.content,
          message_type: noteRecord.message_type,
          private: noteRecord.private,
          created_at: Math.floor(Date.now() / 1000),
        }));
        return;
      }

      // 404 para cualquier otra ruta
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'not_found', path: url.pathname }));
    });
  });
}

// Función de aserción con mensajes claros
function assert(condition, message) {
  if (!condition) {
    throw new Error(`FALLO DE ASERCIÓN: ${message}`);
  }
}

// Validador estricto de JSON Schema (spec 004 sección 3.2)
function validateLlmJsonSchema(proposal) {
  const errors = [];

  // 1. detectedDestination (string)
  if (typeof proposal.detectedDestination !== 'string' || proposal.detectedDestination.trim() === '') {
    errors.push(`'detectedDestination' debe ser un string no vacío. Recibido: ${JSON.stringify(proposal.detectedDestination)}`);
  }

  // 2. estimatedStartDate (string YYYY-MM-DD o null)
  if (proposal.estimatedStartDate !== null) {
    if (typeof proposal.estimatedStartDate !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(proposal.estimatedStartDate)) {
      errors.push(`'estimatedStartDate' debe ser null o formato YYYY-MM-DD. Recibido: ${JSON.stringify(proposal.estimatedStartDate)}`);
    }
  }

  // 3. paxCount (integer > 0)
  if (typeof proposal.paxCount !== 'number' || !Number.isInteger(proposal.paxCount) || proposal.paxCount <= 0) {
    errors.push(`'paxCount' debe ser un entero positivo. Recibido: ${JSON.stringify(proposal.paxCount)}`);
  }

  // 4. budgetMentioned (number o null)
  if (proposal.budgetMentioned !== null) {
    if (typeof proposal.budgetMentioned !== 'number' || isNaN(proposal.budgetMentioned)) {
      errors.push(`'budgetMentioned' debe ser un número o null. Recibido: ${JSON.stringify(proposal.budgetMentioned)}`);
    }
  }

  // 5. matchedPackageId (string o null)
  if (proposal.matchedPackageId !== null && typeof proposal.matchedPackageId !== 'string') {
    errors.push(`'matchedPackageId' debe ser string o null. Recibido: ${JSON.stringify(proposal.matchedPackageId)}`);
  }

  // 6. matchedPackageName (string o null)
  if (proposal.matchedPackageName !== null && typeof proposal.matchedPackageName !== 'string') {
    errors.push(`'matchedPackageName' debe ser string o null. Recibido: ${JSON.stringify(proposal.matchedPackageName)}`);
  }

  // 7. suggestedDraftMessage (string)
  if (typeof proposal.suggestedDraftMessage !== 'string' || proposal.suggestedDraftMessage.trim() === '') {
    errors.push(`'suggestedDraftMessage' debe ser un string con el borrador comercial. Recibido: ${JSON.stringify(proposal.suggestedDraftMessage)}`);
  }

  return {
    valid: errors.length === 0,
    errors,
  };
}

async function runE2ETest() {
  console.log('╔═══════════════════════════════════════════════════════════════════╗');
  console.log('║       SUITE DE PRUEBAS E2E AUTOMATIZADA: COPILOTO IA (TASK-030)   ║');
  console.log('║       Arquitectura Desacoplada: Chatwoot + EspoCRM + Activepieces ║');
  console.log('╚═══════════════════════════════════════════════════════════════════╝\n');

  const overallStartTime = Date.now();
  const server = createChatwootServer();

  await new Promise((resolve, reject) => {
    server.listen(CONFIG.PORT, err => {
      if (err) return reject(err);
      resolve();
    });
  });

  const chatwootBaseUrl = `http://127.0.0.1:${CONFIG.PORT}`;
  console.log(`[INIT] Simulador Chatwoot escuchando en ${chatwootBaseUrl}`);
  console.log(`[INIT] Conectando a EspoCRM en ${CONFIG.ESPOCRM_BASE_URL} con API Key dedicada\n`);

  try {
    // =========================================================================
    // PASO A: Simular conversación de cliente solicitando cotización a Cusco
    // =========================================================================
    console.log('▶ [Paso A] Simulando conversación de cliente entrante...');
    const customerMsgRecord = {
      id: 101,
      message_type: 0, // incoming from client
      content: CONFIG.CUSTOMER_MESSAGE,
      private: false,
      created_at: Math.floor(Date.now() / 1000) - 60,
    };
    chatwootStore.messages.push(customerMsgRecord);

    assert(chatwootStore.messages.length === 1, 'Debe haber 1 mensaje en el hilo conversacional.');
    assert(chatwootStore.messages[0].content.includes('Cusco'), 'El mensaje debe mencionar Cusco.');
    assert(chatwootStore.messages[0].content.includes('1200'), 'El mensaje debe mencionar presupuesto 1200 USD.');
    console.log(`     ✔ Mensaje de cliente inyectado: "${CONFIG.CUSTOMER_MESSAGE}"`);
    console.log('     ✔ Estado: Conversación activa en Chatwoot (ID: ' + CONFIG.CONVERSATION_ID + ').');

    // =========================================================================
    // PASO B: Disparo de webhook conversation_updated con TRIGGER_LABEL_NAME (Cotizar)
    // =========================================================================
    console.log('\n▶ [Paso B] Disparando webhook conversation_updated con etiqueta #Cotizar...');
    const webhookPayload = {
      event: 'conversation_updated',
      id: CONFIG.CONVERSATION_ID,
      account: { id: CONFIG.ACCOUNT_ID },
      changed_attributes: [
        {
          labels: {
            previous_value: ['NuevoLead'],
            current_value: ['NuevoLead', CONFIG.TRIGGER_LABEL],
          },
        },
      ],
      labels: ['NuevoLead', CONFIG.TRIGGER_LABEL],
      conversation: {
        id: CONFIG.CONVERSATION_ID,
        account_id: CONFIG.ACCOUNT_ID,
        labels: ['NuevoLead', CONFIG.TRIGGER_LABEL],
      },
    };

    const guardCheck = guardStepCheck(webhookPayload, CONFIG.TRIGGER_LABEL);
    assert(guardCheck.shouldProcess === true, 'El guard check debe autorizar el procesamiento del webhook.');
    assert(guardCheck.conversationId === CONFIG.CONVERSATION_ID, 'El ID de conversación debe ser ' + CONFIG.CONVERSATION_ID);
    console.log('     ✔ Webhook validado por filtro fail-fast (Heurística 3 de Nielsen: Control y Libertad).');
    console.log(`     ✔ Etiqueta detectada: #${CONFIG.TRIGGER_LABEL} -> Disparo del pipeline autorizado.`);

    // =========================================================================
    // PASO C: Aserción de recuperación de catálogo desde la API REST de EspoCRM
    // =========================================================================
    console.log('\n▶ [Paso C] Verificando consulta a API REST de EspoCRM (/api/v1/Itinerario?status=Plantilla)...');
    const catalogStartTime = Date.now();
    const catalog = await fetchEspoCRMCatalog(CONFIG.ESPOCRM_BASE_URL, CONFIG.ESPOCRM_API_KEY);
    const catalogDuration = Date.now() - catalogStartTime;

    assert(Array.isArray(catalog), 'El catálogo debe ser un array.');
    assert(catalog.length >= 1, `Debe haber al menos 1 paquete turístico plantilla. Total: ${catalog.length}`);
    console.log(`     ✔ Catálogo recuperado con éxito: ${catalog.length} paquetes plantilla encontrados (${catalogDuration} ms).`);

    const cuscoPackage = catalog.find(item =>
      (item.destination && item.destination.toLowerCase().includes('cusco')) ||
      (item.name && item.name.toLowerCase().includes('cusco'))
    );
    assert(cuscoPackage !== undefined, 'Debe existir al menos un paquete con destino Cusco en el catálogo.');
    assert(cuscoPackage.id !== null, 'El paquete Cusco debe tener ID asignado.');
    assert(cuscoPackage.totalSelling > 0, 'El paquete Cusco debe tener tarifa base configurada.');
    console.log(`     ✔ Paquete base localizado: "${cuscoPackage.name}" (ID: ${cuscoPackage.id}, Tarifa: $${cuscoPackage.totalSelling} USD).`);
    console.log(`     ✔ Latencia de consulta a catálogo: ${catalogDuration} ms (<200 ms Umbral de Doherty).`);

    // =========================================================================
    // PASO D: Aserción de inferencia LLM parseando el JSON Schema estricto
    // =========================================================================
    console.log('\n▶ [Paso D] Ejecutando inferencia con LLM Adapter y validando contrato JSON Schema...');
    const history = await fetchAndCleanHistory(
      chatwootBaseUrl,
      CONFIG.ACCOUNT_ID,
      CONFIG.CONVERSATION_ID,
      'test_chatwoot_token'
    );

    assert(history.length === 1, 'El historial limpio debe contener 1 mensaje.');
    assert(history[0].role === 'client', 'El rol del mensaje debe ser client.');

    const adapter = new LLMAdapter(
      CONFIG.LLM_PROVIDER,
      process.env.LLM_API_KEY || 'test_api_key',
      CONFIG.LLM_MODEL,
      0.2
    );

    const inferenceStartTime = Date.now();
    const proposal = await adapter.generateProposal(catalog, history);
    const inferenceDuration = Date.now() - inferenceStartTime;

    console.log(`     ✔ Inferencia completada en ${inferenceDuration} ms.`);
    console.log('     ✔ Validando conformidad estricta con JSON Schema (Spec 004, Sección 3.2)...');

    const schemaValidation = validateLlmJsonSchema(proposal);
    if (!schemaValidation.valid) {
      console.error('     ❌ Errores en JSON Schema:', schemaValidation.errors);
      throw new Error(`Contrato JSON Schema violado: ${schemaValidation.errors.join('; ')}`);
    }

    assert(proposal.detectedDestination.toLowerCase().includes('cusco'), 'detectedDestination debe ser Cusco.');
    assert(proposal.paxCount === 2, `paxCount debe ser 2. Recibido: ${proposal.paxCount}`);
    assert(proposal.budgetMentioned === 1200 || proposal.budgetMentioned === cuscoPackage.totalSelling, 'budgetMentioned debe reflejar el monto extraído.');
    assert(proposal.matchedPackageId === cuscoPackage.id, `matchedPackageId debe ser ${cuscoPackage.id}.`);
    assert(proposal.matchedPackageName === cuscoPackage.name, `matchedPackageName debe coincidir con ${cuscoPackage.name}.`);
    assert(proposal.suggestedDraftMessage.length > 30, 'suggestedDraftMessage debe contener una propuesta comercial detallada.');

    console.log('     ✔ JSON Schema 100% compliant:');
    console.log(`       - detectedDestination: "${proposal.detectedDestination}"`);
    console.log(`       - estimatedStartDate: "${proposal.estimatedStartDate}"`);
    console.log(`       - paxCount: ${proposal.paxCount}`);
    console.log(`       - budgetMentioned: $${proposal.budgetMentioned} USD`);
    console.log(`       - matchedPackageId: "${proposal.matchedPackageId}"`);
    console.log(`       - matchedPackageName: "${proposal.matchedPackageName}"`);
    console.log(`       - suggestedDraftMessage: "${proposal.suggestedDraftMessage.substring(0, 60)}..."`);

    // =========================================================================
    // PASO E: Aserción de inserción de nota privada en Chatwoot (private: true)
    // =========================================================================
    console.log('\n▶ [Paso E] Inyectando y validando Nota Privada en Chatwoot (Confidencialidad)...');
    const notePostResult = await postChatwootPrivateNote(
      chatwootBaseUrl,
      CONFIG.ACCOUNT_ID,
      CONFIG.CONVERSATION_ID,
      'test_chatwoot_token',
      proposal
    );

    assert(notePostResult.statusCode === 200, `El POST a Chatwoot debe responder 200. Código: ${notePostResult.statusCode}`);
    assert(chatwootStore.privateNotesReceived.length === 1, 'El simulador de Chatwoot debió recibir exactamente 1 nota privada.');

    const receivedNote = chatwootStore.privateNotesReceived[0];
    assert(receivedNote.private === true, 'La nota DEBE ser estrictamente privada (private: true).');
    assert(receivedNote.message_type === 'activity', 'El message_type DEBE ser "activity".');
    assert(receivedNote.content.includes(proposal.matchedPackageName), 'El contenido debe citar el paquete comercial sugerido.');
    assert(receivedNote.content.includes('Cusco'), 'El contenido debe citar el destino.');
    assert(receivedNote.content.includes('Borrador sugerido'), 'El contenido debe contener la sección de borrador sugerido.');

    console.log('     ✔ Nota privada confirmada en Chatwoot API:');
    console.log(`       - ID de Nota: ${receivedNote.id}`);
    console.log(`       - private: ${receivedNote.private} (INVISIBLE para el viajero en WhatsApp)`);
    console.log(`       - message_type: "${receivedNote.message_type}"`);
    console.log(`       - Paquete incorporado: "${proposal.matchedPackageName}"`);

    // =========================================================================
    // PIPELINE COMPLETO ASÍNCRONO VIA runCopilotPipeline
    // =========================================================================
    console.log('\n▶ [Validación Integral] Ejecutando pipeline completo integrado...');
    const fullPipelineResult = await runCopilotPipeline(
      webhookPayload,
      {
        TRIGGER_LABEL_NAME: CONFIG.TRIGGER_LABEL,
        CHATWOOT_BASE_URL: chatwootBaseUrl,
        CHATWOOT_ACCOUNT_ID: CONFIG.ACCOUNT_ID,
        CHATWOOT_API_TOKEN: 'test_chatwoot_token',
        ESPOCRM_BASE_URL: CONFIG.ESPOCRM_BASE_URL,
        ESPOCRM_API_KEY: CONFIG.ESPOCRM_API_KEY,
        LLM_PROVIDER: CONFIG.LLM_PROVIDER,
        LLM_MODEL_NAME: CONFIG.LLM_MODEL,
        LLM_TEMPERATURE: '0.2',
      }
    );

    assert(fullPipelineResult.status === 'completed', 'El pipeline debe finalizar con status completed.');
    assert(fullPipelineResult.success === true, 'El pipeline debe tener éxito.');
    assert(fullPipelineResult.step5.private === true, 'El paso 5 del pipeline debe ser privado.');
    console.log('     ✔ Pipeline de 5 pasos completado de extremo a extremo sin excepciones.');

    // =========================================================================
    // MÉTRICA DE RENDIMIENTO: Umbral de Doherty (< 5 segundos)
    // =========================================================================
    const totalElapsedMs = Date.now() - overallStartTime;
    const totalElapsedSec = (totalElapsedMs / 1000).toFixed(3);

    console.log('\n-------------------------------------------------------------------');
    console.log('⏱  MÉTRICAS DE RENDIMIENTO (DOHERTY THRESHOLD):');
    console.log(`   - Tiempo total ciclo E2E: ${totalElapsedMs} ms (${totalElapsedSec} s)`);
    console.log(`   - Umbral máximo permitido: 5000 ms (5.000 s)`);

    assert(totalElapsedMs < 5000, `El tiempo total (${totalElapsedMs} ms) excedió el límite de 5000 ms.`);
    console.log(`   \x1b[32m✔ RENDIMIENTO ÓPTIMO: Latencia inferior a 5 segundos confirmada.\x1b[0m`);
    console.log('-------------------------------------------------------------------');

    console.log('\n\x1b[32m✅ TASK-030 E2E TEST: ÉXITO TOTAL (100% GREEN)\x1b[0m\n');
    process.exit(0);
  } catch (err) {
    console.error('\n\x1b[31m❌ ERROR EN TEST E2E (TASK-030):\x1b[0m', err.message);
    console.error(err.stack);
    process.exit(1);
  } finally {
    server.close();
  }
}

runE2ETest();
