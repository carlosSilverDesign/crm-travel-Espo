/**
 * Dashlet: AgentPerformance (TASK-053)
 * Rendimiento de Asesores de Viajes: Tiempo de Primera Respuesta (FRT Mediana y P90),
 * Tasa de Conversión, Cumplimiento de Horario Laboral y visualización HTML nativa.
 */
(function () {
    'use strict';

    function initDashlet(BaseView) {
        var AgentPerformanceView = BaseView.extend({
            templateContent: `
                <div class="agent-performance-dashlet" style="padding: 10px;">
                    <!-- Toolbar de Filtros -->
                    <div class="dashlet-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div class="btn-group btn-group-xs date-filter-group" role="group">
                            <button type="button" class="btn btn-default filter-btn active" data-range="currentMonth">Este Mes</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentQuarter">Trimestre</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="currentYear">Año</button>
                            <button type="button" class="btn btn-default filter-btn" data-range="ever">Todo</button>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="font-size: 11px; margin-bottom: 0; cursor: pointer; user-select: none; font-weight: 500; color: #475569;">
                                <input type="checkbox" class="business-hours-toggle" checked style="margin-right: 4px; vertical-align: middle;">
                                Horario Laboral (L-V 09:00 - 18:00)
                            </label>
                            <button type="button" class="btn btn-default btn-xs download-csv-btn" title="Descargar reporte en Excel / CSV">
                                <i class="fas fa-file-csv text-success"></i> Descargar CSV
                            </button>
                        </div>
                    </div>

                    <!-- Métricas Globales del Equipo -->
                    <div class="kpi-summary-row" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px;">
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Conversaciones</div>
                            <div class="kpi-total-convs" style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">0</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">FRT Mediana (P50)</div>
                            <div class="kpi-median-frt" style="font-size: 16px; font-weight: 800; color: #0284c7; margin-top: 2px;">0 min</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">FRT P90</div>
                            <div class="kpi-p90-frt" style="font-size: 16px; font-weight: 800; color: #d97706; margin-top: 2px;">0 min</div>
                        </div>
                        <div class="kpi-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Tasa Cierre Global</div>
                            <div class="kpi-close-rate" style="font-size: 16px; font-weight: 800; color: #16a34a; margin-top: 2px;">0.00%</div>
                        </div>
                    </div>

                    <!-- Visualización de Tiempos y Rendimiento por Asesor -->
                    <div class="chart-container" style="width: 100%; min-height: 140px; position: relative; margin-bottom: 12px;">
                        <div class="chart-loading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.7); z-index: 10;">
                            <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        </div>
                        <div class="chart-empty-state" style="display: none; flex-direction: column; align-items: center; justify-content: center; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 20px; text-align: center;">
                            <i class="fas fa-user-clock text-muted fa-3x" style="margin-bottom: 12px; color: #94a3b8;"></i>
                            <h5 style="margin: 0 0 6px 0; color: #334155; font-weight: 700;">Sin actividad de asesores</h5>
                            <p class="text-muted" style="font-size: 12px; margin: 0; max-width: 360px;">
                                No se encontraron mensajes ni asignaciones de leads en el período seleccionado.
                            </p>
                        </div>
                        <div class="agents-bars-container" style="display: flex; flex-direction: column; gap: 8px; padding: 4px 0;"></div>
                    </div>

                    <!-- Tabla Resumen por Asesor -->
                    <div class="table-responsive" style="max-height: 180px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 4px;">
                        <table class="table table-condensed table-striped" style="margin-bottom: 0; font-size: 11px;">
                            <thead>
                                <tr style="background: #f1f5f9; color: #475569;">
                                    <th>Asesor de Viajes</th>
                                    <th class="text-right">Chats</th>
                                    <th class="text-right">FRT Mediana</th>
                                    <th class="text-right">FRT P90</th>
                                    <th class="text-right">Ventas</th>
                                    <th class="text-right">% Cierre</th>
                                </tr>
                            </thead>
                            <tbody class="agents-table-body">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Cargando métricas de asesores...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            `,

            events: {
                'click .filter-btn': 'onChangeDateFilter',
                'change .business-hours-toggle': 'onToggleBusinessHours',
                'click .download-csv-btn': 'onDownloadCsv'
            },

            currentRange: 'currentYear',
            onlyBusinessHours: true,

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

            onToggleBusinessHours: function (e) {
                this.onlyBusinessHours = $(e.currentTarget).is(':checked');
                this.loadDataAndRender();
            },

            onDownloadCsv: function () {
                var dates = this.computeDateRange(this.currentRange);
                var url = 'api/v1/Analytics/export-agent-performance';
                var params = [];
                if (dates.dateFrom) params.push('dateFrom=' + encodeURIComponent(dates.dateFrom));
                if (dates.dateTo) params.push('dateTo=' + encodeURIComponent(dates.dateTo));
                params.push('onlyBusinessHours=' + (this.onlyBusinessHours ? 'true' : 'false'));
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
                var $bars = this.$el.find('.agents-bars-container');

                $loading.show();
                $empty.hide();
                $bars.empty();

                var params = this.computeDateRange(this.currentRange);
                params.onlyBusinessHours = this.onlyBusinessHours ? 'true' : 'false';

                Espo.Ajax.getRequest('Analytics/agent-performance', params)
                    .then(function (data) {
                        $loading.hide();
                        var agents = data.agents || [];
                        var totalConvs = (data.summary && data.summary.totalConversations) || 0;
                        var hasData = agents.length > 0 && (totalConvs > 0 || agents.some(function (a) { return (a.conversationsCount || 0) > 0; }));

                        self.renderKpis(data.summary || {});

                        if (!hasData) {
                            $empty.css('display', 'flex');
                            $bars.hide();
                        } else {
                            $empty.hide();
                            $bars.show();
                            self.renderAgentBars(agents);
                        }

                        self.renderTable(agents);
                    })
                    .catch(function () {
                        $loading.hide();
                        $empty.css('display', 'flex');
                        $bars.hide();
                    });
            },

            renderKpis: function (summary) {
                this.$el.find('.kpi-total-convs').text(summary.totalConversations || 0);
                this.$el.find('.kpi-median-frt').text((summary.avgFirstResponseTimeMinutes || 0) + ' min');
                this.$el.find('.kpi-p90-frt').text((summary.p90FirstResponseTimeMinutes || 0) + ' min');
                this.$el.find('.kpi-close-rate').text((summary.overallCloseRate || 0) + '%');
            },

            renderAgentBars: function (agents) {
                var $container = this.$el.find('.agents-bars-container');
                $container.empty();

                agents.slice(0, 5).forEach(function (a) {
                    var closeRate = Number(a.closeRate || 0);
                    var row = $(
                        '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">' +
                            '<div style="min-width: 140px;">' +
                                '<div style="font-weight: 700; color: #1e293b; font-size: 12px;">' +
                                    '<i class="fas fa-user-circle text-primary" style="margin-right: 5px;"></i>' + (a.agentName || 'Sin asignar') +
                                '</div>' +
                                '<div style="font-size: 11px; color: #64748b;">' + (a.conversationsCount || 0) + ' chats | ' + (a.wonOpportunitiesCount || 0) + ' ventas</div>' +
                            '</div>' +
                            '<div style="display: flex; gap: 6px; align-items: center;">' +
                                '<span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 11px;" title="Tiempo Mediano">P50: ' + (a.medianFirstResponseMinutes || 0) + 'm</span>' +
                                '<span class="badge" style="background: #fef3c7; color: #b45309; font-size: 11px;" title="Percentil 90">P90: ' + (a.p90FirstResponseMinutes || 0) + 'm</span>' +
                                '<span class="badge" style="background: #dcfce7; color: #15803d; font-weight: 700; font-size: 11px;">' + closeRate + '% Cierre</span>' +
                            '</div>' +
                        '</div>'
                    );
                    $container.append(row);
                });
            },

            renderTable: function (agents) {
                var $tbody = this.$el.find('.agents-table-body');
                $tbody.empty();

                if (!agents || !agents.length) {
                    $tbody.html('<tr><td colspan="6" class="text-center text-muted">Sin actividad de asesores en el período seleccionado.</td></tr>');
                    return;
                }

                agents.forEach(function (a) {
                    var tr = $(
                        '<tr>' +
                            '<td><strong>' + (a.agentName || 'Sin asignar') + '</strong></td>' +
                            '<td class="text-right">' + (a.conversationsCount || 0) + '</td>' +
                            '<td class="text-right"><span class="badge" style="background:#e0f2fe; color:#0369a1;">' + (a.medianFirstResponseMinutes || 0) + ' min</span></td>' +
                            '<td class="text-right"><span class="badge" style="background:#fef3c7; color:#b45309;">' + (a.p90FirstResponseMinutes || 0) + ' min</span></td>' +
                            '<td class="text-right">' + (a.wonOpportunitiesCount || 0) + '</td>' +
                            '<td class="text-right"><strong style="color:#16a34a;">' + (a.closeRate || 0) + '%</strong></td>' +
                        '</tr>'
                    );
                    $tbody.append(tr);
                });
            }
        });

        return AgentPerformanceView;
    }

    if (typeof define === 'function' && define.amd) {
        define('custom:views/dashlets/agent-performance', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
        define('custom:modules/travel/views/dashlets/agent-performance', ['views/dashlets/abstract/base'], function (Base) {
            return initDashlet(Base);
        });
    }
})();
