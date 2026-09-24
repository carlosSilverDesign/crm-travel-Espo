/**
 * =============================================================================
 * SUITE DE PRUEBAS DE INTEGRACIÓN: TASK-047
 * Flujo de Ingesta y Disparo en Activepieces (Post-Trip NPS Survey)
 * =============================================================================
 */

const {
  parseNpsResponse,
  sendWhatsAppSurveyTemplate,
  runPostTripPipeline,
  httpRequest,
} = require('./post-trip-nps-engine');

function assert(condition, message) {
  if (!condition) {
    throw new Error(`FALLO DE ASERCIÓN: ${message}`);
  }
}

async function runTests() {
  console.log('=================================================================');
  console.log('  EJECUTANDO PRUEBA DE INTEGRACIÓN ACTIVEPIECES (TASK-047)');
  console.log('  Flujo Post-Viaje NPS WhatsApp y Activación de Calidad en CRM');
  console.log('=================================================================\n');

  // ---------------------------------------------------------------------------
  // TEST 1: Ley de Postel (Parser Tolerante de Respuestas en WhatsApp)
  // ---------------------------------------------------------------------------
  console.log('▶ [Paso 1] Probando Parser Tolerante de Calificaciones WhatsApp (Ley de Postel)...');

  const testCases = [
    { text: '10!', expectedScore: 10, label: 'Exclamación simple (10!)' },
    { text: 'Me encantó todo, les doy un 9', expectedScore: 9, label: 'Semántica en prosa (les doy un 9)' },
    { text: '5 puntos', expectedScore: 5, label: 'Sufijo puntos (5 puntos)' },
    { text: '10/10 viaje soñado con mi familia', expectedScore: 10, label: 'Fracción (10/10)' },
    { text: '8 de 10, pero el transfer se demoró', expectedScore: 8, label: 'Fracción textual (8 de 10)' },
    { text: '3 - pésimo hotel sin agua caliente', expectedScore: 3, label: 'Detractor con guión (3 - pésimo hotel)' },
    { text: '1 estrella, no vuelvo nunca', expectedScore: 1, label: 'Mínimo permitido (1 estrella)' },
    { text: 'Hola buenas tardes, gracias por preguntar', expectedScore: null, label: 'Sin puntaje numérico (Fallback)' },
  ];

  for (const tc of testCases) {
    const res = parseNpsResponse(tc.text, { clientName: 'Viajero Test' });
    if (tc.expectedScore !== null) {
      assert(res.hasValidScore === true, `Debe detectar score válido para: ${tc.label}`);
      assert(res.npsScore === tc.expectedScore, `Score esperado ${tc.expectedScore}, obtenido ${res.npsScore} en: ${tc.label}`);
      assert(res.comments.length > 0, `Debe aislar comentarios para: ${tc.label}`);
      console.log(`     \x1b[32m✔ [${tc.label}] => Score: ${res.npsScore} | Comentarios: "${res.comments}"\x1b[0m`);
    } else {
      assert(res.hasValidScore === false, `Debe gestionar fallback en: ${tc.label}`);
      assert(res.fallbackRequired === true, `Debe marcar fallbackRequired para: ${tc.label}`);
      console.log(`     \x1b[32m✔ [${tc.label}] => Fallback detectado sin error huérfano.\x1b[0m`);
    }
  }

  // ---------------------------------------------------------------------------
  // TEST 2: Despacho de Plantilla WhatsApp vía Chatwoot (Mock y Simulación)
  // ---------------------------------------------------------------------------
  console.log('\n▶ [Paso 2] Probando Despacho de Plantilla WhatsApp vía Chatwoot...');

  let sentPayload = null;
  const mockChatwoot = {
    baseUrl: 'http://localhost:3000',
    apiToken: 'mock_token',
    accountId: '1',
    mockSender: async (payload) => {
      sentPayload = payload;
      return { success: true, messageId: 'cw-msg-999' };
    },
  };

  const surveyData = {
    itineraryId: 'itin-test-101',
    contactId: 'contact-test-202',
    phone: '+51987654321',
    clientName: 'Mariana Senderista',
    destination: 'Machu Picchu Mágico',
  };

  const templateRes = await sendWhatsAppSurveyTemplate(mockChatwoot, surveyData);
  assert(templateRes.success === true, 'El despacho de plantilla debe ser exitoso.');
  assert(sentPayload.phone === '+51987654321', 'Debe dirigir al teléfono en E.164.');
  assert(sentPayload.content.includes('Mariana Senderista'), 'El mensaje debe personalizarse con el nombre del cliente.');
  assert(sentPayload.content.includes('Machu Picchu Mágico'), 'El mensaje debe incluir el destino del viaje.');
  console.log('     \x1b[32m✔ Plantilla WhatsApp post-viaje despachada correctamente al titular.\x1b[0m');

  // ---------------------------------------------------------------------------
  // TEST 3: Ingesta REST en EspoCRM en Tiempo Real (POST /api/v1/Feedback)
  // ---------------------------------------------------------------------------
  console.log('\n▶ [Paso 3] Probando Ingesta REST en EspoCRM y Activación Automática de Tarea Urgente...');

  const espocrmLiveConfig = {
    baseUrl: process.env.ESPOCRM_BASE_URL || 'http://localhost:8080',
    apiKey: process.env.ESPOCRM_API_KEY || 'espocrm_api_key_module_04_secret',
  };

  // Obtener un Contact existente vía REST API de EspoCRM
  const contactRes = await httpRequest(`${espocrmLiveConfig.baseUrl}/api/v1/Contact?maxSize=1`, {
    headers: { 'X-Api-Key': espocrmLiveConfig.apiKey },
  });

  assert(contactRes.statusCode === 200, 'Debe obtener lista de Contactos vía REST API.');
  assert(contactRes.body && contactRes.body.list && contactRes.body.list.length > 0, 'Debe existir al menos un Contact en EspoCRM.');
  const testContact = contactRes.body.list[0];
  const contactId = testContact.id;
  const contactName = testContact.name || 'Viajero Test';

  // 3a. Ingesta de Detractor (Score = 3)
  const detractorPayload = {
    itineraryId: 'itin-detractor-001',
    contactId: contactId,
    clientName: contactName,
    phone: '+51999111222',
    destination: 'Puno & Titicaca',
  };
  const customerComplaintText = '3 - El guía nunca se presentó y perdimos medio día';

  const pipelineStart = Date.now();
  const pipelineResult = await runPostTripPipeline(
    detractorPayload,
    customerComplaintText,
    {
      chatwootConfig: mockChatwoot,
      espocrmConfig: espocrmLiveConfig,
    }
  );
  const pipelineDuration = Date.now() - pipelineStart;

  assert(pipelineResult.success === true, 'El pipeline E2E debe culminar exitosamente.');
  assert(pipelineResult.sentiment === 'Detractor', 'El sentimiento en CRM debe ser Detractor.');
  assert(pipelineResult.followUpRequired === true, 'followUpRequired debe ser true para Detractor.');
  assert(pipelineResult.followUpStatus === 'Pending', 'followUpStatus debe ser Pending.');

  const feedbackId = pipelineResult.feedbackId;
  console.log(`     \x1b[32m✔ Feedback Detractor persistido en EspoCRM con ID: ${feedbackId} (Sentiment: Detractor, Pending).\x1b[0m`);

  // Verificar vía REST API que la Task urgente fue creada por el hook TASK-044
  const taskRes = await httpRequest(
    `${espocrmLiveConfig.baseUrl}/api/v1/Task?where[0][type]=equals&where[0][attribute]=parentId&where[0][value]=${feedbackId}`,
    {
      headers: { 'X-Api-Key': espocrmLiveConfig.apiKey },
    }
  );

  assert(taskRes.statusCode === 200, 'Debe consultar tareas vinculadas al Feedback vía REST API.');
  assert(taskRes.body && taskRes.body.list && taskRes.body.list.length > 0, 'El hook TASK-044 debe crear atómicamente la entidad Task para Detractores.');
  const urgentTask = taskRes.body.list[0];
  assert(urgentTask.priority === 'Urgent', 'La prioridad de la tarea debe ser Urgent.');
  assert(urgentTask.status === 'Not Started', 'El estado de la tarea debe ser Not Started.');
  assert(urgentTask.name.includes('Atención Urgente Detractor'), 'El nombre de la tarea debe contener Atención Urgente Detractor.');

  console.log(`     \x1b[32m✔ Tarea Urgente creada atómicamente en CRM: [ID: ${urgentTask.id}] "${urgentTask.name}" (${urgentTask.priority}).\x1b[0m`);

  // ---------------------------------------------------------------------------
  // TEST 4: Umbral de Doherty (Latencia E2E < 5 segundos)
  // ---------------------------------------------------------------------------
  console.log('\n▶ [Paso 4] Validando Umbral de Doherty y Latencia E2E (< 5 segundos)...');
  console.log(`     Tiempo total de ejecución del pipeline E2E: ${pipelineDuration} ms.`);
  assert(pipelineDuration < 5000, `La ejecución debe tomar menos de 5000 ms (tomó ${pipelineDuration} ms).`);
  console.log('     \x1b[32m✔ Umbral de Doherty ampliamente satisfecho (< 5000 ms).\x1b[0m');

  // ---------------------------------------------------------------------------
  // TEST 5: Limpieza de entidades de prueba en EspoCRM
  // ---------------------------------------------------------------------------
  await httpRequest(`${espocrmLiveConfig.baseUrl}/api/v1/Task/${urgentTask.id}`, {
    method: 'DELETE',
    headers: { 'X-Api-Key': espocrmLiveConfig.apiKey },
  });
  await httpRequest(`${espocrmLiveConfig.baseUrl}/api/v1/Feedback/${feedbackId}`, {
    method: 'DELETE',
    headers: { 'X-Api-Key': espocrmLiveConfig.apiKey },
  });
  console.log('\n     \x1b[32m✔ Registros de prueba eliminados limpiamente vía API REST de EspoCRM.\x1b[0m');

  console.log('\n=================================================================');
  console.log('  \x1b[32mTODAS LAS PRUEBAS DE INTEGRACIÓN TASK-047 PASARON CON ÉXITO\x1b[0m');
  console.log('=================================================================');
}

runTests().catch((err) => {
  console.error('\n\x1b[31mFALLO EN PRUEBA DE INTEGRACIÓN TASK-047:\x1b[0m', err.message);
  process.exit(1);
});
