/**
 * Dashlet: PipelineFunnel (TASK-053)
 * Embudo de Conversión de Pipeline y Tiempos de Etapa con visualización HTML/CSS nativa y Empty States claros.
 */
(function () {
    'use strict';

    function initDashlet(BaseView) {
        var PipelineFunnelView = BaseView.extend({
            templateContent: `
                <div class="pipeline-funnel-dashlet" style="padding: 10px;">
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

                    <div class="kpi-summary-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px;">
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">Total Leads</div>
                            <div class="kpi-total-leads" style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 2px;">0</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">Ventas Ganadas</div>
                            <div class="kpi-total-won" style="font-size: 20px; font-weight: 700; color: #16a34a; margin-top: 2px;">0</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">Conversión Global</div>
                            <div class="kpi-conversion-rate" style="font-size: 20px; font-weight: 700; color: #2563eb; margin-top: 2px;">0%</div>
                        </div>
                    </div>

                    <div class="chart-container" style="width: 100%; min-height: 220px; position: relative;">
                        <div class="chart-loading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.7); z-index: 10;">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        </div>
                        <div class="chart-empty-state" style="display: none; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 25px; text-align: center;">
                            <i class="fas fa-filter text-muted fa-3x" style="margin-bottom: 12px; color: #94a3b8;"></i>
                            <h5 style="margin: 0 0 6px 0; color: #334155; font-weight: 700;">Sin oportunidades en este período</h5>
                            <p class="text-muted" style="font-size: 12px; margin: 0; max-width: 320px;">
                                No se encontraron prospectos o ventas registradas en el rango de fechas seleccionado. Pruebe ampliando el filtro a <strong>Año</strong> o <strong>Todo</strong>.
                            </p>
                        </div>
                        <div class="funnel-bars-container" style="display: flex; flex-direction: column; gap: 10px; padding: 10px 0;"></div>
                    </div>

                    <div class="dropoff-section" style="margin-top: 14px; border-top: 1px solid #f1f5f9; padding-top: 10px;">
                        <div style="font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                            <i class="fas fa-filter text-muted"></i> Motivos de Descarte (Closed Lost)
                        </div>
                        <div class="dropoff-reasons-list" style="font-size: 12px; display: flex; flex-direction: column; gap: 4px;">
                            <span class="text-muted small">Sin motivos registrados</span>
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
                var url = 'api/v1/Analytics/export-pipeline-conversion';
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

            loadDataAndRender: function () {
                var self = this;
                var $loading = this.$el.find('.chart-loading');
                var $empty = this.$el.find('.chart-empty-state');
                var $bars = this.$el.find('.funnel-bars-container');

                $loading.show();
                $empty.hide();
                $bars.empty();

                var dates = this.computeDateRange(this.currentRange);

                Espo.Ajax.getRequest('Analytics/pipeline-conversion', dates)
                    .then(function (data) {
                        $loading.hide();
                        var totalLeads = (data.summary && data.summary.totalLeads) || 0;
                        var stages = data.stages || [];
                        var hasData = totalLeads > 0 || stages.some(function (s) { return s.count > 0; });

                        self.renderKpis(data.summary || {});

                        if (!hasData) {
                            $empty.css('display', 'flex');
                            $bars.hide();
                        } else {
                            $empty.hide();
                            $bars.show();
                            self.renderFunnelBars(stages, totalLeads);
                        }

                        self.renderDropOff(data.dropOffReasons || []);
                    })
                    .catch(function () {
                        $loading.hide();
                        $empty.css('display', 'flex');
                        $bars.hide();
                    });
            },

            renderKpis: function (summary) {
                this.$el.find('.kpi-total-leads').text(summary.totalLeads || 0);
                this.$el.find('.kpi-total-won').text(summary.totalWon || 0);
                this.$el.find('.kpi-conversion-rate').text((summary.overallConversionRate || 0) + '%');
            },

            renderFunnelBars: function (stages, totalLeads) {
                var $container = this.$el.find('.funnel-bars-container');
                $container.empty();

                var maxCount = Math.max.apply(Math, stages.map(function (s) { return s.count; })) || 1;

                var colors = ['#0284c7', '#0284c7', '#0d9488', '#16a34a', '#dc2626'];

                stages.forEach(function (s, idx) {
                    var pct = maxCount > 0 ? ((s.count / maxCount) * 100).toFixed(0) : 0;
                    var color = colors[idx % colors.length];

                    var row = $(
                        '<div style="display: flex; flex-direction: column; gap: 4px;">' +
                            '<div style="display: flex; justify-content: space-between; font-size: 12px; font-weight: 600; color: #334155;">' +
                                '<span>' + s.stage + '</span>' +
                                '<span><strong>' + s.count + '</strong> (' + s.conversionToNext + '% avance)</span>' +
                            '</div>' +
                            '<div style="background: #e2e8f0; border-radius: 4px; height: 14px; overflow: hidden;">' +
                                '<div style="background: ' + color + '; width: ' + Math.max(pct, 4) + '%; height: 100%; border-radius: 4px; transition: width 0.4s ease;"></div>' +
                            '</div>' +
                        '</div>'
                    );
                    $container.append(row);
                });
            },

            renderDropOff: function (reasons) {
                var $container = this.$el.find('.dropoff-reasons-list');
                $container.empty();

                if (!reasons || !reasons.length) {
                    $container.html('<span class="text-muted small">Cero abandonos registrados en este período.</span>');
                    return;
                }

                reasons.slice(0, 4).forEach(function (r) {
                    var bar = $(
                        '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">' +
                            '<span style="color:#475569; font-weight:500;">' + r.reason + '</span>' +
                            '<span class="badge" style="background:#f1f5f9; color:#0f172a; font-weight:700;">' + r.count + ' (' + r.percentage + '%)</span>' +
                        '</div>'
                    );
                    $container.append(bar);
                });
            }
        });

        return PipelineFunnelView;
    }

    if (typeof define === 'function' && define.amd) {
        define('custom:views/dashlets/pipeline-funnel', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
        define('custom:modules/travel/views/dashlets/pipeline-funnel', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
    }
})();
