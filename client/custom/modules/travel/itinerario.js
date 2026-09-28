/**
 * TravelOps - Módulo de Experiencia de Usuario y Visualización de Itinerarios (EspoCRM v8)
 * Monta: Stepper Visual de Estado, Hero Bar Financiero con Ruta/Tipo, Portal Web del Viajero y Consola Operativa en Destino.
 */
(function () {
    'use strict';

    function getSafeTravelBaseUrl(viewInstance) {
        try {
            if (viewInstance && typeof viewInstance.getConfig === 'function') {
                var c = viewInstance.getConfig();
                if (c && typeof c.get === 'function') {
                    var val = c.get('travelWebExternalUrl');
                    if (val) return String(val).replace(/\/$/, '');
                }
            }
        } catch (e) {}
        try {
            if (viewInstance && viewInstance.config && typeof viewInstance.config.get === 'function') {
                var val2 = viewInstance.config.get('travelWebExternalUrl');
                if (val2) return String(val2).replace(/\/$/, '');
            }
        } catch (e) {}
        try {
            if (window.Espo && window.Espo.settings && window.Espo.settings.travelWebExternalUrl) {
                return String(window.Espo.settings.travelWebExternalUrl).replace(/\/$/, '');
            }
        } catch (e) {}
        return 'http://localhost:8085';
    }

    function createItinerarioDetailView(DetailRecordViewModule) {
        var BaseDetail = (DetailRecordViewModule && DetailRecordViewModule.default)
            ? DetailRecordViewModule.default
            : DetailRecordViewModule;

        class ItinerarioDetailRecordView extends BaseDetail {

            events() {
                var events = super.events ? (typeof super.events === 'function' ? super.events() : Object.assign({}, super.events)) : {};
                events['click [data-action="generatePdf"]'] = function (e) {
                    if (e) e.preventDefault();
                    this.actionGeneratePdf();
                };
                events['click [data-action="openPublicWeb"]'] = function (e) {
                    if (e) e.preventDefault();
                    this.actionOpenPublicWeb();
                };
                return events;
            }

            setup() {
                super.setup();
                var self = this;
                this.listenTo(this.model, 'sync', function () { self.renderAllPanels(); });
                this.listenTo(this.model, 'change:status', function () { self.renderAllPanels(); });
                this.listenTo(this.model, 'change:totalCost change:totalSelling change:grossProfit change:origin change:destination change:tripType', function () {
                    self.renderHeroFinancialBar();
                });

                this.listenTo(this, 'action:generatePdf', function () { self.actionGeneratePdf(); });
                this.listenTo(this, 'action:openPublicWeb', function () { self.actionOpenPublicWeb(); });
            }

            afterRender() {
                super.afterRender();
                var self = this;
                this.renderAllPanels();
                this.bindHeaderActionButtons();

                setTimeout(function () {
                    self.renderAllPanels();
                    self.bindHeaderActionButtons();
                }, 50);

                setTimeout(function () {
                    self.renderAllPanels();
                    self.bindHeaderActionButtons();
                }, 200);

                setTimeout(function () {
                    self.renderAllPanels();
                    self.bindHeaderActionButtons();
                }, 500);
            }

            bindHeaderActionButtons() {
                var self = this;
                var $targets = $('[data-action="generatePdf"], [data-action="openPublicWeb"]');
                $targets.filter('[data-action="generatePdf"]').off('click.itinPdf').on('click.itinPdf', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.actionGeneratePdf();
                });

                $targets.filter('[data-action="openPublicWeb"]').off('click.itinWeb').on('click.itinWeb', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.actionOpenPublicWeb();
                });
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
                                '<i class="' + st.icon + '"></i> ' +
                                '<span>' + st.label + '</span>' +
                            '</div>' +
                        '</div>';
                });

                var stepperWrapper = $(
                    '<div class="itinerario-status-stepper" style="margin: 0 0 16px 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">' +
                        '<div style="display: flex; gap: 8px; align-items: stretch;">' +
                            stepsHtml +
                        '</div>' +
                    '</div>'
                );

                this.insertCustomPanel(stepperWrapper);
            }

            renderHeroFinancialBar() {
                this.$el.find('.itinerario-hero-financial-bar').remove();

                var totalCost = parseFloat(this.model.get('totalCost')) || 0;
                var totalSelling = parseFloat(this.model.get('totalSelling')) || 0;
                var grossProfit = parseFloat(this.model.get('grossProfit')) || (totalSelling - totalCost);
                var marginPct = totalSelling > 0 ? ((grossProfit / totalSelling) * 100).toFixed(1) : '0.0';
                var isProfitable = grossProfit >= 0;

                var origin = this.model.get('origin') || '';
                var destination = this.model.get('destination') || this.model.get('name') || 'Destino por definir';
                var tripType = this.model.get('tripType') || 'Paquete Turístico';
                var startDate = this.model.get('startDate') || 'Por definir';
                var endDate = this.model.get('endDate') || 'Por definir';

                var routeText = origin ? (origin + ' &rarr; ' + destination) : destination;

                var heroHtml = $(
                    '<div class="itinerario-hero-financial-bar" style="margin: 0 0 16px 0; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; border-radius: 8px; padding: 16px 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-left: 5px solid #0284c7;">' +
                        '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">' +
                            '<div>' +
                                '<div style="margin-bottom: 4px;">' +
                                    '<span class="badge" style="background:#0284c7; color:#fff; font-size:10px; text-transform:uppercase; letter-spacing:0.05em; padding:4px 8px; font-weight:700; border-radius:4px; margin-right:8px;">' + tripType + '</span>' +
                                    '<span style="font-size: 13px; color: #cbd5e1; font-weight:600;">' + (this.model.get('name') || '') + '</span>' +
                                '</div>' +
                                '<div style="font-size: 18px; font-weight: 800; color: #f8fafc; display:flex; align-items:center; gap:8px;">' +
                                    '<i class="fas fa-map-marked-alt text-info" style="color:#38bdf8;"></i> ' + routeText +
                                '</div>' +
                                '<div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">' +
                                    '<i class="fas fa-calendar-alt" style="margin-right: 6px;"></i> ' + startDate + ' al ' + endDate +
                                '</div>' +
                            '</div>' +
                            '<div style="display: grid; grid-template-columns: repeat(4, auto); gap: 12px; align-items: center;">' +
                                '<div style="background: rgba(255,255,255,0.08); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">Costo Total</div>' +
                                    '<div style="font-size: 15px; font-weight: 700; color: #f8fafc;">$' + totalCost.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                                '<div style="background: rgba(255,255,255,0.08); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">Precio Venta</div>' +
                                    '<div style="font-size: 15px; font-weight: 700; color: #38bdf8;">$' + totalSelling.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                                '<div style="background: rgba(255,255,255,0.12); padding: 10px 14px; border-radius: 8px; border: 1px solid ' + (isProfitable ? '#22c55e' : '#ef4444') + '; text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #e2e8f0; font-weight: 700;">Utilidad</div>' +
                                    '<div style="font-size: 16px; font-weight: 800; color: ' + (isProfitable ? '#4ade80' : '#f87171') + ';">$' + grossProfit.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</div>' +
                                '</div>' +
                                '<div style="background: rgba(255,255,255,0.12); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.18); text-align: right;">' +
                                    '<div style="font-size: 10px; text-transform: uppercase; color: #e2e8f0; font-weight: 700;">Margen</div>' +
                                    '<div style="font-size: 16px; font-weight: 800; color: #fbbf24;">' + marginPct + '%</div>' +
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

                var baseUrl = getSafeTravelBaseUrl(this);
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
                    navigator.clipboard.writeText(publicUrl).then(function () {
                        Espo.Ui.success('Enlace copiado al portapapeles');
                    }).catch(function () {
                        Espo.Ui.warning('No se pudo copiar automáticamente.');
                    });
                });

                this.insertCustomPanelAfterHero(portalHtml);
            }

            renderOperationalBoardCard() {
                this.$el.find('.itinerario-operational-board-card').remove();

                var status = this.model.get('status');
                if (status !== 'En Viaje' && status !== 'Confirmado') {
                    return;
                }

                var destination = this.model.get('destination') || 'Destino';
                var self = this;
                var id = this.model.id;

                Espo.Ajax.getRequest('Itinerario/action/getOperationalCard', { id: id })
                    .then(function (data) {
                        if (!data) return;

                        var leadPax = data.leadPassengerName || 'Por asignar';
                        var paxCount = data.paxCount || 1;
                        var activeService = data.todaysActiveService || 'Sin servicio programado para hoy';
                        var activeSupplier = data.assignedSupplierName || 'Operador local no asignado';
                        var emergencyPhone = data.emergencyPhone || '+51 1 999 999 999';
                        var chatUrl = data.chatwootChatUrl || null;

                        var cardHtml = $(
                            '<div class="itinerario-operational-board-card alert alert-info" style="margin: 0 0 16px 0; border-left: 5px solid #00838f; background: #e0f2fe; color: #075985; border-radius: 8px; padding: 14px 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);">' +
                                '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">' +
                                    '<div>' +
                                        '<h5 style="margin:0 0 6px 0; color:#0369a1; font-weight:700; font-size:14px;">' +
                                            '<i class="fas fa-plane-arrival" style="margin-right:6px;"></i> Consola Operativa en Destino: ' + destination +
                                        '</h5>' +
                                        '<div style="font-size:12px; line-height: 1.6;">' +
                                            '<strong>Titular:</strong> ' + leadPax + ' &nbsp;<span class="badge" style="background:#0284c7;">' + paxCount + ' Pax</span> &nbsp;|&nbsp; ' +
                                            '<strong>Servicio Hoy:</strong> <span class="badge" style="background:#0369a1;">' + activeService + '</span> &nbsp;|&nbsp; ' +
                                            '<strong>Operador:</strong> ' + activeSupplier +
                                        '</div>' +
                                    '</div>' +
                                    '<div style="display:flex; gap:6px; align-items:center;">' +
                                        '<a href="tel:' + emergencyPhone + '" class="btn btn-warning btn-xs" style="font-weight:600; color:#78350f; background:#fef3c7; border-color:#fde68a;">' +
                                            '<i class="fas fa-phone-alt"></i> ' + emergencyPhone +
                                        '</a>' +
                                        (chatUrl ? (
                                            '<a href="' + chatUrl + '" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-xs" style="font-weight:600;">' +
                                                '<i class="fab fa-whatsapp"></i> WhatsApp Pasajero' +
                                            '</a>'
                                        ) : '') +
                                        '<button type="button" class="btn btn-primary btn-xs btn-action-pdf" style="font-weight:600;">' +
                                            '<i class="fas fa-file-pdf"></i> Generar PDF' +
                                        '</button>' +
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
                var status = this.model.get('status');

                if (status === 'Cancelado') {
                    Espo.Ui.warning('Este itinerario está Cancelado.');
                    return;
                }

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
                        var errorMsg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
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
                var baseUrl = getSafeTravelBaseUrl(this);
                var url = baseUrl + '/p/' + token;
                window.open(url, '_blank');
            }
        }

        return ItinerarioDetailRecordView;
    }

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

    if (typeof Espo !== 'undefined' && Espo.loader) {
        Espo.loader.require('views/record/detail', function (DetailRecordViewModule) {
            createItinerarioDetailView(DetailRecordViewModule);
        });
    }
})();
