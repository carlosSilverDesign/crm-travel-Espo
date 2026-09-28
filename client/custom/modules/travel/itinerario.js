/**
 * TravelOps - Módulo de Experiencia de Usuario y Visualización de Itinerarios (EspoCRM v8)
 * Monta: Stepper Visual de Estado, Hero Bar Financiero, Portal Web del Viajero y Consola Operativa en Destino.
 */
(function () {
    'use strict';

    console.log('[TravelOps] Módulo Itinerario cargado.');

    function createItinerarioDetailView(DetailRecordViewModule) {
        var BaseDetail = (DetailRecordViewModule && DetailRecordViewModule.default)
            ? DetailRecordViewModule.default
            : DetailRecordViewModule;

        class ItinerarioDetailRecordView extends BaseDetail {

            setup() {
                super.setup();
                var self = this;
                this.listenTo(this.model, 'sync', function () { self.renderAllPanels(); });
                this.listenTo(this.model, 'change:status', function () { self.renderAllPanels(); });
                this.listenTo(this.model, 'change:totalCost change:totalSelling change:grossProfit', function () { self.renderHeroFinancialBar(); });
            }

            afterRender() {
                super.afterRender();
                var self = this;
                this.renderAllPanels();
                setTimeout(function () { self.renderAllPanels(); }, 50);
                setTimeout(function () { self.renderAllPanels(); }, 150);
                setTimeout(function () { self.renderAllPanels(); }, 350);
            }

            renderAllPanels() {
                if (!this.$el || !this.$el.length) return;
                this.renderStatusStepper();
                this.renderHeroFinancialBar();
                this.renderOperationalBoardCard();
                this.renderPublicPortalCard();
            }

            insertCustomPanel(element) {
                var $middle = this.$el.find('.record-grid .middle, .record-grid .left, .middle, .left, .panels-container').first();
                if ($middle.length) {
                    $middle.prepend(element);
                    return;
                }
                var $target = this.$el.find('.record-grid, .detail, .record, .body').first();
                if ($target.length) {
                    $target.prepend(element);
                } else {
                    this.$el.prepend(element);
                }
            }

            insertCustomPanelAfterHero(element) {
                var $hero = this.$el.find('.itinerario-hero-financial-bar');
                if ($hero.length) {
                    $hero.after(element);
                } else {
                    this.insertCustomPanel(element);
                }
            }

            /**
             * 1. Stepper Visual del Ciclo de Vida del Viaje
             */
            renderStatusStepper() {
                this.$el.find('.itinerario-status-stepper').remove();

                var currentStatus = this.model.get('status') || 'Cotización';
                var stages = [
                    { key: 'Cotización', label: '1. Cotización', icon: 'fas fa-file-invoice-dollar' },
                    { key: 'Confirmado', label: '2. Confirmado', icon: 'fas fa-check-circle' },
                    { key: 'En Viaje', label: '3. En Destino', icon: 'fas fa-plane-departure' },
                    { key: 'Finalizado', label: '4. Finalizado', icon: 'fas fa-award' }
                ];

                var currentIndex = 0;
                stages.forEach(function (st, idx) {
                    if (st.key === currentStatus) currentIndex = idx;
                });

                if (currentStatus === 'Cancelado') {
                    var cancelHtml = $(
                        '<div class="itinerario-status-stepper alert alert-danger" style="margin: 0 0 16px 0; border-radius: 8px; font-weight: 700; display:flex; align-items:center; gap:8px;">' +
                            '<i class="fas fa-ban fa-lg"></i> Este itinerario se encuentra CANCELADO' +
                        '</div>'
                    );
                    this.insertCustomPanel(cancelHtml);
                    return;
                }

                var stepsHtml = '';
                stages.forEach(function (st, idx) {
                    var isCompleted = idx < currentIndex;
                    var isActive = idx === currentIndex;
                    var stateClass = isActive ? 'stepper-active' : (isCompleted ? 'stepper-completed' : 'stepper-pending');

                    var bg = isActive ? '#0284c7' : (isCompleted ? '#10b981' : '#f8fafc');
                    var color = (isActive || isCompleted) ? '#ffffff' : '#64748b';
                    var border = isActive ? '#0369a1' : (isCompleted ? '#059669' : '#cbd5e1');

                    stepsHtml +=
                        '<div class="stepper-step ' + stateClass + '" style="flex: 1; text-align: center; position: relative; padding: 4px 2px;">' +
                            '<div style="background:' + bg + '; color:' + color + '; border: 1px solid ' + border + '; border-radius: 6px; padding: 10px 8px; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: ' + (isActive ? '0 2px 6px rgba(2,132,199,0.3)' : 'none') + ';">' +
                                '<i class="' + st.icon + '"></i> ' + st.label +
                            '</div>' +
                        '</div>';
                });

                var stepperContainer = $(
                    '<div class="itinerario-status-stepper" style="margin: 0 0 16px 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.05);">' +
                        '<div style="display: flex; gap: 10px; align-items: center; justify-content: space-between;">' +
                            stepsHtml +
                        '</div>' +
                    '</div>'
                );

                this.insertCustomPanel(stepperContainer);
            }

            /**
             * 2. Barra Hero de Métricas Financieras
             */
            renderHeroFinancialBar() {
                this.$el.find('.itinerario-hero-financial-bar').remove();

                var totalCost = parseFloat(this.model.get('totalCost') || 0);
                var totalSelling = parseFloat(this.model.get('totalSelling') || 0);
                var grossProfit = parseFloat(this.model.get('grossProfit') || (totalSelling - totalCost));
                var marginPct = totalSelling > 0 ? ((grossProfit / totalSelling) * 100).toFixed(1) : 0;
                var destination = this.model.get('destination') || 'Destino por definir';
                var startDate = this.model.get('startDate') || '---';
                var endDate = this.model.get('endDate') || '---';

                var isProfitable = grossProfit >= 0;

                var heroHtml = $(
                    '<div class="itinerario-hero-financial-bar" style="margin-bottom: 16px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 10px; padding: 18px 22px; color: #ffffff; box-shadow: 0 4px 14px rgba(15,23,42,0.18);">' +
                        '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">' +
                            '<div>' +
                                '<div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; font-weight: 700;">Resumen del Itinerario</div>' +
                                '<h3 style="margin: 3px 0 6px 0; font-size: 20px; font-weight: 800; color: #f8fafc;">' +
                                    '<i class="fas fa-map-marker-alt text-danger" style="margin-right: 8px;"></i> ' + destination +
                                '</h3>' +
                                '<div style="font-size: 13px; color: #cbd5e1;">' +
                                    '<i class="fas fa-calendar-alt" style="margin-right: 6px;"></i> ' + startDate + ' al ' + endDate +
                                '</div>' +
                            '</div>' +
                            '<div style="display: grid; grid-template-columns: repeat(3, auto); gap: 14px; align-items: center;">' +
                                '<div style="background: rgba(255,255,255,0.08); padding: 10px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">Costo Neto</div>' +
                                    '<div style="font-size: 16px; font-weight: 700; color: #f8fafc;">$' + totalCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                                '<div style="background: rgba(255,255,255,0.08); padding: 10px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">Precio Venta</div>' +
                                    '<div style="font-size: 16px; font-weight: 700; color: #38bdf8;">$' + totalSelling.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                                '<div style="background: rgba(255,255,255,0.12); padding: 10px 16px; border-radius: 8px; border: 1px solid ' + (isProfitable ? '#22c55e' : '#ef4444') + '; text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #e2e8f0; font-weight: 700;">Utilidad (' + marginPct + '%)</div>' +
                                    '<div style="font-size: 17px; font-weight: 800; color: ' + (isProfitable ? '#4ade80' : '#f87171') + ';">$' + grossProfit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );

                var $stepper = this.$el.find('.itinerario-status-stepper');
                if ($stepper.length) {
                    $stepper.after(heroHtml);
                } else {
                    this.insertCustomPanel(heroHtml);
                }
            }

            /**
             * 3. Tarjeta del Portal Web Interactivo
             */
            renderPublicPortalCard() {
                this.$el.find('.itinerario-public-portal-card').remove();

                var token = this.model.get('publicAccessToken');
                if (!token) {
                    var emptyCard = $(
                        '<div class="itinerario-public-portal-card alert alert-warning" style="margin: 0 0 16px 0; border-left: 5px solid #f59e0b; background: #fffbeb; color: #92400e; border-radius: 8px; padding: 12px 16px;">' +
                            '<div style="display:flex; align-items:center; gap:10px;">' +
                                '<i class="fas fa-info-circle fa-lg" style="color:#f59e0b;"></i>' +
                                '<div>' +
                                    '<strong>Portal Web del Viajero:</strong> Token público pendiente de asignación. Guarde el itinerario para habilitar el enlace interactivo.' +
                                '</div>' +
                            '</div>' +
                        '</div>'
                    );
                    this.insertCustomPanelAfterHero(emptyCard);
                    return;
                }

                var baseUrl = (this.getConfig().get('travelWebExternalUrl') || 'http://localhost:8085').replace(/\/$/, '');
                var publicUrl = baseUrl + '/p/' + token;
                var accessCount = this.model.get('webAccessCount') || 0;
                var lastAccess = this.model.get('lastWebAccessAt') || 'Sin visitas aún';

                var portalHtml = $(
                    '<div class="itinerario-public-portal-card alert alert-success" style="margin: 0 0 16px 0; border-left: 5px solid #10b981; background: #ecfdf5; color: #065f46; border-radius: 8px; padding: 14px 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">' +
                            '<div>' +
                                '<h5 style="margin:0 0 4px 0; color:#065f46; font-weight:700; font-size:14px;">' +
                                    '<i class="fas fa-globe-americas" style="margin-right:6px;"></i> Portal Web Interactivo del Viajero' +
                                '</h5>' +
                                '<div style="font-size:12px;">' +
                                    '<span style="color:#047857; font-weight:600;">Enlace Seguro:</span> ' +
                                    '<a href="' + publicUrl + '" target="_blank" rel="noopener noreferrer" style="font-weight:600; text-decoration:underline; color:#065f46;">' + publicUrl + '</a> &nbsp;|&nbsp; ' +
                                    '<span class="badge" style="background:#059669;">' + accessCount + ' accesos web</span> &nbsp;|&nbsp; ' +
                                    '<span class="text-muted small" style="color:#047857;">Último acceso: ' + lastAccess + '</span>' +
                                '</div>' +
                            '</div>' +
                            '<div style="display:flex; gap:6px;">' +
                                '<button type="button" class="btn btn-default btn-xs btn-copy-portal-link" style="font-weight:600;" title="Copiar enlace">' +
                                    '<i class="fas fa-copy"></i> Copiar Link' +
                                '</button>' +
                                '<a href="' + publicUrl + '" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-xs" style="font-weight:600;">' +
                                    '<i class="fas fa-external-link-alt"></i> Abrir Portal' +
                                '</a>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );

                portalHtml.find('.btn-copy-portal-link').on('click', function () {
                    var btn = $(this);
                    navigator.clipboard.writeText(publicUrl).then(function () {
                        btn.html('<i class="fas fa-check text-success"></i> ¡Copiado!');
                        setTimeout(function () {
                            btn.html('<i class="fas fa-copy"></i> Copiar Link');
                        }, 2000);
                    });
                });

                this.insertCustomPanelAfterHero(portalHtml);
            }

            /**
             * 4. Consola Operativa en Destino
             */
            renderOperationalBoardCard() {
                this.$el.find('.itinerario-operational-board-card').remove();

                var status = this.model.get('status');
                if (status !== 'Confirmado' && status !== 'En Viaje') {
                    return;
                }

                var self = this;
                Espo.Ajax.getRequest('Itinerario/action/operationalSummary', {
                    id: this.model.id
                }).then(function (summary) {
                    var phoneBtn = summary.emergencyPhone
                        ? '<a href="tel:' + encodeURIComponent(summary.emergencyPhone) + '" class="btn btn-warning btn-sm" style="font-weight:700; color:#3e2723;"><i class="fas fa-phone-alt"></i> ' + summary.emergencyPhone + '</a>'
                        : '<span class="text-muted small" style="background:rgba(0,0,0,0.05); padding:4px 8px; border-radius:4px;">Sin teléfono</span>';

                    var chatBtn = summary.chatwootChatUrl
                        ? '<a href="' + summary.chatwootChatUrl + '" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm" style="font-weight:600;"><i class="fab fa-whatsapp"></i> WhatsApp Pasajero</a>'
                        : '<button type="button" class="btn btn-default btn-sm" disabled><i class="fab fa-whatsapp"></i> Sin chat</button>';

                    var cardHtml = $(
                        '<div class="itinerario-operational-board-card alert alert-info" style="margin: 0 0 16px 0; border-left: 5px solid #00838f; background: #e0f7fa; color: #006064; border-radius: 8px; padding: 14px 18px; box-shadow: 0 2px 5px rgba(0,0,0,0.06);">' +
                            '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">' +
                                '<div>' +
                                    '<h4 style="margin:0 0 6px 0; color:#006064; font-weight:700; font-size:15px;">' +
                                        '<i class="fas fa-plane-departure" style="margin-right:6px;"></i> Consola Operativa en Destino: ' + (summary.destination || 'Destino') +
                                    '</h4>' +
                                    '<div style="font-size:13px; line-height:1.6;">' +
                                        '<strong>Titular:</strong> ' + summary.leadPassengerName + ' ' +
                                        '<span class="badge" style="background:#00838f; margin-left:4px;">' + summary.paxCount + ' Pax</span> &nbsp;|&nbsp; ' +
                                        '<strong>Servicio Hoy:</strong> <span class="label label-primary" style="background:#0277bd;">' + summary.serviceType + '</span> ' + summary.todaysActiveService + ' &nbsp;|&nbsp; ' +
                                        '<strong>Operador:</strong> ' + summary.assignedSupplierName +
                                    '</div>' +
                                '</div>' +
                                '<div style="display:flex; gap:8px; align-items:center;">' +
                                    phoneBtn +
                                    chatBtn +
                                    '<button type="button" class="btn btn-primary btn-sm btn-action-pdf" style="font-weight:600;"><i class="fas fa-file-pdf"></i> Generar PDF</button>' +
                                '</div>' +
                            '</div>' +
                        '</div>'
                    );

                    cardHtml.find('.btn-action-pdf').on('click', function () {
                        self.actionGeneratePdf();
                    });

                    self.insertCustomPanelAfterHero(cardHtml);
                }).catch(function () {});
            }

            actionGeneratePdf() {
                var self = this;
                var id = this.model.id;
                Espo.Ui.notifyWait('Generando expediente PDF...');

                Espo.Ajax.postRequest('Itinerario/action/generatePdf', { id: id })
                    .then(function (response) {
                        Espo.Ui.notify(false);
                        if (response && response.attachmentId) {
                            var downloadUrl = '?entryPoint=download&id=' + encodeURIComponent(response.attachmentId);
                            window.open(downloadUrl, '_blank');
                            Espo.Ui.success('Expediente PDF generado correctamente.');
                            self.model.fetch();
                        } else if (response && response.id) {
                            var downloadUrl2 = '?entryPoint=download&id=' + encodeURIComponent(response.id);
                            window.open(downloadUrl2, '_blank');
                            Espo.Ui.success('Expediente PDF generado correctamente.');
                            self.model.fetch();
                        } else {
                            Espo.Ui.success('Solicitud de PDF procesada.');
                        }
                    })
                    .catch(function (xhr) {
                        Espo.Ui.notify(false);
                        var errorMsg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Error al generar el PDF del itinerario.';
                        Espo.Ui.error(errorMsg);
                    });
            }

            actionOpenPublicWeb() {
                var token = this.model.get('publicAccessToken');
                if (!token) {
                    Espo.Ui.warning('El itinerario aún no tiene un token público asignado. Guarde el registro para generarlo.');
                    return;
                }
                var baseUrl = (this.getConfig().get('travelWebExternalUrl') || 'http://localhost:8085').replace(/\/$/, '');
                var url = baseUrl + '/p/' + token;
                window.open(url, '_blank');
            }
        }

        return ItinerarioDetailRecordView;
    }

    // Definir el módulo AMD en todos los alias soportados
    if (typeof define === 'function' && define.amd) {
        define('custom:views/itinerario/record/detail', ['exports', 'views/record/detail'], function (exports, DetailRecordViewModule) {
            var ViewClass = createItinerarioDetailView(DetailRecordViewModule);
            exports.default = ViewClass;
            return ViewClass;
        });

        define('views/itinerario/record/detail', ['exports', 'views/record/detail'], function (exports, DetailRecordViewModule) {
            var ViewClass = createItinerarioDetailView(DetailRecordViewModule);
            exports.default = ViewClass;
            return ViewClass;
        });

        define('custom:modules/travel/views/itinerario/record/detail', ['exports', 'views/record/detail'], function (exports, DetailRecordViewModule) {
            var ViewClass = createItinerarioDetailView(DetailRecordViewModule);
            exports.default = ViewClass;
            return ViewClass;
        });
    }

    // Fallback directo en el prototipo de views/record/detail
    function hookFallback() {
        if (typeof Espo === 'undefined' || !Espo.loader) {
            setTimeout(hookFallback, 50);
            return;
        }

        Espo.loader.require(['views/record/detail'], function (DetailRecordViewModule) {
            var DetailRecordView = (DetailRecordViewModule && DetailRecordViewModule.default)
                ? DetailRecordViewModule.default
                : DetailRecordViewModule;

            if (!DetailRecordView || !DetailRecordView.prototype || DetailRecordView.prototype.__travelOpsHooked) return;
            DetailRecordView.prototype.__travelOpsHooked = true;

            var origAfterRender = DetailRecordView.prototype.afterRender;
            DetailRecordView.prototype.afterRender = function () {
                var res = origAfterRender.apply(this, arguments);
                var scope = this.scope || this.entityType || (this.model && this.model.name) || (this.model && this.model.entityType);
                if (scope === 'Itinerario' && !(this instanceof (createItinerarioDetailView(DetailRecordViewModule)))) {
                    var ItinClass = createItinerarioDetailView(DetailRecordViewModule);
                    var helper = new ItinClass({ model: this.model, el: this.$el });
                    helper.$el = this.$el;
                    helper.renderAllPanels();
                }
                return res;
            };
        });
    }
    hookFallback();
})();
