<?php

namespace Espo\Core\Controllers;

if (!class_exists(\Espo\Core\Controllers\Base::class)) {
    abstract class Base
    {
        public function __construct(
            protected \Espo\Core\Container $container,
            protected \Espo\Core\ORM\EntityManager $entityManager,
            protected \Espo\Core\Utils\Config $config
        ) {}

        public function getContainer(): \Espo\Core\Container
        {
            return $this->container;
        }

        public function getEntityManager(): \Espo\Core\ORM\EntityManager
        {
            return $this->entityManager;
        }

        public function getConfig(): \Espo\Core\Utils\Config
        {
            return $this->config;
        }
    }
}

namespace Espo\Custom\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Unauthorized;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Custom\Services\LeadDistributionService;

/**
 * Controlador para la ingesta de webhooks de Chatwoot (WhatsApp & Omnicanalidad).
 */
class WebhookChatwoot extends Base
{
    public function __construct(
        Container $container,
        EntityManager $entityManager,
        Config $config,
        private ?ConfigWriter $configWriter = null
    ) {
        parent::__construct($container, $entityManager, $config);
    }

    /**
     * Acción POST para recibir eventos y mensajes entrantes de Chatwoot.
     *
     * @param mixed $params Parámetros de ruta
     * @param mixed $data Payload decodificado
     * @param mixed $request Objeto Request HTTP
     * @return array<string, mixed>
     */
    public function actionReceive($params, $data, $request): array
    {
        // =========================================================================
        // Paso A: Autenticación segura de cabecera (Fail-Fast)
        // =========================================================================
        $authHeader = null;
        if (is_object($request)) {
            if (method_exists($request, 'getHeader')) {
                $authHeader = $request->getHeader('Authorization');
                if (is_array($authHeader)) {
                    $authHeader = $authHeader[0] ?? null;
                }
            }
            if (!$authHeader && method_exists($request, 'getServerParam')) {
                $authHeader = $request->getServerParam('HTTP_AUTHORIZATION')
                    ?? $request->getServerParam('REDIRECT_HTTP_AUTHORIZATION');
            }
        }
        if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$authHeader && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $incomingToken = '';
        if (!empty($authHeader) && is_string($authHeader)) {
            if (preg_match('/Bearer\s+(.*)$/i', trim($authHeader), $matches)) {
                $incomingToken = trim($matches[1]);
            } else {
                $incomingToken = trim($authHeader);
            }
        }

        $configuredSecret = (string) $this->getConfig()->get('chatwootWebhookSecret', '');

        if (empty($incomingToken) || empty($configuredSecret) || !hash_equals($configuredSecret, $incomingToken)) {
            throw new Unauthorized("Unauthorized webhook access.");
        }

        // =========================================================================
        // Paso B: Filtrado de eventos y payload
        // =========================================================================
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        } elseif (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($data)) {
            if (is_object($request) && method_exists($request, 'getBodyContents')) {
                $rawBody = $request->getBodyContents();
                $decoded = json_decode($rawBody, true);
                $data = is_array($decoded) ? $decoded : [];
            } else {
                $data = [];
            }
        }

        $event = (string) ($data['event'] ?? '');

        if ($event !== 'conversation_created' && $event !== 'message_created') {
            return [
                'status' => 'ignored',
                'reason' => 'non_incoming_event',
            ];
        }

        if ($event === 'message_created') {
            $messageType = $data['message_type'] ?? ($data['message']['message_type'] ?? null);
            $isIncoming = ($messageType === 0 || $messageType === '0' || $messageType === 'incoming');
            if (!$isIncoming) {
                return [
                    'status' => 'ignored',
                    'reason' => 'non_incoming_event',
                ];
            }
        }

        // =========================================================================
        // Paso C: Extracción y normalización telefónica (Ley de Postel)
        // =========================================================================
        $sender = $data['meta']['sender']
            ?? $data['sender']
            ?? ($data['conversation']['meta']['sender'] ?? null)
            ?? ($data['conversation']['contact'] ?? null)
            ?? [];

        if (is_object($sender)) {
            $sender = (array) $sender;
        }

        $rawPhone = $sender['phone_number']
            ?? ($sender['identifier'] ?? null)
            ?? ($data['phone_number'] ?? null)
            ?? '';

        if (empty($rawPhone)) {
            throw new BadRequest("Missing sender phone number or identifier.");
        }

        $rawPhone = trim((string) $rawPhone);
        $hasPlus = str_starts_with($rawPhone, '+');
        $digits = preg_replace('/\D+/', '', $rawPhone);

        if (empty($digits)) {
            throw new BadRequest("Missing sender phone number or identifier.");
        }

        $normalizedPhone = ($hasPlus ? '+' : '+') . ltrim($digits, '+');

        // =========================================================================
        // Paso D: Resolución de Contacto (Evitar duplicados)
        // =========================================================================
        $chatwootContactId = (string) ($sender['id'] ?? '');
        $chatwootConversationId = (string) (
            $data['conversation']['id']
            ?? $data['conversation_id']
            ?? $data['id']
            ?? ''
        );

        $contact = null;
        if (!empty($chatwootContactId)) {
            $contact = $this->getEntityManager()->getRDBRepository('Contact')
                ->where(['chatwootContactId' => $chatwootContactId])
                ->findOne();
        }
        if (!$contact) {
            $contact = $this->getEntityManager()->getRDBRepository('Contact')
                ->where(['phoneNumber' => $normalizedPhone])
                ->findOne();
        }

        $now = date('Y-m-d H:i:s');

        if (!$contact) {
            $fullName = trim((string) ($sender['name'] ?? ''));
            if (empty($fullName)) {
                $firstName = 'Viajero';
                $lastName = 'WhatsApp';
            } else {
                $parts = preg_split('/\s+/', $fullName, 2);
                $firstName = $parts[0] ?? 'Viajero';
                $lastName = $parts[1] ?? 'WhatsApp';
            }

            $contact = $this->getEntityManager()->getNewEntity('Contact');
            $contact->set([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'phoneNumber' => $normalizedPhone,
                'chatwootContactId' => !empty($chatwootContactId) ? $chatwootContactId : null,
                'chatwootConversationId' => !empty($chatwootConversationId) ? $chatwootConversationId : null,
                'lastWhatsappMessageAt' => $now,
            ]);
            $this->getEntityManager()->saveEntity($contact);
        } else {
            $updateData = [
                'lastWhatsappMessageAt' => $now,
            ];
            if (!empty($chatwootConversationId)) {
                $updateData['chatwootConversationId'] = $chatwootConversationId;
            }
            if (!empty($chatwootContactId) && !$contact->get('chatwootContactId')) {
                $updateData['chatwootContactId'] = $chatwootContactId;
            }
            $contact->set($updateData);
            $this->getEntityManager()->saveEntity($contact);
        }

        // =========================================================================
        // Paso E: Resolución de Oportunidad Comercial y Asignación Round-Robin
        // =========================================================================
        $activeOpp = $this->getEntityManager()->getRDBRepository('Opportunity')
            ->where([
                'contactId' => $contact->getId(),
                'stage!=' => ['Closed Won', 'Closed Lost'],
            ])
            ->order('createdAt', 'DESC')
            ->findOne();

        if ($activeOpp) {
            // CASO 1: Existe oportunidad activa
            if (!empty($chatwootConversationId)) {
                $activeOpp->set('chatwootConversationId', $chatwootConversationId);
                $this->getEntityManager()->saveEntity($activeOpp);
            }
            $opportunityId = $activeOpp->getId();
            $assignedUserId = $activeOpp->get('assignedUserId');
        } else {
            // CASO 2: No existe oportunidad activa (o todas están cerradas)
            if ($this->getContainer()->has('leadDistributionService')) {
                $leadService = $this->getContainer()->get('leadDistributionService');
            } else {
                $configWriter = $this->configWriter;
                if ($configWriter === null && $this->getContainer()->has('configWriter')) {
                    $configWriter = $this->getContainer()->get('configWriter');
                } elseif ($configWriter === null && $this->getContainer()->has('injectableFactory')) {
                    try {
                        $configWriter = $this->getContainer()->get('injectableFactory')->create(\Espo\Core\Utils\Config\ConfigWriter::class);
                    } catch (\Throwable) {}
                }

                $leadService = new LeadDistributionService(
                    $this->getEntityManager(),
                    $this->getConfig(),
                    $configWriter
                );
            }

            $assignedUserId = $leadService->getNextAssignedUserId();

            $contactName = $contact->get('name') ?: ($contact->get('firstName') . ' ' . $contact->get('lastName'));

            $newOpp = $this->getEntityManager()->getNewEntity('Opportunity');
            $newOpp->set([
                'name' => 'Viaje WhatsApp - ' . trim((string) $contactName),
                'stage' => 'Prospecting',
                'leadSource' => 'WhatsApp',
                'contactId' => $contact->getId(),
                'assignedUserId' => $assignedUserId,
                'chatwootConversationId' => !empty($chatwootConversationId) ? $chatwootConversationId : null,
                'whatsappChannel' => 'Línea Oficial WhatsApp',
            ]);
            $this->getEntityManager()->saveEntity($newOpp);
            $opportunityId = $newOpp->getId();
        }

        // =========================================================================
        // Paso F: Respuesta HTTP
        // =========================================================================
        return [
            'status' => 'success',
            'contactId' => $contact->getId(),
            'opportunityId' => $opportunityId,
            'assignedUserId' => $assignedUserId,
        ];
    }

    /**
     * Alias para compatibilidad con enrutador POST.
     */
    public function postActionReceive($params, $data, $request): array
    {
        return $this->actionReceive($params, $data, $request);
    }
}
