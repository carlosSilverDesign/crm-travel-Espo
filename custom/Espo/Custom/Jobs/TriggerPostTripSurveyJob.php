<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Throwable;

class TriggerPostTripSurveyJob implements Job, JobDataLess
{
    /** @var callable|null */
    private $httpSender;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
        ?callable $httpSender = null
    ) {
        $this->httpSender = $httpSender;
    }

    /**
     * Ejecución del Scheduled Job diario de encuestas post-viaje.
     *
     * Peak-End Rule: Encuesta a las 24 horas del regreso (endDate = ayer).
     * Ley de Tesler: Identificación y normalización transparente de datos en backend.
     * Ley de Postel: Robustez ante teléfonos ausentes o caídas del receptor.
     * Umbral de Doherty: Consulta indexada sobre status y endDate (<400ms).
     */
    public function run(?Data $data = null): void
    {
        $targetDate = date('Y-m-d', strtotime('-1 day'));
        $webhookUrl = $this->getWebhookUrl();

        $this->log->info("TriggerPostTripSurveyJob: Iniciando búsqueda de retornos para la fecha {$targetDate}.");

        // Consulta de itinerarios que finalizaron exactamente ayer en estado Confirmado
        $itineraries = $this->entityManager->getRDBRepository('Itinerario')
            ->where([
                'status' => 'Confirmado',
                'endDate' => $targetDate,
            ])
            ->find();

        $count = count($itineraries);
        $this->log->info("TriggerPostTripSurveyJob: Se encontraron {$count} itinerarios finalizados el {$targetDate}.");

        $dispatched = 0;

        foreach ($itineraries as $itinerario) {
            try {
                $payload = $this->buildPayload($itinerario);

                if (!$payload) {
                    continue;
                }

                $this->dispatchWebhook($webhookUrl, $payload);
                $dispatched++;
            } catch (Throwable $e) {
                // Ley de Postel: Capturar y auditar fallos sin abortar el lote de viajeros restantes
                $this->log->warning(
                    "TriggerPostTripSurveyJob: Error procesando itinerario {$itinerario->getId()}: " . $e->getMessage()
                );
            }
        }

        $this->log->info("TriggerPostTripSurveyJob: Proceso finalizado. Total despachados: {$dispatched}/{$count}.");
    }

    /**
     * Construye y normaliza el payload JSON requerido para Activepieces.
     */
    public function buildPayload($itinerario): ?array
    {
        $itineraryId = $itinerario->getId();
        $oppId = $itinerario->get('opportunityId');
        $opportunity = $oppId ? $this->entityManager->getEntity('Opportunity', $oppId) : null;

        $contact = null;
        $rawPhone = null;
        $clientName = null;

        // 1. Intentar obtener el contacto titular desde los Pasajeros del itinerario
        $passengers = $this->entityManager->getRDBRepository('Passenger')
            ->where(['itinerarioId' => $itineraryId])
            ->order('id', 'ASC')
            ->find();

        if (count($passengers) > 0) {
            $pax = $passengers[0];
            $clientName = $pax->get('name');
            $contactId = $pax->get('contactId');
            if ($contactId) {
                $contact = $this->entityManager->getEntity('Contact', $contactId);
            }
        }

        // 2. Si no se obtuvo contacto por pasajero, buscar en Opportunity
        if (!$contact && $opportunity && $opportunity->get('contactId')) {
            $contact = $this->entityManager->getEntity('Contact', $opportunity->get('contactId'));
        }

        if ($contact) {
            $clientName = $clientName ?: $contact->get('name');
            $rawPhone = $contact->get('phoneNumber') ?? $contact->get('phone');
        }

        $normalizedPhone = self::normalizePhone($rawPhone);

        // Ley de Postel: Si carece de número válido, advertir en log y omitir sin arrojar excepción fatal
        if (!$normalizedPhone) {
            $this->log->warning(
                "TriggerPostTripSurveyJob: Itinerario {$itineraryId} omitido por falta de teléfono válido E.164 (tel: '{$rawPhone}')."
            );
            return null;
        }

        return [
            'itineraryId' => $itineraryId,
            'contactId' => $contact ? $contact->getId() : '',
            'phone' => $normalizedPhone,
            'clientName' => $clientName ?: 'Viajero',
            'destination' => $itinerario->get('destination') ?? $itinerario->get('name'),
        ];
    }

    /**
     * Normaliza cualquier teléfono de entrada al estándar internacional E.164 (+[1-9]\d{7,14}).
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Remover caracteres separadores habituales
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        if (empty($cleaned)) {
            return null;
        }

        // Si comienza con 00, reemplazar por +
        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+' . substr($cleaned, 2);
        }

        // Si no tiene prefijo internacional +, asumir código de país por defecto (+51 para números peruanos de 9 dígitos)
        if (!str_starts_with($cleaned, '+')) {
            if (strlen($cleaned) === 9 && str_starts_with($cleaned, '9')) {
                $cleaned = '+51' . $cleaned;
            } else {
                $cleaned = '+' . $cleaned;
            }
        }

        // Validar expresión regular internacional E.164: + seguido de entre 7 y 15 dígitos
        if (preg_match('/^\+[1-9]\d{6,14}$/', $cleaned)) {
            return $cleaned;
        }

        return null;
    }

    /**
     * Despacha el payload vía POST al webhook de Activepieces de forma desacoplada y defensiva.
     */
    protected function dispatchWebhook(string $url, array $payload): bool
    {
        if (empty($url)) {
            $this->log->warning("TriggerPostTripSurveyJob: URL de webhook no configurada. Omitiendo envío HTTP.");
            return false;
        }

        // Si se inyectó un sender HTTP (para tests o handlers dedicados), delegar
        if ($this->httpSender !== null) {
            return (bool) call_user_func($this->httpSender, $url, $payload);
        }

        $jsonPayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Fallo de conexión cURL al webhook de Activepieces: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Activepieces respondió con código de error HTTP {$httpCode}. Respuesta: {$response}");
        }

        return true;
    }

    /**
     * Resuelve la URL del webhook de Activepieces desde Config o variables de entorno.
     */
    private function getWebhookUrl(): string
    {
        return $this->config->get('activepiecesPostTripWebhookUrl')
            ?: (getenv('ACTIVEPIECES_POST_TRIP_WEBHOOK_URL') ?: 'https://activepieces.internal/api/v1/webhooks/post-trip');
    }
}
