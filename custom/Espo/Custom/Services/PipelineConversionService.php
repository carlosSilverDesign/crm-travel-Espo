<?php

namespace Espo\Custom\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * PipelineConversionService
 *
 * Servicio de Inteligencia Comercial para el análisis de conversión de embudo,
 * tiempos de permanencia (Mediana y P90) y motivos de descarte.
 *
 * Mide con precisión matemática la transición etapa a etapa a partir
 * del historial auditado en 'OpportunityStageHistory'.
 */
class PipelineConversionService
{
    /**
     * Secuencia oficial de etapas de progresión comercial hacia la venta.
     */
    public const STAGES_PROGRESSION = [
        'Prospecting',
        'Qualification',
        'Proposal',
        'Negotiation',
        'PaymentPending',
        'Closed Won',
    ];

    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Calcula las métricas consolidadas de conversión de embudo, duraciones y motivos de pérdida.
     *
     * @param array{
     *   dateFrom?: string|null,
     *   dateTo?: string|null,
     *   leadSource?: string|null,
     *   assignedUserId?: string|null
     * } $filters
     * @return array
     */
    public function getPipelineConversion(array $filters = []): array
    {
        $dateFrom = !empty($filters['dateFrom']) ? substr((string) $filters['dateFrom'], 0, 10) : null;
        $dateTo = !empty($filters['dateTo']) ? substr((string) $filters['dateTo'], 0, 10) : null;
        $leadSource = !empty($filters['leadSource']) ? (string) $filters['leadSource'] : null;
        $assignedUserId = !empty($filters['assignedUserId']) ? (string) $filters['assignedUserId'] : null;

        // 1. Cargar historial de etapas
        $historyRecords = $this->loadStageHistory();

        // 2. Indexar registros agrupados por etapa
        $stageOpportunities = [];
        $stageDurations = [];
        foreach (self::STAGES_PROGRESSION as $st) {
            $stageOpportunities[$st] = [];
            $stageDurations[$st] = [];
        }

        $allDistinctLeads = [];

        foreach ($historyRecords as $record) {
            if ($record->get('deleted')) {
                continue;
            }

            // Filtro por fecha de entrada en la etapa
            $enteredAt = (string) $record->get('enteredAt');
            $enteredDate = $enteredAt !== '' ? substr($enteredAt, 0, 10) : null;
            if (!$this->isDateInRange($enteredDate, $dateFrom, $dateTo)) {
                continue;
            }

            // Filtro por canal (leadSource)
            if ($leadSource !== null && (string) $record->get('leadSource') !== $leadSource) {
                continue;
            }

            // Filtro por asesor asignado
            if ($assignedUserId !== null && (string) $record->get('assignedUserId') !== $assignedUserId) {
                continue;
            }

            $oppId = (string) $record->get('opportunityId');
            $st = (string) $record->get('stage');

            if ($oppId === '' || !in_array($st, self::STAGES_PROGRESSION, true)) {
                continue;
            }

            $stageOpportunities[$st][$oppId] = true;
            $allDistinctLeads[$oppId] = true;

            // Recolectar duración en segundos para cálculo de percentiles
            $durationSeconds = $record->get('durationSeconds');
            if ($durationSeconds !== null && (float) $durationSeconds >= 0.0) {
                // Convertir a horas
                $stageDurations[$st][] = (float) $durationSeconds / 3600.0;
            }
        }

        // 3. Calcular conversión etapa a etapa
        $stagesData = [];
        $stagesCount = count(self::STAGES_PROGRESSION);

        for ($i = 0; $i < $stagesCount; $i++) {
            $currentStage = self::STAGES_PROGRESSION[$i];
            $count = count($stageOpportunities[$currentStage]);

            $durations = $stageDurations[$currentStage];
            sort($durations, SORT_NUMERIC);

            $medianHours = $this->calculatePercentile($durations, 50.0);
            $p90Hours = $this->calculatePercentile($durations, 90.0);

            if ($i === $stagesCount - 1) {
                // Etapa final (Closed Won)
                $conversionToNext = 100.0;
                $dropOffCount = 0;
                // En Closed Won la duración adicional es 0
                $medianHours = 0.0;
                $p90Hours = 0.0;
            } else {
                $nextStage = self::STAGES_PROGRESSION[$i + 1];
                $nextCount = count($stageOpportunities[$nextStage]);

                $conversionToNext = ($count > 0)
                    ? round(($nextCount / $count) * 100, 2)
                    : 0.0;

                $dropOffCount = max(0, $count - $nextCount);
            }

            $stagesData[] = [
                'stage' => $currentStage,
                'count' => $count,
                'conversionToNext' => $conversionToNext,
                'dropOffCount' => $dropOffCount,
                'medianDurationHours' => $medianHours,
                'p90DurationHours' => $p90Hours,
            ];
        }

        // 4. Calcular Resumen Global
        $totalLeads = count($stageOpportunities['Prospecting']);
        if ($totalLeads === 0 && !empty($allDistinctLeads)) {
            $totalLeads = count($allDistinctLeads);
        }
        $totalWon = count($stageOpportunities['Closed Won']);
        $overallConversionRate = ($totalLeads > 0)
            ? round(($totalWon / $totalLeads) * 100, 2)
            : 0.0;

        // 5. Analizar Motivos de Descarte (Lost Reasons)
        $dropOffReasons = $this->calculateDropOffReasons($filters);

        return [
            'summary' => [
                'totalLeads' => $totalLeads,
                'totalWon' => $totalWon,
                'overallConversionRate' => $overallConversionRate,
            ],
            'stages' => $stagesData,
            'dropOffReasons' => $dropOffReasons,
        ];
    }

    /**
     * Calcula la distribución de motivos de descarte para oportunidades en Closed Lost.
     */
    protected function calculateDropOffReasons(array $filters = []): array
    {
        $dateFrom = !empty($filters['dateFrom']) ? substr((string) $filters['dateFrom'], 0, 10) : null;
        $dateTo = !empty($filters['dateTo']) ? substr((string) $filters['dateTo'], 0, 10) : null;
        $leadSource = !empty($filters['leadSource']) ? (string) $filters['leadSource'] : null;
        $assignedUserId = !empty($filters['assignedUserId']) ? (string) $filters['assignedUserId'] : null;

        $opportunities = $this->loadOpportunities();

        $reasonCounts = [];
        $totalLostWithReason = 0;

        foreach ($opportunities as $opp) {
            if ($opp->get('deleted')) {
                continue;
            }

            if ((string) $opp->get('stage') !== 'Closed Lost') {
                continue;
            }

            // Filtro por fecha de creación o cierre
            $oppDate = (string) ($opp->get('closeDate') ?: substr((string) ($opp->get('createdAt') ?: ''), 0, 10));
            if (!$this->isDateInRange($oppDate, $dateFrom, $dateTo)) {
                continue;
            }

            // Filtro por canal
            if ($leadSource !== null && (string) $opp->get('leadSource') !== $leadSource) {
                continue;
            }

            // Filtro por asesor
            if ($assignedUserId !== null && (string) $opp->get('assignedUserId') !== $assignedUserId) {
                continue;
            }

            $reason = trim((string) ($opp->get('lostReason') ?: 'Otro'));
            if (!isset($reasonCounts[$reason])) {
                $reasonCounts[$reason] = 0;
            }
            $reasonCounts[$reason]++;
            $totalLostWithReason++;
        }

        $result = [];
        foreach ($reasonCounts as $reason => $cnt) {
            $percentage = ($totalLostWithReason > 0)
                ? round(($cnt / $totalLostWithReason) * 100, 2)
                : 0.0;

            $result[] = [
                'reason' => $reason,
                'count' => $cnt,
                'percentage' => $percentage,
            ];
        }

        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Calcula de forma exacta un percentil a partir de un arreglo ordenado
     * mediante interpolación lineal continua (estándar NumPy/Pandas/Excel).
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
     * Exporta el informe de conversión de embudo a formato CSV descargable con BOM UTF-8.
     */
    public function exportCsv(array $filters = []): string
    {
        $data = $this->getPipelineConversion($filters);

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");

        $this->writeCsvLine($fp, ['REPORTE DE CONVERSIÓN DE PIPELINE Y TIEMPOS DE ETAPA']);
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

        // 1. Resumen Ejecutivo
        $this->writeCsvLine($fp, ['--- 1. RESUMEN GLOBAL DEL EMBUDO ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Valor']);
        $this->writeCsvLine($fp, ['Total Leads en Prospección', $data['summary']['totalLeads']]);
        $this->writeCsvLine($fp, ['Total Oportunidades Ganadas', $data['summary']['totalWon']]);
        $this->writeCsvLine($fp, ['Tasa de Conversión Global', $data['summary']['overallConversionRate'] . '%']);
        $this->writeCsvLine($fp, []);

        // 2. Embudo por Etapa
        $this->writeCsvLine($fp, ['--- 2. CONVERSIÓN Y DURACIÓN POR ETAPA ---']);
        $this->writeCsvLine($fp, ['Etapa', 'Prospectos', 'Conversión al Siguiente (%)', 'Caídas (Drop-off)', 'Duración Mediana (h)', 'Duración P90 (h)']);
        foreach ($data['stages'] as $s) {
            $this->writeCsvLine($fp, [
                $s['stage'],
                $s['count'],
                $s['conversionToNext'] . '%',
                $s['dropOffCount'],
                $s['medianDurationHours'],
                $s['p90DurationHours'],
            ]);
        }
        $this->writeCsvLine($fp, []);

        // 3. Motivos de Descarte
        $this->writeCsvLine($fp, ['--- 3. MOTIVOS DE DESCARTE (DROP-OFF REASONS) ---']);
        $this->writeCsvLine($fp, ['Motivo de Pérdida', 'Cantidad', 'Porcentaje (%)']);
        foreach ($data['dropOffReasons'] as $r) {
            $this->writeCsvLine($fp, [
                $r['reason'],
                $r['count'],
                $r['percentage'] . '%',
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

    protected function loadStageHistory(): iterable
    {
        return $this->entityManager->getRDBRepository('OpportunityStageHistory')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadOpportunities(): iterable
    {
        return $this->entityManager->getRDBRepository('Opportunity')
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
