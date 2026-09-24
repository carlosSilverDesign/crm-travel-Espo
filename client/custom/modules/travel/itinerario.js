/**
 * Extensiones Client-Side para la Consola y Tablero de Pasajeros "En Viaje"
 * con contactos de emergencia y enlace a Chatwoot (TASK-045).
 */
define('custom:views/itinerario/record/detail', ['views/record/detail'], function (DetailRecordView) {
    'use strict';

    return DetailRecordView.extend({

        setup: function () {
            DetailRecordView.prototype.setup.call(this);
            this.listenTo(this.model, 'sync', this.renderOperationalBoardCard.bind(this));
        },

        afterRender: function () {
            DetailRecordView.prototype.afterRender.call(this);
            this.renderOperationalBoardCard();
        },

        /**
         * Renderiza la tarjeta operativa "En Destino" con datos de emergencia y Chatwoot.
         * Heurística 6 (Reconocimiento antes que recuerdo) & Ley de Miller (Chunking).
         */
        renderOperationalBoardCard: function () {
            this.$el.find('.itinerario-operational-board-card').remove();

            var status = this.model.get('status');
            var startDate = this.model.get('startDate');
            var endDate = this.model.get('endDate');

            // Solo mostrar tarjeta relevante cuando está Confirmado o En Viaje
            if (status !== 'Confirmado' && status !== 'En Viaje') {
                return;
            }

            var self = this;
            Espo.Ajax.getRequest('Itinerario/action/operationalSummary', {
                id: this.model.id
            }).then(function (summary) {
                var phoneBtn = summary.emergencyPhone
                    ? '<a href="tel:' + encodeURIComponent(summary.emergencyPhone) + '" class="btn btn-warning btn-sm" style="font-weight:700; color:#3e2723;"><i class="fas fa-phone-alt"></i> ' + summary.emergencyPhone + '</a>'
                    : '<span class="text-muted small">Sin teléfono registrado</span>';

                var chatBtn = summary.chatwootChatUrl
                    ? '<a href="' + summary.chatwootChatUrl + '" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm" style="font-weight:600;"><i class="fab fa-whatsapp"></i> Chat Pasajero</a>'
                    : '<button type="button" class="btn btn-default btn-sm" disabled><i class="fab fa-whatsapp"></i> Sin chat vinculado</button>';

                var cardHtml = $(
                    '<div class="itinerario-operational-board-card alert alert-info" style="margin: 15px 0; border-left: 5px solid #00838f; background: #e0f7fa; color: #006064; border-radius: 6px; padding: 14px 18px; box-shadow: 0 2px 5px rgba(0,0,0,0.06);">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">' +
                            '<div>' +
                                '<h4 style="margin:0 0 6px 0; color:#006064; font-weight:700;">' +
                                    '<i class="fas fa-plane-departure" style="margin-right:6px;"></i> Consola Operativa en Destino: ' + (summary.destination || 'Destino') +
                                '</h4>' +
                                '<div style="font-size:13px; line-height:1.6;">' +
                                    '<strong>Titular:</strong> ' + summary.leadPassengerName + ' ' +
                                    '<span class="badge" style="background:#00838f; margin-left:4px;">' + summary.paxCount + ' Pax</span> &nbsp;|&nbsp; ' +
                                    '<strong>Servicio Hoy:</strong> <span class="label label-primary" style="background:#0277bd;">' + summary.serviceType + '</span> ' + summary.todaysActiveService + ' &nbsp;|&nbsp; ' +
                                    '<strong>Operador Local:</strong> ' + summary.assignedSupplierName +
                                '</div>' +
                            '</div>' +
                            '<div style="display:flex; gap:8px; align-items:center;">' +
                                phoneBtn +
                                chatBtn +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );

                var $recordHeader = self.$el.find('.record-grid, .detail, .record').first();
                if ($recordHeader.length) {
                    $recordHeader.before(cardHtml);
                } else {
                    self.$el.prepend(cardHtml);
                }
            }).catch(function () {
                // Silencioso ante fallos secundarios sin bloquear el CRM
            });
        }
    });
});
