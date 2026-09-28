/**
 * Dashlet: DestinationQuality (TASK-053)
 * Calidad en Destino y Satisfacción del Viajero: Tacómetro NPS (-100 a +100) en SVG nativo,
 * Costo Financiero de Contingencias, Cumplimiento de SLA de Detractores (< 2 horas) y Empty State.
 */
(function () {
    'use strict';

    function initDashlet(BaseView) {
        var DestinationQualityView = BaseView.extend({
            templateContent: `
                <div class="destination-quality-dashlet" style="padding: 10px;">
                    <!-- Toolbar de Filtros -->
                    <div class="dashlet-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div class="btn-group btn-group-xs date-filter-group" role="group">
                            <button type="button" class="btn btn-default filter-btn active" data-range="currentMonth">Este Mes</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentQuarter">Trimestre</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentYear">Año</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="ever">Todo</button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-default btn-xs download-csv-btn" title="Descargar reporte en Excel / CSV">
                                <i class="fas fa-file-csv text-success"></i> Descargar CSV
                            </button>
                        </div>
                    </div>

                    <!-- Métricas de Impacto Operativo y Calidad -->
                    <div class="kpi-summary-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px;">
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Encuestas NPS</div>
                            <div class="kpi-total-surveys" style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">0</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Costo Incidentes</div>
                            <div class="kpi-contingency-cost" style="font-size: 16px; font-weight: 800; color: #dc2626; margin-top: 2px;">$0.00</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">SLA Detractores (&lt;2h)</div>
                            <div class="kpi-sla-compliance" style="font-size: 16px; font-weight: 800; color: #16a34a; margin-top: 2px;">100%</div>
                        </div>
                    </div>

                    <!-- Fila Principal: Gauge NPS a la izquierda, Desglose a la derecha -->
                    <div class="nps-main-section" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; align-items: center;">
                        <!-- Gauge SVG de NPS -->
                        <div class="gauge-container" style="width: 100%; height: 160px; position: relative; display: flex; align-items: center; justify-content: center;">
                            <div class="chart-loading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.7); z-index: 10;">
                                <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                            </div>
                            <div class="chart-empty-state" style="display: none; position: absolute; inset: 0; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 15px; text-align: center; z-index: 5;">
                                <i class="fas fa-poll text-muted fa-2x" style="margin-bottom: 8px; color: #94a3b8;"></i>
                                <strong style="font-size: 12px; color: #334155;">Sin encuestas NPS registradas</strong>
                                <span class="text-muted" style="font-size: 11px; margin-top: 4px;">Se recopilan automáticamente 24h tras finalizar el viaje.</span>
                            </div>
                            <div class="nps-svg-wrapper" style="width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center;"></div>
                        </div>

                        <!-- Desglose de Clasificación NPS -->
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 10px;">
                                <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; color: #166534;">
                                    <span><i class="fas fa-smile text-success"></i> Promotores (9-10)</span>
                                    <span class="nps-promoters-stat">0 (0%)</span>
                                </div>
                            </div>
                            <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 6px; padding: 8px 10px;">
                                <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; color: #854d0e;">
                                    <span><i class="fas fa-meh text-warning"></i> Pasivos (7-8)</span>
                                    <span class="nps-passives-stat">0 (0%)</span>
                                </div>
                            </div>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 8px 10px;">
                                <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 600; color: #991b1b;">
                                    <span><i class="fas fa-frown text-danger"></i> Detractores (0-6)</span>
                                    <span class="nps-detractors-stat">0 (0%)</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Desglose de Contingencias Operativas por Severidad -->
                    <div class="contingencies-breakdown" style="border-top: 1px solid #e2e8f0; padding-top: 10px;">
                        <div style="font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                            <i class="fas fa-shield-alt text-muted"></i> Incidentes Operativos en Destino
                        </div>
                        <div class="contingencies-badges-row" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; text-align: center; font-size: 11px;">
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px;">
                                <div style="color: #64748b; font-size: 10px;">Baja</div>
                                <strong class="badge-baja font-bold">0 ($0.00)</strong>
                            </div>
                            <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 4px; padding: 6px;">
                                <div style="color: #854d0e; font-size: 10px;">Media</div>
                                <strong class="badge-media font-bold">0 ($0.00)</strong>
                            </div>
                            <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; padding: 6px;">
                                <div style="color: #9a3412; font-size: 10px;">Alta</div>
                                <strong class="badge-alta font-bold">0 ($0.00)</strong>
                            </div>
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 6px;">
                                <div style="color: #991b1b; font-size: 10px;">Crítica</div>
                                <strong class="badge-critica font-bold">0 ($0.00)</strong>
                            </div>
                        </div>
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
                var url = 'api/v1/Analytics/export-destination-quality';
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
                var $svgWrapper = this.$el.find('.nps-svg-wrapper');

                $loading.show();
                $empty.hide();
                $svgWrapper.empty();

                var dates = this.computeDateRange(this.currentRange);

                Espo.Ajax.getRequest('Analytics/destination-quality', dates)
                    .then(function (data) {
                        $loading.hide();
                        var nps = data.nps || {};
                        var totalSurveys = nps.totalSurveys || 0;

                        self.renderKpis(nps, data.contingencies || {}, data.detractorSla || {});

                        if (totalSurveys === 0) {
                            $empty.css('display', 'flex');
                            $svgWrapper.hide();
                        } else {
                            $empty.hide();
                            $svgWrapper.show();
                            self.renderGauge(nps.score || 0);
                        }

                        self.renderContingencies(data.contingencies ? data.contingencies.bySeverity : {});
                    })
                    .catch(function () {
                        $loading.hide();
                        $empty.css('display', 'flex');
                        $svgWrapper.hide();
                    });
            },

            renderKpis: function (nps, contingencies, detractorSla) {
                this.$el.find('.kpi-total-surveys').text(nps.totalSurveys || 0);
                this.$el.find('.kpi-contingency-cost').text(this.formatMoney(contingencies.totalContingencyCost));
                this.$el.find('.kpi-sla-compliance').text((detractorSla.slaCompliancePercentage || 100) + '%');

                this.$el.find('.nps-promoters-stat').text((nps.promotersCount || 0) + ' (' + (nps.promotersPercentage || 0) + '%)');
                this.$el.find('.nps-passives-stat').text((nps.passivesCount || 0) + ' (' + (nps.passivesPercentage || 0) + '%)');
                this.$el.find('.nps-detractors-stat').text((nps.detractorsCount || 0) + ' (' + (nps.detractorsPercentage || 0) + '%)');
            },

            renderGauge: function (npsScore) {
                var $wrapper = this.$el.find('.nps-svg-wrapper');
                $wrapper.empty();

                var score = Math.max(-100, Math.min(100, Math.round(npsScore)));
                var color = score >= 50 ? '#10b981' : (score >= 0 ? '#f59e0b' : '#ef4444');
                var label = score > 0 ? ('+' + score) : score.toString();

                var gaugeHtml = $(
                    '<div style="text-align: center;">' +
                        '<div style="font-size: 34px; font-weight: 900; color: ' + color + '; line-height: 1;">' + label + '</div>' +
                        '<div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; margin-top: 4px;">NPS Global</div>' +
                        '<div style="display: flex; gap: 4px; justify-content: center; margin-top: 8px;">' +
                            '<span style="background: #ef4444; width: 30px; height: 6px; border-radius: 3px;" title="Detractores (-100 a 0)"></span>' +
                            '<span style="background: #f59e0b; width: 30px; height: 6px; border-radius: 3px;" title="Pasivos (0 a 50)"></span>' +
                            '<span style="background: #10b981; width: 30px; height: 6px; border-radius: 3px;" title="Promotores (50 a 100)"></span>' +
                        '</div>' +
                    '</div>'
                );

                $wrapper.append(gaugeHtml);
            },

            renderContingencies: function (bySeverity) {
                var self = this;
                var baja = (bySeverity && bySeverity.Baja) || { count: 0, cost: 0 };
                var media = (bySeverity && bySeverity.Media) || { count: 0, cost: 0 };
                var alta = (bySeverity && bySeverity.Alta) || { count: 0, cost: 0 };
                var critica = (bySeverity && bySeverity.Critica) || { count: 0, cost: 0 };

                this.$el.find('.badge-baja').text(baja.count + ' (' + self.formatMoney(baja.cost) + ')');
                this.$el.find('.badge-media').text(media.count + ' (' + self.formatMoney(media.cost) + ')');
                this.$el.find('.badge-alta').text(alta.count + ' (' + self.formatMoney(alta.cost) + ')');
                this.$el.find('.badge-critica').text(critica.count + ' (' + self.formatMoney(critica.cost) + ')');
            }
        });

        return DestinationQualityView;
    }

    if (typeof define === 'function' && define.amd) {
        define('custom:views/dashlets/destination-quality', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
        define('custom:modules/travel/views/dashlets/destination-quality', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
    }
})();
