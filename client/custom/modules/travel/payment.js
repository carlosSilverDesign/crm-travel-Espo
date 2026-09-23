/**
 * Extensiones Client-Side para Mesa de Control, Visualizador de Vouchers
 * y Acciones de Decisión (Confirmar/Rechazar) en Payment (TASK-041).
 */
define('custom:views/payment/record/detail', ['views/record/detail'], function (DetailRecordView) {
    'use strict';

    return DetailRecordView.extend({

        setup: function () {
            DetailRecordView.prototype.setup.call(this);
            this.listenTo(this.model, 'change:status', this.controlPaymentButtons.bind(this));
            this.listenTo(this.model, 'sync', this.renderAuditAndVoucherPanels.bind(this));
        },

        afterRender: function () {
            DetailRecordView.prototype.afterRender.call(this);
            this.controlPaymentButtons();
            this.renderAuditAndVoucherPanels();
        },

        /**
         * Controla la visibilidad de los botones de cabecera 'Confirmar Pago' y 'Rechazar Pago'.
         * Solo deben ser visibles cuando el cobro está en estado 'UnderReview' (Heurística 5 Nielsen).
         */
        controlPaymentButtons: function () {
            var status = this.model.get('status');
            var isUnderReview = (status === 'UnderReview');

            if (isUnderReview) {
                if (typeof this.showActionItem === 'function') {
                    this.showActionItem('confirmPayment');
                    this.showActionItem('rejectPayment');
                }
                this.$el.find('[data-action="confirmPayment"]').removeClass('hidden').show();
                this.$el.find('[data-action="rejectPayment"]').removeClass('hidden').show();
            } else {
                if (typeof this.hideActionItem === 'function') {
                    this.hideActionItem('confirmPayment');
                    this.hideActionItem('rejectPayment');
                }
                this.$el.find('[data-action="confirmPayment"]').addClass('hidden').hide();
                this.$el.find('[data-action="rejectPayment"]').addClass('hidden').hide();
            }
        },

        /**
         * Renderiza el banner de mesa de control comparativo y el visor embebido de comprobante.
         */
        renderAuditAndVoucherPanels: function () {
            this.$el.find('.payment-audit-banner-container').remove();
            this.$el.find('.payment-voucher-preview-container').remove();

            var status = this.model.get('status');
            var expectedAmount = parseFloat(this.model.get('amount') || 0);
            var declaredAmount = parseFloat(this.model.get('clientDeclaredAmount') || 0);
            var currency = this.model.get('currency') || 'USD';
            var opNumber = this.model.get('clientOperationNumber') || 'S/N';
            var proofId = this.model.get('proofAttachmentId');
            var proofName = this.model.get('proofAttachmentName') || 'comprobante';

            // 1. Banner de Mesa de Control Comparativa (Heurística 6 y Ley de Miller)
            if (status === 'UnderReview') {
                var amountsMatch = (Math.abs(expectedAmount - declaredAmount) < 0.01);
                var matchBadge = amountsMatch
                    ? '<span class="label label-success" style="font-size:12px; padding:4px 8px;"><i class="fas fa-check-circle"></i> Montos coinciden</span>'
                    : '<span class="label label-danger" style="font-size:12px; padding:4px 8px;"><i class="fas fa-exclamation-triangle"></i> Discrepancia: Esperado ' + expectedAmount.toFixed(2) + ' vs Declarado ' + declaredAmount.toFixed(2) + '</span>';

                var bannerHtml = $(
                    '<div class="payment-audit-banner-container alert alert-info" style="margin: 15px 0; border-left: 5px solid #0288d1; background: #e1f5fe; color: #01579b; border-radius: 6px; padding: 14px 18px;">' +
                        '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">' +
                            '<div>' +
                                '<h4 style="margin:0 0 6px 0; color:#01579b; font-weight:700;"><i class="fas fa-cash-register" style="margin-right:6px;"></i> Mesa de Control: Validación de Transferencia</h4>' +
                                '<div style="font-size:13px; line-height:1.5;">' +
                                    '<strong>N° Operación:</strong> <span class="badge" style="background:#0288d1;">' + opNumber + '</span> &nbsp;|&nbsp; ' +
                                    '<strong>Esperado:</strong> ' + currency + ' ' + expectedAmount.toFixed(2) + ' &nbsp;|&nbsp; ' +
                                    '<strong>Declarado:</strong> ' + currency + ' ' + declaredAmount.toFixed(2) + ' &nbsp; ' +
                                    matchBadge +
                                '</div>' +
                            '</div>' +
                            '<div style="display:flex; gap:8px;">' +
                                '<button type="button" class="btn btn-success btn-sm btn-quick-confirm" style="font-weight:600;"><i class="fas fa-check"></i> Confirmar Cobro</button>' +
                                '<button type="button" class="btn btn-danger btn-sm btn-quick-reject" style="font-weight:600;"><i class="fas fa-times"></i> Rechazar Cobro</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );

                bannerHtml.find('.btn-quick-confirm').on('click', this.actionConfirmPayment.bind(this));
                bannerHtml.find('.btn-quick-reject').on('click', this.actionRejectPayment.bind(this));

                var $recordHeader = this.$el.find('.record-grid, .detail, .record').first();
                if ($recordHeader.length) {
                    $recordHeader.before(bannerHtml);
                } else {
                    this.$el.prepend(bannerHtml);
                }
            }

            // 2. Visor Embebido de Voucher (Voucher Visualizer sin descarga forzada)
            if (proofId) {
                var fileUrl = '?entryPoint=attachment&id=' + encodeURIComponent(proofId);
                var isPdf = (/\.pdf$/i).test(proofName);
                var previewContent = '';

                if (isPdf) {
                    previewContent =
                        '<div style="margin-top:10px;">' +
                            '<iframe src="' + fileUrl + '#toolbar=0" style="width:100%; height:460px; border:1px solid #cfd8dc; border-radius:6px; background:#fff;"></iframe>' +
                            '<div style="margin-top:6px; text-align:right;">' +
                                '<a href="' + fileUrl + '" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-xs">' +
                                    '<i class="fas fa-external-link-alt"></i> Abrir PDF en pestaña nueva' +
                                '</a>' +
                            '</div>' +
                        '</div>';
                } else {
                    previewContent =
                        '<div style="margin-top:10px; text-align:center; background:#eceff1; padding:12px; border-radius:6px; border:1px solid #cfd8dc;">' +
                            '<a href="' + fileUrl + '" target="_blank" rel="noopener noreferrer" title="Clic para ampliar comprobante">' +
                                '<img src="' + fileUrl + '" alt="' + proofName + '" style="max-height:450px; max-width:100%; object-fit:contain; border-radius:4px; box-shadow:0 2px 6px rgba(0,0,0,0.15); border:1px solid #ccc; transition:transform 0.2s;" onmouseover="this.style.transform=\'scale(1.01)\'" onmouseout="this.style.transform=\'scale(1)\'">' +
                            '</a>' +
                            '<div class="text-muted small" style="margin-top:8px;">' +
                                '<i class="fas fa-search-plus"></i> Clic en la imagen para abrir en tamaño completo (' + proofName + ')' +
                            '</div>' +
                        '</div>';
                }

                var voucherContainer = $(
                    '<div class="payment-voucher-preview-container panel panel-default" style="margin-top:20px; border-color:#b0bec5;">' +
                        '<div class="panel-heading" style="background:#eceff1; font-weight:700; color:#37474f;">' +
                            '<i class="fas fa-file-invoice-dollar" style="margin-right:6px;"></i> Previsualización Inmediata del Comprobante Bancario' +
                        '</div>' +
                        '<div class="panel-body">' +
                            previewContent +
                        '</div>' +
                    '</div>'
                );

                var $declaredPanel = this.$el.find('[data-name="clientDeclaredAmount"]').closest('.panel');
                if ($declaredPanel.length) {
                    $declaredPanel.after(voucherContainer);
                } else {
                    this.$el.find('.middle').append(voucherContainer);
                }
            }
        },

        /**
         * Acción de decisión: Confirmar Pago en 1 clic tras diálogo de confirmación.
         */
        actionConfirmPayment: function () {
            var amount = this.model.get('amount');
            var currency = this.model.get('currency') || '';
            var opNum = this.model.get('clientOperationNumber') || 'S/N';

            var message = '¿Confirmar cobro de ' + currency + ' ' + parseFloat(amount).toFixed(2) + ' (Op. ' + opNum + ')?\n\n' +
                          'Esta acción mutará el estado a "Confirmado", actualizará irreversiblemente el balance de la reserva y liquidará la cuota programada bajo transacción atómica MySQL.';

            this.confirm(message, function () {
                Espo.Ui.notifyWait();
                Espo.Ajax.postRequest('Payment/action/confirm', {
                    id: this.model.id
                }).then(function (response) {
                    Espo.Ui.success(response.message || 'Cobro confirmado y conciliación completada.');
                    this.model.fetch();
                }.bind(this)).catch(function (xhr) {
                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Error al confirmar el cobro.';
                    Espo.Ui.error(errorMsg);
                });
            }.bind(this));
        },

        /**
         * Acción de decisión: Rechazar Pago con captura obligatoria de motivo (Heurísticas 5 y 9).
         */
        actionRejectPayment: function () {
            var reasons = [
                { value: '', label: '-- Seleccione un motivo obligatorio --' },
                { value: 'wrong_amount', label: 'Monto no coincide con la cuota esperada' },
                { value: 'transfer_not_found', label: 'Transferencia no figura en movimientos bancarios' },
                { value: 'invalid_account', label: 'Transferencia realizada a cuenta incorrecta / no autorizada' },
                { value: 'unreadable_voucher', label: 'Comprobante ilegible, borroso o incompleto' },
                { value: 'duplicate_operation', label: 'Número de operación bancaria duplicado o ya utilizado' },
                { value: 'other', label: 'Otro motivo (especificar en notas)' }
            ];

            var optionsHtml = '';
            reasons.forEach(function (r) {
                optionsHtml += '<option value="' + r.value + '">' + r.label + '</option>';
            });

            var modalHtml = $(
                '<div class="modal fade" tabindex="-1" role="dialog" id="payment-reject-modal">' +
                    '<div class="modal-dialog" role="document">' +
                        '<div class="modal-content">' +
                            '<div class="modal-header" style="background:#ffebee; border-bottom:1px solid #ffcdd2;">' +
                                '<button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>' +
                                '<h4 class="modal-title" style="color:#c62828; font-weight:700;"><i class="fas fa-ban"></i> Rechazar Comprobante de Pago</h4>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<p class="text-muted" style="margin-bottom:15px;">Indique el motivo estructurado del rechazo para notificar al asesor y viajero el motivo de subsanación requerido.</p>' +
                                '<div class="form-group">' +
                                    '<label for="modal-rejection-reason" style="font-weight:600;">Motivo de Rechazo <span class="text-danger">*</span></label>' +
                                    '<select class="form-control" id="modal-rejection-reason">' +
                                        optionsHtml +
                                    '</select>' +
                                '</div>' +
                                '<div class="form-group" style="margin-top:12px;">' +
                                    '<label for="modal-verification-notes" style="font-weight:600;">Notas de Verificación y Auditoría</label>' +
                                    '<textarea class="form-control" id="modal-verification-notes" rows="3" placeholder="Detalles u observaciones específicas sobre la transferencia o comprobante..."></textarea>' +
                                '</div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>' +
                                '<button type="button" class="btn btn-danger btn-submit-rejection" disabled>' +
                                    '<i class="fas fa-times"></i> Confirmar Rechazo' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );

            $('body').append(modalHtml);
            var $modal = $('#payment-reject-modal');
            var $select = $modal.find('#modal-rejection-reason');
            var $submitBtn = $modal.find('.btn-submit-rejection');
            var $notes = $modal.find('#modal-verification-notes');

            // Heurística 5: Deshabilitar el botón de envío hasta que se seleccione un motivo válido
            $select.on('change', function () {
                var selected = $(this).val();
                if (selected && selected.trim() !== '') {
                    $submitBtn.prop('disabled', false);
                } else {
                    $submitBtn.prop('disabled', true);
                }
            });

            $submitBtn.on('click', function () {
                var reason = $select.val();
                var notes = $notes.val();

                if (!reason) {
                    Espo.Ui.error('Debe seleccionar un motivo de rechazo.');
                    return;
                }

                $modal.modal('hide');
                Espo.Ui.notifyWait();

                Espo.Ajax.postRequest('Payment/action/reject', {
                    id: this.model.id,
                    rejectionReason: reason,
                    verificationNotes: notes
                }).then(function (response) {
                    Espo.Ui.success(response.message || 'Cobro rechazado correctamente.');
                    this.model.fetch();
                }.bind(this)).catch(function (xhr) {
                    var errorMsg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Error al rechazar el cobro.';
                    Espo.Ui.error(errorMsg);
                });
            }.bind(this));

            $modal.on('hidden.bs.modal', function () {
                $modal.remove();
            });

            $modal.modal('show');
        }
    });
});
