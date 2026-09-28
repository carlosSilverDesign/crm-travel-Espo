<?php

namespace Espo\Custom\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * RealProfitabilityService
 *
 * Servicio de Inteligencia Financiera para la Regla de Oro de Liquidación:
 * Margen Bruto Real = Ingresos Reconciliados (Cobros Confirmed) - Costos Confirmados de Operadores.
 *
 * Aislamiento Contable Estricto:
 * Cotizaciones y saldos por cobrar se segregan exclusivamente en 'unreconciledFunnel'
 * y jamás se computan como margen líquido real.
 */
class RealProfitabilityService
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Calcula la rentabilidad real consolidada y el embudo de cobros pendientes.
     *
     * @param array{
     *   dateFrom?: string|null,
     *   dateTo?: string|null,
     *   clientType?: string|null,
     *   destination?: string|null,
     *   supplierId?: string|null
     * } $filters
     * @return array
     */
    public function getRealProfitability(array $filters = []): array
    {
        $dateFrom = !empty($filters['dateFrom']) ? substr((string) $filters['dateFrom'], 0, 10) : null;
        $dateTo = !empty($filters['dateTo']) ? substr((string) $filters['dateTo'], 0, 10) : null;
        $clientTypeFilter = !empty($filters['clientType']) ? (string) $filters['clientType'] : null;
        $destinationFilter = !empty($filters['destination']) ? trim((string) $filters['destination']) : null;
        $supplierIdFilter = !empty($filters['supplierId']) ? (string) $filters['supplierId'] : null;

        // 1. Obtener entidades desde repositorios ORM
        $opportunities = $this->loadOpportunities();
        $payments = $this->loadPayments();
        $itinerarios = $this->loadItinerarios();
        $budgetLines = $this->loadBudgetLines();
        $suppliers = $this->loadSuppliers();

        // 2. Mapear proveedores: id => nombre
        $supplierNames = [];
        foreach ($suppliers as $sup) {
            if ($sup->get('deleted')) {
                continue;
            }
            $supplierNames[$sup->getId()] = (string) ($sup->get('name') ?: $sup->getId());
        }

        // 3. Indexar BudgetLines por itinerarioId
        $budgetLinesByItinId = [];
        $itinIdsWithSupplier = [];
        foreach ($budgetLines as $line) {
            if ($line->get('deleted')) {
                continue;
            }
            $itinId = (string) $line->get('itinerarioId');
            if ($itinId === '') {
                continue;
            }
            $lineSupId = (string) $line->get('supplierId');
            if ($supplierIdFilter !== null && $lineSupId !== $supplierIdFilter) {
                continue;
            }
            $budgetLinesByItinId[$itinId][] = $line;
            if ($lineSupId !== '') {
                $itinIdsWithSupplier[$itinId] = true;
            }
        }

        // 4. Indexar Itinerarios por opportunityId
        $itinerariosByOppId = [];
        $oppIdsWithSupplier = [];
        foreach ($itinerarios as $itin) {
            if ($itin->get('deleted')) {
                continue;
            }
            $oppId = (string) $itin->get('opportunityId');
            if ($oppId === '') {
                continue;
            }
            $itinerariosByOppId[$oppId][] = $itin;
            if (isset($itinIdsWithSupplier[$itin->getId()])) {
                $oppIdsWithSupplier[$oppId] = true;
            }
        }

        // 5. Indexar Pagos Confirmados por opportunityId
        $paymentsByOppId = [];
        foreach ($payments as $payment) {
            if ($payment->get('deleted') || $payment->get('status') !== 'Confirmed') {
                continue;
            }

            // Filtro por fecha de confirmación / pago
            $pDate = $this->extractPaymentDate($payment);
            if (!$this->isDateInRange($pDate, $dateFrom, $dateTo)) {
                continue;
            }

            $oppId = (string) $payment->get('opportunityId');
            if ($oppId === '') {
                continue;
            }
            $paymentsByOppId[$oppId][] = $payment;
        }

        // 6. Contenedores de agregación
        $totalReconciledIncome = 0.0;
        $totalConfirmedCost = 0.0;
        $confirmedBookingsCount = 0;

        $unreconciledPendingBalance = 0.0;
        $unconfirmedQuotesCost = 0.0;
        $pipelineBookingsCount = 0;

        $destMap = [];
        $supplierCostMap = [];
        $clientTypeMap = [
            'B2C_Direct' => ['income' => 0.0, 'cost' => 0.0],
            'B2B_Corporate' => ['income' => 0.0, 'cost' => 0.0],
        ];

        // 7. Procesar cada Oportunidad
        foreach ($opportunities as $opp) {
            if ($opp->get('deleted')) {
                continue;
            }

            $oppId = $opp->getId();
            $clientType = (string) ($opp->get('clientType') ?: 'B2C_Direct');
            if ($clientType !== 'B2B_Corporate') {
                $clientType = 'B2C_Direct';
            }

            // Filtro clientType
            if ($clientTypeFilter !== null && $clientType !== $clientTypeFilter) {
                continue;
            }

            // Determinar destino (prioridad Opportunity, fallback Itinerario)
            $destination = trim((string) ($opp->get('destination') ?: ''));
            if ($destination === '' && !empty($itinerariosByOppId[$oppId])) {
                foreach ($itinerariosByOppId[$oppId] as $itin) {
                    $itinDest = trim((string) ($itin->get('destination') ?: ''));
                    if ($itinDest !== '') {
                        $destination = $itinDest;
                        break;
                    }
                }
            }
            if ($destination === '') {
                $destination = 'Sin Destino';
            }

            // Filtro destination
            if ($destinationFilter !== null && stripos($destination, $destinationFilter) === false) {
                continue;
            }

            // Filtro supplierId a nivel oportunidad
            if ($supplierIdFilter !== null && empty($oppIdsWithSupplier[$oppId])) {
                continue;
            }

            $stage = (string) ($opp->get('stage') ?: 'Prospecting');
            $oppPayments = $paymentsByOppId[$oppId] ?? [];
            $oppItinerarios = $itinerariosByOppId[$oppId] ?? [];

            // A) Calcular Ingreso Reconciliado (Pagos Confirmados)
            $oppReconciledIncome = 0.0;
            foreach ($oppPayments as $p) {
                $oppReconciledIncome += (float) ($p->get('amount') ?? 0.0);
            }
            $oppReconciledIncome = round($oppReconciledIncome, 2);

            // B) Determinar si la reserva es confirmada (estrictamente con pagos reconciliados)
            $isConfirmedBooking = ($oppReconciledIncome > 0.0);

            // C) Calcular Costos Confirmados vs Cotizaciones en Trámite
            $oppConfirmedCost = 0.0;
            foreach ($oppItinerarios as $itin) {
                $itinStatus = (string) ($itin->get('status') ?: 'Cotización');
                $itinLines = $budgetLinesByItinId[$itin->getId()] ?? [];

                // Ignorar itinerarios cancelados o plantillas
                if ($itinStatus === 'Cancelado' || $itinStatus === 'Plantilla') {
                    continue;
                }

                $isItinConfirmed = in_array($itinStatus, ['Confirmado', 'En Viaje', 'Finalizado'], true)
                    || $isConfirmedBooking;

                foreach ($itinLines as $line) {
                    $supId = (string) $line->get('supplierId');
                    $cost = (float) ($line->get('costPrice') ?? 0.0);

                    if ($isItinConfirmed && $supId !== '') {
                        // Costo con proveedor confirmado
                        $oppConfirmedCost += $cost;

                        // Agregación por Proveedor
                        if (!isset($supplierCostMap[$supId])) {
                            $supplierCostMap[$supId] = [
                                'supplierId' => $supId,
                                'supplierName' => $supplierNames[$supId] ?? ('Proveedor ' . $supId),
                                'confirmedCost' => 0.0,
                                'itemsCount' => 0,
                            ];
                        }
                        $supplierCostMap[$supId]['confirmedCost'] += $cost;
                        $supplierCostMap[$supId]['itemsCount']++;
                    } elseif ($itinStatus === 'Cotización' && $stage !== 'Closed Lost') {
                        // Presupuesto no confirmado (dinero de papel)
                        $unconfirmedQuotesCost += $cost;
                    }
                }
            }
            $oppConfirmedCost = round($oppConfirmedCost, 2);

            // D) Si la reserva tiene cobros confirmados, computar en el P&L Real
            if ($isConfirmedBooking) {
                $totalReconciledIncome += $oppReconciledIncome;
                $totalConfirmedCost += $oppConfirmedCost;
                $confirmedBookingsCount++;

                // Agregación por Destino
                if (!isset($destMap[$destination])) {
                    $destMap[$destination] = [
                        'destination' => $destination,
                        'reconciledIncome' => 0.0,
                        'reconciledCost' => 0.0,
                    ];
                }
                $destMap[$destination]['reconciledIncome'] += $oppReconciledIncome;
                $destMap[$destination]['reconciledCost'] += $oppConfirmedCost;

                // Agregación por Tipo de Cliente
                $clientTypeMap[$clientType]['income'] += $oppReconciledIncome;
                $clientTypeMap[$clientType]['cost'] += $oppConfirmedCost;
            }

            // E) Embudo No Reconciliado (Saldos pendientes y pipeline activo)
            if ($stage !== 'Closed Lost') {
                $oppTotalAmount = (float) ($opp->get('amount') ?? 0.0);
                $pendingBalance = (float) ($opp->get('pendingBalance') ?? 0.0);

                if ($pendingBalance <= 0.0 && $oppTotalAmount > 0.0) {
                    $pendingBalance = max(0.0, $oppTotalAmount - $oppReconciledIncome);
                }

                if ($pendingBalance > 0.0) {
                    $unreconciledPendingBalance += $pendingBalance;
                }

                // Contar oportunidad activa en pipeline
                if (!in_array($stage, ['Closed Won', 'Closed Lost'], true) || $pendingBalance > 0.0) {
                    $pipelineBookingsCount++;
                }
            }
        }

        // 8. Construir respuesta final estructurada
        $totalReconciledIncome = round($totalReconciledIncome, 2);
        $totalConfirmedCost = round($totalConfirmedCost, 2);
        $grossProfit = round($totalReconciledIncome - $totalConfirmedCost, 2);
        $grossMarginRate = ($totalReconciledIncome > 0.0)
            ? round(($grossProfit / $totalReconciledIncome) * 100, 2)
            : 0.0;

        // Formatear desglose por Destino
        $byDestination = [];
        foreach ($destMap as $d) {
            $dIncome = round($d['reconciledIncome'], 2);
            $dCost = round($d['reconciledCost'], 2);
            $dProfit = round($dIncome - $dCost, 2);
            $dMarginRate = ($dIncome > 0.0) ? round(($dProfit / $dIncome) * 100, 2) : 0.0;

            $byDestination[] = [
                'destination' => $d['destination'],
                'reconciledIncome' => $dIncome,
                'reconciledCost' => $dCost,
                'grossProfit' => $dProfit,
                'marginRate' => $dMarginRate,
            ];
        }
        usort($byDestination, fn($a, $b) => $b['reconciledIncome'] <=> $a['reconciledIncome']);

        // Formatear desglose por Proveedor
        $bySupplier = array_values($supplierCostMap);
        foreach ($bySupplier as &$s) {
            $s['confirmedCost'] = round($s['confirmedCost'], 2);
        }
        unset($s);
        usort($bySupplier, fn($a, $b) => $b['confirmedCost'] <=> $a['confirmedCost']);

        // Formatear desglose por Tipo de Cliente
        $byClientType = [];
        foreach (['B2C_Direct', 'B2B_Corporate'] as $ct) {
            $ctIncome = round($clientTypeMap[$ct]['income'], 2);
            $ctCost = round($clientTypeMap[$ct]['cost'], 2);
            $ctProfit = round($ctIncome - $ctCost, 2);
            $ctMarginRate = ($ctIncome > 0.0) ? round(($ctProfit / $ctIncome) * 100, 2) : 0.0;

            $byClientType[$ct] = [
                'income' => $ctIncome,
                'profit' => $ctProfit,
                'marginRate' => $ctMarginRate,
            ];
        }

        return [
            'summary' => [
                'reconciledIncome' => $totalReconciledIncome,
                'confirmedCost' => $totalConfirmedCost,
                'grossMarginAmount' => $grossProfit,
                'grossMarginPercentage' => $grossMarginRate,
                'totalIncome' => $totalReconciledIncome,
                'totalCost' => $totalConfirmedCost,
                'grossProfit' => $grossProfit,
                'grossMarginRate' => $grossMarginRate,
                'confirmedBookingsCount' => $confirmedBookingsCount,
            ],
            'reconciled' => [
                'totalIncome' => $totalReconciledIncome,
                'totalCost' => $totalConfirmedCost,
                'grossProfit' => $grossProfit,
                'grossMarginRate' => $grossMarginRate,
                'confirmedBookingsCount' => $confirmedBookingsCount,
            ],
            'unreconciledFunnel' => [
                'pendingBalance' => round($unreconciledPendingBalance, 2),
                'unconfirmedQuotesCost' => round($unconfirmedQuotesCost, 2),
                'openQuoteCost' => round($unconfirmedQuotesCost, 2),
                'pipelineBookingsCount' => $pipelineBookingsCount,
            ],
            'byDestination' => $byDestination,
            'bySupplier' => $bySupplier,
            'byClientType' => $byClientType,
        ];
    }

    /**
     * Exporta el informe analítico completo a formato CSV con codificación UTF-8 (BOM).
     */
    public function exportCsv(array $filters = []): string
    {
        $data = $this->getRealProfitability($filters);

        $fp = fopen('php://temp', 'r+');
        // Escribir BOM UTF-8 para visualización correcta de tildes en MS Excel
        fwrite($fp, "\xEF\xBB\xBF");

        $this->writeCsvLine($fp, ['REPORTE DE RENTABILIDAD REAL LIQUIDADA (CRM VIAJES)']);
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

        // 1. Resumen Reconciliado
        $this->writeCsvLine($fp, ['--- 1. RESUMEN EJECUTIVO (RECONCILIADO EN BANCO) ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Monto / Valor', 'Criterio de Validación']);
        $this->writeCsvLine($fp, ['Ingresos Reconciliados', number_format($data['reconciled']['totalIncome'], 2, '.', ''), 'Cobros con status Confirmed']);
        $this->writeCsvLine($fp, ['Costos Confirmados Operadores', number_format($data['reconciled']['totalCost'], 2, '.', ''), 'Costos con itinerario/reserva confirmada']);
        $this->writeCsvLine($fp, ['Margen Bruto Real', number_format($data['reconciled']['grossProfit'], 2, '.', ''), 'Ingresos Reconciliados - Costos Confirmados']);
        $this->writeCsvLine($fp, ['Margen Bruto (%)', $data['reconciled']['grossMarginRate'] . '%', 'Rentabilidad real efectiva sobre cobros']);
        $this->writeCsvLine($fp, ['Reservas Confirmadas', $data['reconciled']['confirmedBookingsCount'], 'Expedientes con transferencias confirmadas']);
        $this->writeCsvLine($fp, []);

        // 2. Embudo No Reconciliado
        $this->writeCsvLine($fp, ['--- 2. DINERO EN TRÁMITE (EMBUDO NO RECONCILIADO) ---']);
        $this->writeCsvLine($fp, ['Métrica', 'Monto / Valor', 'Riesgo / Estado']);
        $this->writeCsvLine($fp, ['Saldo Pendiente de Cobro', number_format($data['unreconciledFunnel']['pendingBalance'], 2, '.', ''), 'Compromiso comercial aún no acreditado en banco']);
        $this->writeCsvLine($fp, ['Costos en Cotizaciones en Trámite', number_format($data['unreconciledFunnel']['unconfirmedQuotesCost'], 2, '.', ''), 'Costos presupuestados en cotizaciones abiertas']);
        $this->writeCsvLine($fp, ['Reservas en Pipeline', $data['unreconciledFunnel']['pipelineBookingsCount'], 'Oportunidades activas en embudo']);
        $this->writeCsvLine($fp, []);

        // 3. Rentabilidad por Destino
        $this->writeCsvLine($fp, ['--- 3. RENTABILIDAD POR DESTINO ---']);
        $this->writeCsvLine($fp, ['Destino', 'Ingreso Reconciliado', 'Costo Confirmado', 'Margen Bruto Real', 'Margen (%)']);
        foreach ($data['byDestination'] as $d) {
            $this->writeCsvLine($fp, [
                $d['destination'],
                number_format($d['reconciledIncome'], 2, '.', ''),
                number_format($d['reconciledCost'], 2, '.', ''),
                number_format($d['grossProfit'], 2, '.', ''),
                $d['marginRate'] . '%',
            ]);
        }
        $this->writeCsvLine($fp, []);

        // 4. Costos por Proveedor
        $this->writeCsvLine($fp, ['--- 4. COSTOS CONSOLIDADOS POR OPERADOR / PROVEEDOR ---']);
        $this->writeCsvLine($fp, ['ID Operador', 'Nombre Operador', 'Costo Confirmado', 'Cantidad Servicios']);
        foreach ($data['bySupplier'] as $s) {
            $this->writeCsvLine($fp, [
                $s['supplierId'],
                $s['supplierName'],
                number_format($s['confirmedCost'], 2, '.', ''),
                $s['itemsCount'],
            ]);
        }
        $this->writeCsvLine($fp, []);

        // 5. Segmentación por Tipo de Cliente
        $this->writeCsvLine($fp, ['--- 5. RENTABILIDAD POR TIPO DE CLIENTE ---']);
        $this->writeCsvLine($fp, ['Tipo de Cliente', 'Ingreso Reconciliado', 'Margen Bruto Real', 'Margen (%)']);
        foreach ($data['byClientType'] as $type => $ct) {
            $label = ($type === 'B2C_Direct') ? 'B2C - Viajero Directo' : 'B2B - Corporativo';
            $this->writeCsvLine($fp, [
                $label,
                number_format($ct['income'], 2, '.', ''),
                number_format($ct['profit'], 2, '.', ''),
                $ct['marginRate'] . '%',
            ]);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /**
     * Exporta la tabla de destinos como CSV tabular simple.
     */
    public function exportDestinationsCsv(array $filters = []): string
    {
        $data = $this->getRealProfitability($filters);

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");
        $this->writeCsvLine($fp, ['Destino', 'Ingreso Reconciliado', 'Costo Confirmado', 'Margen Bruto Real', 'Margen (%)']);
        foreach ($data['byDestination'] as $d) {
            $this->writeCsvLine($fp, [
                $d['destination'],
                number_format($d['reconciledIncome'], 2, '.', ''),
                number_format($d['reconciledCost'], 2, '.', ''),
                number_format($d['grossProfit'], 2, '.', ''),
                $d['marginRate'] . '%',
            ]);
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /**
     * Exporta la tabla de proveedores como CSV tabular simple.
     */
    public function exportSuppliersCsv(array $filters = []): string
    {
        $data = $this->getRealProfitability($filters);

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");
        $this->writeCsvLine($fp, ['ID Operador', 'Nombre Operador', 'Costo Confirmado', 'Cantidad Servicios']);
        foreach ($data['bySupplier'] as $s) {
            $this->writeCsvLine($fp, [
                $s['supplierId'],
                $s['supplierName'],
                number_format($s['confirmedCost'], 2, '.', ''),
                $s['itemsCount'],
            ]);
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /**
     * Escribe una línea CSV garantizando compatibilidad con PHP 8.4 (parámetro escape explícito).
     */
    protected function writeCsvLine($fp, array $fields): void
    {
        fputcsv($fp, $fields, ',', '"', '\\');
    }

    // =========================================================================
    // Métodos Auxiliares y Carga de Repositorios
    // =========================================================================

    protected function loadOpportunities(): iterable
    {
        return $this->entityManager->getRDBRepository('Opportunity')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadPayments(): iterable
    {
        return $this->entityManager->getRDBRepository('Payment')
            ->where([
                'status' => 'Confirmed',
                'deleted' => false,
            ])
            ->find();
    }

    protected function loadItinerarios(): iterable
    {
        return $this->entityManager->getRDBRepository('Itinerario')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadBudgetLines(): iterable
    {
        return $this->entityManager->getRDBRepository('BudgetLine')
            ->where(['deleted' => false])
            ->find();
    }

    protected function loadSuppliers(): iterable
    {
        return $this->entityManager->getRDBRepository('Supplier')
            ->where(['deleted' => false])
            ->find();
    }

    protected function extractPaymentDate(Entity $payment): ?string
    {
        $verifiedAt = $payment->get('verifiedAt');
        if (!empty($verifiedAt)) {
            return substr((string) $verifiedAt, 0, 10);
        }

        $declaredDate = $payment->get('clientDeclaredDate');
        if (!empty($declaredDate)) {
            return substr((string) $declaredDate, 0, 10);
        }

        $createdAt = $payment->get('createdAt');
        if (!empty($createdAt)) {
            return substr((string) $createdAt, 0, 10);
        }

        return null;
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
