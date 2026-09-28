/**
 * Dashlet: RealProfitability (TASK-053)
 * Rentabilidad Real Consolidada (Ingresos Reconciliados vs Costos Confirmados de Operador)
 * con desglose visual por destino en HTML/CSS nativo, exportación CSV y Empty State.
 */
(function () {
    'use strict';

    function initDashlet(BaseView) {
        var RealProfitabilityView = BaseView.extend({
            templateContent: `
                <div class="real-profitability-dashlet" style="padding: 10px;">
                    <!-- Barra de Controles y Filtros -->
                    <div class="dashlet-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div class="btn-group btn-group-xs date-filter-group" role="group">
                            <button type="button" class="btn btn-default filter-btn active" data-range="currentMonth">Este Mes</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentQuarter">Trimestre</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentYear">Año</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="ever">Todo</button>
                        </div>
                        <div class="btn-group btn-group-xs" role="group">
                            <button type="button" class="btn btn-default btn-xs download-csv-btn" title="Descargar reporte en Excel / CSV">
                                <i class="fas fa-file-csv text-success"></i> Descargar CSV
                            </button>
                        </div>
                    </div>

                    <!-- Tarjetas de Métricas Clave (Margen Bruto Real) -->
                    <div class="kpi-summary-row" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px;">
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Ingresos Reconciliados</div>
                            <div class="kpi-reconciled-income" style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">$0.00</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Costos Confirmados</div>
                            <div class="kpi-confirmed-cost" style="font-size: 16px; font-weight: 800; color: #dc2626; margin-top: 2px;">$0.00</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Margen Bruto Real</div>
                            <div class="kpi-gross-margin" style="font-size: 16px; font-weight: 800; color: #16a34a; margin-top: 2px;">$0.00</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Margen (%)</div>
                            <div class="kpi-margin-pct" style="font-size: 16px; font-weight: 800; color: #2563eb; margin-top: 2px;">0.00%</div>
                        </div>
                    </div>

                    <!-- Banner de Embudo No Reconciliado (Principio Contable de Prudencia) -->
                    <div class="unreconciled-funnel-banner" style="background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; font-size: 11px; color: #92400e; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <i class="fas fa-exclamation-triangle" style="margin-right: 5px;"></i>
                            <strong>Embudo No Reconciliado:</strong> Saldo Cobrable Pendiente: <span class="unrec-pending font-bold" style="font-weight: 700;">$0.00</span> | Costo Cotizado en Negociación: <span class="unrec-quote font-bold" style="font-weight: 700;">$0.00</span>
                        </div>
                        <span class="badge" style="background: #f59e0b; color: #fff; font-size: 10px;">Fuera de Contabilidad Real</span>
                    </div>

                    <!-- Visualización de Rentabilidad por Destino -->
                    <div class="chart-container" style="width: 100%; min-height: 200px; position: relative;">
                        <div class="chart-loading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.7); z-index: 10;">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        </div>
                        <div class="chart-empty-state" style="display: none; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 25px; text-align: center;">
                            <i class="fas fa-coins text-muted fa-3x" style="margin-bottom: 12px; color: #94a3b8;"></i>
                            <h5 style="margin: 0 0 6px 0; color: #334155; font-weight: 700;">Sin transacciones reconciliadas</h5>
                            <p class="text-muted" style="font-size: 12px; margin: 0; max-width: 360px;">
                                La contabilidad real solo registra cobros con estado <strong>Confirmado</strong> y líneas de costo emitidas. No se encontraron movimientos en este rango.
                            </p>
                        </div>
                        <div class="destinations-bars-container" style="display: flex; flex-direction: column; gap: 10px; padding: 6px 0;"></div>
                    </div>
                </div>
            `,

            events: {
                'click .filter-btn': 'onChangeDateFilter',
                'click .download-csv-btn': 'onDownloadCsv'
            },

            currentRange: 'currentYear',

            setup: function () {
                BaseView.prototype.setup.call(this);
                this.currentRange = this.getOption('dateFilter') || 'currentYear';
            },

            afterRender: function () {
                BaseView.prototype.afterRender.call(this);
                this.$el.find('.filter-btn').removeClass('active');
                this.$el.find('.filter-btn[data-range="' + this.currentRange + '"]').addClass('active');

                this.loadDataAndRender();
            },

            onChangeDateFilter: function (e) {
                var range = $(e.currentTarget).data('range');
                if (!range || range === this.currentRange) return;

                this.currentRange = range;
                this.$el.find('.filter-btn').removeClass('active');
                $(e.currentTarget).addClass('active');

                this.loadDataAndRender();
            },

            onDownloadCsv: function () {
                var dates = this.computeDateRange(this.currentRange);
                var url = 'api/v1/Analytics/export-real-profitability';
                var params = [];
                if (dates.dateFrom) params.push('dateFrom=' + encodeURIComponent(dates.dateFrom));
                if (dates.dateTo) params.push('dateTo=' + encodeURIComponent(dates.dateTo));
                if (params.length) url += '?' + params.join('&');

                window.open(url, '_blank');
            },

            computeDateRange: function (range) {
                var now = new Date();
                var y = now.getFullYear();
                var m = now.getMonth();
                var pad = function (n) { return (n < 10 ? '0' : '') + n; };

                if (range === 'currentMonth') {
                    var lastDay = new Date(y, m + 1, 0).getDate();
                    return {
                        dateFrom: y + '-' + pad(m + 1) + '-01',
                        dateTo: y + '-' + pad(m + 1) + '-' + pad(lastDay)
                    };
                }
                if (range === 'currentQuarter') {
                    var qMonth = Math.floor(m / 3) * 3;
                    var qEnd = new Date(y, qMonth + 3, 0).getDate();
                    return {
                        dateFrom: y + '-' + pad(qMonth + 1) + '-01',
                        dateTo: y + '-' + pad(qMonth + 3) + '-' + pad(qEnd)
                    };
                }
                if (range === 'currentYear') {
                    return {
                        dateFrom: y + '-01-01',
                        dateTo: y + '-12-31'
                    };
                }
                return {};
            },

            formatMoney: function (amount) {
                var val = Number(amount) || 0;
                return '$' + val.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            loadDataAndRender: function () {
                var self = this;
                var $loading = this.$el.find('.chart-loading');
                var $empty = this.$el.find('.chart-empty-state');
                var $bars = this.$el.find('.destinations-bars-container');

                $loading.show();
                $empty.hide();
                $bars.empty();

                var dates = this.computeDateRange(this.currentRange);

                Espo.Ajax.getRequest('Analytics/real-profitability', dates)
                    .then(function (data) {
                        $loading.hide();
                        var summary = data.summary || {};
                        var destinations = data.byDestination || [];
                        var hasData = destinations.length > 0 && (Number(summary.reconciledIncome || 0) > 0 || Number(summary.confirmedCost || 0) > 0);

                        self.renderKpis(summary, data.unreconciledFunnel || {});

                        if (!hasData) {
                            $empty.css('display', 'flex');
                            $bars.hide();
                        } else {
                            $empty.hide();
                            $bars.show();
                            self.renderDestinations(destinations);
                        }
                    })
                    .catch(function () {
                        $loading.hide();
                        $empty.css('display', 'flex');
                        $bars.hide();
                    });
            },

            renderKpis: function (summary, unreconciled) {
                this.$el.find('.kpi-reconciled-income').text(this.formatMoney(summary.reconciledIncome));
                this.$el.find('.kpi-confirmed-cost').text(this.formatMoney(summary.confirmedCost));
                this.$el.find('.kpi-gross-margin').text(this.formatMoney(summary.grossMarginAmount));
                this.$el.find('.kpi-margin-pct').text((summary.grossMarginPercentage || 0) + '%');

                this.$el.find('.unrec-pending').text(this.formatMoney(unreconciled.pendingBalance));
                this.$el.find('.unrec-quote').text(this.formatMoney(unreconciled.openQuoteCost));
            },

            renderDestinations: function (destinations) {
                var self = this;
                var $container = this.$el.find('.destinations-bars-container');
                $container.empty();

                var topDestinations = destinations.slice(0, 6);
                var maxIncome = Math.max.apply(Math, topDestinations.map(function (d) { return Number(d.reconciledIncome || 0); })) || 1;

                topDestinations.forEach(function (d) {
                    var income = Number(d.reconciledIncome || 0);
                    var cost = Number(d.confirmedCost || 0);
                    var margin = Number(d.grossMarginAmount || 0);
                    var marginPct = income > 0 ? ((margin / income) * 100).toFixed(1) : 0;
                    var incomePct = Math.min(100, Math.max(5, (income / maxIncome) * 100)).toFixed(0);
                    var costPct = Math.min(100, Math.max(5, (cost / maxIncome) * 100)).toFixed(0);

                    var row = $(
                        '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px;">' +
                            '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">' +
                                '<span style="font-weight: 700; color: #1e293b; font-size: 12px;"><i class="fas fa-map-marker-alt text-danger" style="margin-right: 4px;"></i> ' + (d.destination || 'Sin destino') + '</span>' +
                                '<span class="badge" style="background: ' + (margin >= 0 ? '#10b981' : '#ef4444') + '; color: #fff; font-weight: 700; font-size: 11px;">Margen: ' + self.formatMoney(margin) + ' (' + marginPct + '%)</span>' +
                            '</div>' +
                            '<div style="display: flex; flex-direction: column; gap: 3px;">' +
                                '<div style="display: flex; align-items: center; gap: 8px; font-size: 11px;">' +
                                    '<span style="width: 60px; color: #3b82f6; font-weight: 600;">Ingreso:</span>' +
                                    '<div style="flex: 1; background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">' +
                                        '<div style="background: #3b82f6; width: ' + incomePct + '%; height: 100%; border-radius: 4px;"></div>' +
                                    '</div>' +
                                    '<span style="font-weight: 600; color: #334155; width: 70px; text-align: right;">' + self.formatMoney(income) + '</span>' +
                                '</div>' +
                                '<div style="display: flex; align-items: center; gap: 8px; font-size: 11px;">' +
                                    '<span style="width: 60px; color: #ef4444; font-weight: 600;">Costo:</span>' +
                                    '<div style="flex: 1; background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden;">' +
                                        '<div style="background: #ef4444; width: ' + costPct + '%; height: 100%; border-radius: 4px;"></div>' +
                                    '</div>' +
                                    '<span style="font-weight: 600; color: #334155; width: 70px; text-align: right;">' + self.formatMoney(cost) + '</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>'
                    );
                    $container.append(row);
                });
            }
        });

        return RealProfitabilityView;
    }

    if (typeof define === 'function' && define.amd) {
        define('custom:views/dashlets/real-profitability', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
        define('custom:modules/travel/views/dashlets/real-profitability', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
    }
})();
