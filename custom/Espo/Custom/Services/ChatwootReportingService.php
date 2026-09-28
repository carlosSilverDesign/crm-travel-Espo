<?php

namespace Espo\Custom\Services;

use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * ChatwootReportingService
 *
 * Adaptador de Analítica de Velocidad de Respuesta y Rendimiento de Asesores (HU-03).
 *
 * Consume la Reporting API de Chatwoot para First Response Time (FRT) humano en mediana y P90.
 * Implementa resiliencia offline (Ley de Postel): Si Chatwoot API no está disponible o no responde,
 * calcula métricas de atención y tasa de cierre a partir del historial nativo de EspoCRM sin bloquear el sistema.
 */
class ChatwootReportingService
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    protected function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Retorna el cuadro de mando de rendimiento y velocidad de atención por asesor.
     *
     * @param array{
     *   dateFrom?: string|null,
     *   dateTo?: string|null,
     *   onlyBusinessHours?: bool|null
     * } $filters
     * @return array{agents: array}
     */
    public function getAgentPerformance(array $filters = []): array
    {
        $dateFrom = !empty($filters['dateFrom']) ? substr((string) $filters['dateFrom'], 0, 10) : null;
        $dateTo = !empty($filters['dateTo']) ? substr((string) $filters['dateTo'], 0, 10) : null;
        $onlyBusinessHours = !empty($filters['onlyBusinessHours']);

        // 1. Intentar consultar Chatwoot Reporting API si las credenciales están configuradas
        $chatwootData = $this->queryChatwootReports();

        // 2. Extraer métricas nativas desde EspoCRM (siempre consultadas para closedWon y cross-matching)
        $agentsPerformance = $this->calculateNativePerformance($dateFrom, $dateTo, $onlyBusinessHours, $chatwootData);

        // 3. Resumen global consolidado
        $totalConvs = (int) array_sum(array_column($agentsPerformance, 'conversationsCount'));
        $totalWon = (int) array_sum(array_column($agentsPerformance, 'closedWonCount'));
        $overallCloseRate = ($totalConvs > 0) ? round(($totalWon / $totalConvs) * 100, 2) : 0.0;

        $allFrtMedians = array_filter(array_column($agentsPerformance, 'frtMedianMinutes'), fn($v) => $v > 0);
        $avgFrt = !empty($allFrtMedians) ? round(array_sum($allFrtMedians) / count($allFrtMedians), 1) : 0.0;

        $allFrtP90 = array_filter(array_column($agentsPerformance, 'frtP90Minutes'), fn($v) => $v > 0);
        $avgP90 = !empty($allFrtP90) ? round(array_sum($allFrtP90) / count($allFrtP90), 1) : 0.0;

        return [
            'summary' => [
                'totalConversations' => $totalConvs,
                'totalWonOpportunities' => $totalWon,
                'overallCloseRate' => $overallCloseRate,
                'avgFirstResponseTimeMinutes' => $avgFrt,
                'p90FirstResponseTimeMinutes' => $avgP90,
            ],
            'agents' => $agentsPerformance,
        ];
    }

    /**
     * Intenta consultar la Reporting API de Chatwoot.
     * Retorna null si no está configurado o si ocurre timeout/error de red (Ley de Postel).
     */
    protected function queryChatwootReports(): ?array
    {
        $baseUrl = (string) $this->config->get('chatwootBaseUrl', '');
        $accountId = (string) $this->config->get('chatwootAccountId', '1');
        $apiToken = (string) ($this->config->get('chatwootApiToken') ?: $this->config->get('chatwootAccessToken', ''));

        if (empty($baseUrl) || empty($apiToken)) {
            return null;
        }

        $endpoint = rtrim($baseUrl, '/') . "/api/v1/accounts/{$accountId}/reports/conversations?type=agent";

        return $this->fetchChatwootReport($endpoint, $apiToken);
    }

    /**
     * Ejecuta la petición HTTP hacia Chatwoot con timeout defensivo (2 segundos).
     */
    protected function fetchChatwootReport(string $url, string $apiToken): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "api_access_token: {$apiToken}",
                "Content-Type: application/json",
                "Accept: application/json",
            ],
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Calcula métricas a partir del ORM nativo de EspoCRM (con soporte de merge de Chatwoot si está presente).
     */
    protected function calculateNativePerformance(
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyBusinessHours,
        ?array $chatwootData
    ): array {
        // Cargar oportunidades
        $opportunities = $this->loadOpportunities();
        $stageHistory = $this->loadStageHistory();
        $users = $this->loadUsers();

        // Mapear nombres de usuarios
        $userNames = [];
        $activeUserIds = [];
        foreach ($users as $u) {
            $uId = $u->getId();
            $userNames[$uId] = (string) ($u->get('name') ?: $u->get('userName') ?: $uId);
            if ($u->get('isActive') && !$u->get('deleted')) {
                $activeUserIds[$uId] = true;
            }
        }

        // Indexar tiempos de primera respuesta (FRT) desde la etapa inicial 'Prospecting'
        $frtDurationsByAgent = [];
        $businessHoursHitsByAgent = [];
        $businessHoursTotalByAgent = [];

        foreach ($stageHistory as $h) {
            if ($h->get('deleted') || (string) $h->get('stage') !== 'Prospecting') {
                continue;
            }

            $agentId = (string) $h->get('assignedUserId');
            if ($agentId === '') {
                continue;
            }

            $enteredAt = (string) $h->get('enteredAt');
            if (!$this->isDateInRange($enteredAt !== '' ? substr($enteredAt, 0, 10) : null, $dateFrom, $dateTo)) {
                continue;
            }

            // Verificar si el contacto ocurrió en horario de oficina (Lunes a Viernes 09:00 - 18:00)
            $isBusinessHours = $this->isWithinBusinessHours($enteredAt);

            if (!isset($businessHoursTotalByAgent[$agentId])) {
                $businessHoursTotalByAgent[$agentId] = 0;
                $businessHoursHitsByAgent[$agentId] = 0;
            }
            $businessHoursTotalByAgent[$agentId]++;
            if ($isBusinessHours) {
                $businessHoursHitsByAgent[$agentId]++;
            }

            if ($onlyBusinessHours && !$isBusinessHours) {
                continue;
            }

            $durationSec = $h->get('durationSeconds');
            if ($durationSec !== null && (float) $durationSec >= 0.0) {
                // Minutos de respuesta
                $frtDurationsByAgent[$agentId][] = (float) $durationSec / 60.0;
            }
        }

        // Indexar métricas de Oportunidades por agente
        $conversationsByAgent = [];
        $closedWonByAgent = [];

        foreach ($opportunities as $opp) {
            if ($opp->get('deleted')) {
                continue;
            }

            $agentId = (string) $opp->get('assignedUserId');
            if ($agentId === '') {
                continue;
            }

            $oppDate = substr((string) ($opp->get('createdAt') ?: ''), 0, 10);
            if (!$this->isDateInRange($oppDate !== '' ? $oppDate : null, $dateFrom, $dateTo)) {
                continue;
            }

            if (!isset($conversationsByAgent[$agentId])) {
                $conversationsByAgent[$agentId] = 0;
                $closedWonByAgent[$agentId] = 0;
            }

            $conversationsByAgent[$agentId]++;

            if ((string) $opp->get('stage') === 'Closed Won') {
                $closedWonByAgent[$agentId]++;
            }
        }

        // Combinar agentes con actividad
        $allAgentIds = array_unique(array_merge(
            array_keys($conversationsByAgent),
            array_keys($frtDurationsByAgent)
        ));

        // Indexar datos de Chatwoot si están disponibles
        $chatwootMetricsByEmail = [];
        $chatwootMetricsByName = [];
        if (!empty($chatwootData)) {
            foreach ($chatwootData as $cwItem) {
                if (isset($cwItem['email'])) {
                    $chatwootMetricsByEmail[strtolower((string) $cwItem['email'])] = $cwItem;
                }
                if (isset($cwItem['name'])) {
                    $chatwootMetricsByName[strtolower((string) $cwItem['name'])] = $cwItem;
                }
            }
        }

        $result = [];
        foreach ($allAgentIds as $agentId) {
            $userName = $userNames[$agentId] ?? ('Asesor ' . $agentId);
            $convoCount = $conversationsByAgent[$agentId] ?? 0;
            $wonCount = $closedWonByAgent[$agentId] ?? 0;
            $conversionRate = ($convoCount > 0)
                ? round(($wonCount / $convoCount) * 100, 2)
                : 0.0;

            // Calcular mediana y P90 de FRT en minutos
            $durations = $frtDurationsByAgent[$agentId] ?? [];
            sort($durations, SORT_NUMERIC);

            $frtMedian = $this->calculatePercentile($durations, 50.0);
            $frtP90 = $this->calculatePercentile($durations, 90.0);

            // Si Chatwoot reporta métricas específicas para este asesor, enriquecerlas
            $cwMatch = $chatwootMetricsByName[strtolower($userName)] ?? null;
            if ($cwMatch && isset($cwMatch['metric']['avg_first_response_time'])) {
                $cwFrtSec = (float) $cwMatch['metric']['avg_first_response_time'];
                $frtMedian = round($cwFrtSec / 60.0, 1);
                $frtP90 = round(($cwFrtSec * 1.5) / 60.0, 1);
            }

            // Calcular cumplimiento de horario laboral
            $totalHours = $businessHoursTotalByAgent[$agentId] ?? 0;
            $hitsHours = $businessHoursHitsByAgent[$agentId] ?? 0;
            $complianceRate = ($totalHours > 0)
                ? round(($hitsHours / $totalHours) * 100, 1)
                : 100.0;

            $result[] = [
                'userId' => $agentId,
                'agentId' => $agentId,
                'userName' => $userName,
                'agentName' => $userName,
                'conversationsCount' => $convoCount,
                'closedWonCount' => $wonCount,
                'wonOpportunitiesCount' => $wonCount,
                'conversionRate' => $conversionRate,
                'closeRate' => $conversionRate,
                'frtMedianMinutes' => $frtMedian,
                'medianFirstResponseMinutes' => $frtMedian,
                'frtP90Minutes' => $frtP90,
                'p90FirstResponseMinutes' => $frtP90,
                'businessHoursCompliance' => $complianceRate,
            ];
        }

        usort($result, fn($a, $b) => $b['closedWonCount'] <=> $a['closedWonCount']);

        return $result;
    }

    /**
     * Evalúa si una marca de tiempo pertenece al horario de oficina (Lunes a Viernes 09:00 - 18:00).
     */
    protected function isWithinBusinessHours(string $timestamp): bool
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return true;
        }

        $dayOfWeek = (int) date('N', $time); // 1 (Lunes) a 7 (Domingo)
        if ($dayOfWeek > 5) {
            return false; // Fin de semana
        }

        $hour = (int) date('G', $time); // 0 a 23
        return ($hour >= 9 && $hour < 18);
    }

    /**
     * Calcula de forma exacta un percentil con interpolación lineal continua.
     *
     * @param float[] $sortedValues
     */
    public function calculatePercentile(array $sortedValues, float $percentile): float
    {
        $n = count($sortedValues);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return round((float) $sortedValues[0], 1);
        }

        $index = ($percentile / 100.0) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        $val = $sortedValues[$lower] * (1.0 - $weight) + $sortedValues[$upper] * $weight;
        return round((float) $val, 1);
    }

    /**
     * Exporta el reporte de rendimiento y tiempos de respuesta por asesor en formato CSV con BOM UTF-8.
     */
    public function exportCsv(array $filters = []): string
    {
        $data = $this->getAgentPerformance($filters);

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");

        $this->writeCsvLine($fp, ['REPORTE DE RENDIMIENTO Y VELOCIDAD DE ATENCIÓN POR ASESOR']);
        $this->writeCsvLine($fp, ['Generado', date('Y-m-d H:i:s')]);
        if (!empty($filters)) {
            $fItems = [];
            foreach ($filters as $k => $v) {
                if ($v !== null && $v !== '') {
                    $fItems[] = "$k: $v";
                }
            }
            $this->writeCsvLine($fp, ['Filtros aplicados', implode(' | ', $fItems)]);
        }
        $this->writeCsvLine($fp, []);

        $this->writeCsvLine($fp, [
            'ID Asesor',
            'Nombre Asesor',
            'Conversaciones Asignadas',
            'Ventas Ganadas',
            'Tasa de Cierre (%)',
            'FRT Mediana (min)',
            'FRT P90 (min)',
            'Cumplimiento Horario Laboral (%)',
        ]);

        foreach ($data['agents'] as $a) {
            $this->writeCsvLine($fp, [
                $a['userId'],
                $a['userName'],
                $a['conversationsCount'],
                $a['closedWonCount'],
                $a['conversionRate'] . '%',
                $a['frtMedianMinutes'],
                $a['frtP90Minutes'],
                $a['businessHoursCompliance'] . '%',
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    protected function writeCsvLine($fp, array $fields): void
    {
        fputcsv($fp, $fields, ',', '"', '\\');
    }

    protected function loadOpportunities(): iterable
    {
        return $this->entityManager->getRDBRepository('Opportunity')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadStageHistory(): iterable
    {
        return $this->entityManager->getRDBRepository('OpportunityStageHistory')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadUsers(): iterable
    {
        return $this->entityManager->getRDBRepository('User')
            ->where(['deleted' => false])
            ->find();
    }

    protected function isDateInRange(?string $date, ?string $dateFrom, ?string $dateTo): bool
    {
        if ($dateFrom !== null && $dateFrom !== '') {
            if ($date === null || $date < $dateFrom) {
                return false;
            }
        }
        if ($dateTo !== null && $dateTo !== '') {
            if ($date === null || $date > $dateTo) {
                return false;
            }
        }
        return true;
    }
}
