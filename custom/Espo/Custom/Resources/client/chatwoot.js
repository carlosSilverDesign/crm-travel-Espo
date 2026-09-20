/**
 * Extensiones Client-Side para Integración WhatsApp / Chatwoot en Opportunity (TASK-023).
 */
define('custom:views/fields/chatwoot-conversation', ['views/fields/varchar'], function (VarcharField) {
    'use strict';

    return VarcharField.extend({
        type: 'varchar',

        getChatwootUrl: function () {
            var conversationId = this.model.get(this.name);
            if (!conversationId) {
                return null;
            }
            var baseUrl = (this.getConfig().get('chatwootBaseUrl') || 'https://chat.agencia.com').replace(/\/$/, '');
            var accountId = this.getConfig().get('chatwootAccountId') || 1;
            return baseUrl + '/app/accounts/' + accountId + '/conversations/' + conversationId;
        },

        afterRender: function () {
            VarcharField.prototype.afterRender.call(this);

            var url = this.getChatwootUrl();
            if (url && (this.mode === 'detail' || this.mode === 'list')) {
                if (!this.$el.find('.chatwoot-direct-link').length) {
                    var $link = $(
                        '<a href="' + url + '" target="_blank" rel="noopener noreferrer" ' +
                        'class="btn btn-default btn-xs chatwoot-direct-link" ' +
                        'style="margin-left: 8px; vertical-align: middle;" title="Abrir en Chatwoot">' +
                        '<i class="fab fa-whatsapp text-success" style="margin-right: 4px;"></i> Abrir en Chatwoot <i class="fas fa-external-link-alt fa-xs" style="margin-left: 3px;"></i>' +
                        '</a>'
                    );
                    this.$el.append($link);
                }
            }
        }
    });
});

define('custom:views/opportunity/record/detail', ['views/record/detail'], function (DetailRecordView) {
    'use strict';

    return DetailRecordView.extend({
        actionEscalateToAdmin: function () {
            this.confirm(
                '¿Desea reasignar esta oportunidad a Guardia / Admin?',
                function () {
                    Espo.Ui.notifyWait();
                    Espo.Ajax.getRequest('User', {
                        where: [
                            { type: 'equals', attribute: 'type', value: 'admin' },
                            { type: 'isTrue', attribute: 'isActive' }
                        ],
                        maxSize: 1
                    }).then(function (response) {
                        var adminUser = (response && response.list && response.list[0]) ? response.list[0] : null;
                        var adminId = adminUser ? adminUser.id : '6a94d891b65b39232';
                        var adminName = adminUser ? adminUser.name : 'Admin';

                        this.model.save({
                            assignedUserId: adminId,
                            assignedUserName: adminName
                        }, {
                            patch: true
                        }).then(function () {
                            Espo.Ui.success('Oportunidad reasignada a Guardia/Admin');
                        }).catch(function (err) {
                            Espo.Ui.error(err.message || 'Error al reasignar oportunidad');
                        });
                    }.bind(this)).catch(function (err) {
                        Espo.Ui.error(err.message || 'Error al consultar administrador de guardia');
                    });
                }.bind(this)
            );
        },

        actionOpenChatwoot: function () {
            var conversationId = this.model.get('chatwootConversationId');
            if (!conversationId) {
                Espo.Ui.warning('No hay identificador de conversación de Chatwoot asociado.');
                return;
            }
            var baseUrl = (this.getConfig().get('chatwootBaseUrl') || 'https://chat.agencia.com').replace(/\/$/, '');
            var accountId = this.getConfig().get('chatwootAccountId') || 1;
            var url = baseUrl + '/app/accounts/' + accountId + '/conversations/' + conversationId;
            window.open(url, '_blank');
        },

        afterRender: function () {
            DetailRecordView.prototype.afterRender.call(this);

            var conversationId = this.model.get('chatwootConversationId');
            if (conversationId) {
                var $cell = this.$el.find('[data-name="chatwootConversationId"]');
                if ($cell.length && !$cell.find('.chatwoot-direct-link').length) {
                    var baseUrl = (this.getConfig().get('chatwootBaseUrl') || 'https://chat.agencia.com').replace(/\/$/, '');
                    var accountId = this.getConfig().get('chatwootAccountId') || 1;
                    var url = baseUrl + '/app/accounts/' + accountId + '/conversations/' + conversationId;
                    var $btn = $(
                        '<a href="' + url + '" target="_blank" rel="noopener noreferrer" ' +
                        'class="btn btn-default btn-xs chatwoot-direct-link" ' +
                        'style="margin-left: 8px; vertical-align: middle;" title="Abrir en Chatwoot">' +
                        '<i class="fab fa-whatsapp text-success" style="margin-right: 4px;"></i> Abrir en Chatwoot <i class="fas fa-external-link-alt fa-xs" style="margin-left: 3px;"></i>' +
                        '</a>'
                    );
                    $cell.append($btn);
                }
            }
        }
    });
});
