<?php

namespace Espo\Custom\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * DestinationQualityService
 *
 * Servicio de Inteligencia Operativa y Calidad en Destino (HU-04).
 *
 * Mide el Net Promoter Score (NPS) global, el impacto financiero
 * de contingencias operativas (Incident.costImpact) y el cumplimiento
 * de SLA (< 2 horas) en la resolución de tareas de atención a clientes detractores.
 */
class DestinationQualityService
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Retorna los indicadores consolidados de calidad, contingencias y SLA de detractores.
     *
     * @param array{
     *   dateFrom?: string|null,
     *   dateTo?: string|null
     * } $filters
     * @return array
     */
    public function getDestinationQuality(array $filters = []): array
    {
        $dateFrom = !empty($filters['dateFrom']) ? substr((string) $filters['dateFrom'], 0, 10) : null;
        $dateTo = !empty($filters['dateTo']) ? substr((string) $filters['dateTo'], 0, 10) : null;

        // 1. Métricas NPS (Encuestas de satisfacción)
        $feedbacks = $this->loadFeedbacks();
        $totalResponses = 0;
        $promoters = 0;
        $passives = 0;
        $detractors = 0;

        foreach ($feedbacks as $fb) {
            if ($fb->get('deleted')) {
                continue;
            }

            $fbDate = substr((string) ($fb->get('createdAt') ?: ''), 0, 10);
            if (!$this->isDateInRange($fbDate !== '' ? $fbDate : null, $dateFrom, $dateTo)) {
                continue;
            }

            $score = (int) ($fb->get('npsScore') ?? 0);
            $sentiment = (string) ($fb->get('sentiment') ?? '');

            $totalResponses++;

            if ($score >= 9 || $sentiment === 'Promoter') {
                $promoters++;
            } elseif ($score >= 7 || $sentiment === 'Passive') {
                $passives++;
            } else {
                $detractors++;
            }
        }

        $npsScore = ($totalResponses > 0)
            ? round((($promoters - $detractors) / $totalResponses) * 100, 2)
            : 0.0;

        // 2. Métricas de Contingencias Operativas
        $incidents = $this->loadIncidents();
        $incidentsCount = 0;
        $totalCostImpact = 0.0;

        foreach ($incidents as $inc) {
            if ($inc->get('deleted')) {
                continue;
            }

            $incDate = substr((string) ($inc->get('createdAt') ?: ''), 0, 10);
            if (!$this->isDateInRange($incDate !== '' ? $incDate : null, $dateFrom, $dateTo)) {
                continue;
            }

            $incidentsCount++;
            $totalCostImpact += (float) ($inc->get('costImpact') ?? 0.0);
        }

        $totalCostImpact = round($totalCostImpact, 2);
        $averageCostPerIncident = ($incidentsCount > 0)
            ? round($totalCostImpact / $incidentsCount, 2)
            : 0.0;

        // 3. Cumplimiento de SLA para Detractores (< 2 horas)
        $tasks = $this->loadDetractorTasks();
        $totalDetractorTasks = 0;
        $resolvedUnder2Hours = 0;

        foreach ($tasks as $task) {
            if ($task->get('deleted')) {
                continue;
            }

            $taskDate = substr((string) ($task->get('createdAt') ?: ''), 0, 10);
            if (!$this->isDateInRange($taskDate !== '' ? $taskDate : null, $dateFrom, $dateTo)) {
                continue;
            }

            $taskName = (string) $task->get('name');
            $isDetractorTask = (stripos($taskName, 'Detractor') !== false)
                || ((string) $task->get('priority') === 'Urgent' && (string) $task->get('parentType') === 'Feedback');

            if (!$isDetractorTask) {
                continue;
            }

            $totalDetractorTasks++;

            $isResolved = in_array((string) $task->get('status'), ['Completed', 'Resolved'], true);
            if ($isResolved) {
                $createdAt = strtotime((string) $task->get('createdAt'));
                $completedAt = strtotime((string) ($task->get('dateCompleted') ?: $task->get('modifiedAt')));

                if ($createdAt !== false && $completedAt !== false) {
                    $diffSeconds = $completedAt - $createdAt;
                    if ($diffSeconds <= 7200) { // Menor o igual a 2 horas
                        $resolvedUnder2Hours++;
                    }
                } else {
                    // Si no tiene fecha exacta de cierre pero está completada
                    $resolvedUnder2Hours++;
                }
            }
        }

        $slaComplianceRate = ($totalDetractorTasks > 0)
            ? round(($resolvedUnder2Hours / $totalDetractorTasks) * 100, 1)
            : 100.0;

        $npsData = [
            'totalResponses' => $totalResponses,
            'totalSurveys' => $totalResponses,
            'promoters' => $promoters,
            'promotersCount' => $promoters,
            'promotersPercentage' => ($totalResponses > 0) ? round(($promoters / $totalResponses) * 100, 1) : 0.0,
            'passives' => $passives,
            'passivesCount' => $passives,
            'passivesPercentage' => ($totalResponses > 0) ? round(($passives / $totalResponses) * 100, 1) : 0.0,
            'detractors' => $detractors,
            'detractorsCount' => $detractors,
            'detractorsPercentage' => ($totalResponses > 0) ? round(($detractors / $totalResponses) * 100, 1) : 0.0,
            'npsScore' => $npsScore,
            'score' => $npsScore,
        ];

        $contingencyData = [
            'incidentsCount' => $incidentsCount,
            'totalIncidents' => $incidentsCount,
            'totalCostImpact' => $totalCostImpact,
            'totalContingencyCost' => $totalCostImpact,
            'averageCostPerIncident' => $averageCostPerIncident,
        ];

        $slaData = [
            'totalDetractorTasks' => $totalDetractorTasks,
            'totalDetractors' => $totalDetractorTasks,
            'resolvedUnder2Hours' => $resolvedUnder2Hours,
            'slaComplianceRate' => $slaComplianceRate,
            'slaCompliancePercentage' => $slaComplianceRate,
            'targetHours' => 2,
        ];

        return [
            'nps' => $npsData,
            'contingency' => $contingencyData,
            'contingencies' => $contingencyData,
            'detractorSla' => $slaData,
        ];
    }

    /**
     * Exporta el reporte de calidad y contingencias a formato CSV con BOM UTF-8.
     */
    public function exportCsv(array $filters = []): string
    {
        $data = $this->getDestinationQuality($filters);

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");

        $this->writeCsvLine($fp, ['REPORTE DE CALIDAD Y CONTINGENCIAS EN DESTINO']);
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

        // 1. Net Promoter Score
        $this->writeCsvLine($fp, ['--- 1. NET PROMOTER SCORE (NPS) ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Valor']);
        $this->writeCsvLine($fp, ['Total Respuestas Encuesta', $data['nps']['totalResponses']]);
        $this->writeCsvLine($fp, ['Promotores (9-10)', $data['nps']['promoters']]);
        $this->writeCsvLine($fp, ['Pasivos (7-8)', $data['nps']['passives']]);
        $this->writeCsvLine($fp, ['Detractores (1-6)', $data['nps']['detractors']]);
        $this->writeCsvLine($fp, ['NPS Score Global', $data['nps']['npsScore'] . '%']);
        $this->writeCsvLine($fp, []);

        // 2. Contingencias
        $this->writeCsvLine($fp, ['--- 2. IMPACTO ECONÓMICO DE CONTINGENCIAS ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Valor']);
        $this->writeCsvLine($fp, ['Incidentes Reportados', $data['contingency']['incidentsCount']]);
        $this->writeCsvLine($fp, ['Costo Total Asumido por Agencia', number_format($data['contingency']['totalCostImpact'], 2, '.', '')]);
        $this->writeCsvLine($fp, ['Costo Promedio por Incidente', number_format($data['contingency']['averageCostPerIncident'], 2, '.', '')]);
        $this->writeCsvLine($fp, []);

        // 3. SLA Detractores
        $this->writeCsvLine($fp, ['--- 3. SLA DE ATENCIÓN A DETRACTORES (< 2 HORAS) ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Valor']);
        $this->writeCsvLine($fp, ['Tareas de Atención a Detractores', $data['detractorSla']['totalDetractorTasks']]);
        $this->writeCsvLine($fp, ['Resueltas en Menos de 2 Horas', $data['detractorSla']['resolvedUnder2Hours']]);
        $this->writeCsvLine($fp, ['Tasa de Cumplimiento de SLA', $data['detractorSla']['slaComplianceRate'] . '%']);

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    protected function writeCsvLine($fp, array $fields): void
    {
        fputcsv($fp, $fields, ',', '"', '\\');
    }

    protected function loadFeedbacks(): iterable
    {
        return $this->entityManager->getRDBRepository('Feedback')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadIncidents(): iterable
    {
        return $this->entityManager->getRDBRepository('Incident')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadDetractorTasks(): iterable
    {
        return $this->entityManager->getRDBRepository('Task')
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
