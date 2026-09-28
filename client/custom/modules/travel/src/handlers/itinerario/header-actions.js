define('custom:handlers/itinerario/header-actions', ['action-handler'], function (Dep) {
    'use strict';

    var BaseHandler = (Dep && Dep.default) ? Dep.default : Dep;

    var HeaderActions = class extends (BaseHandler || Object) {
        constructor(view) {
            super(view);
            this.view = view;
        }

        getRecordView() {
            if (!this.view) return null;
            if (this.view.model && typeof this.view.actionGeneratePdf === 'function') {
                return this.view;
            }
            if (typeof this.view.getParentView === 'function') {
                var parent = this.view.getParentView();
                if (parent && typeof parent.actionGeneratePdf === 'function') {
                    return parent;
                }
            }
            if (this.view.recordView) {
                return this.view.recordView;
            }
            return this.view;
        }

        actionGeneratePdf(data, event) {
            var recordView = this.getRecordView();
            if (recordView && typeof recordView.actionGeneratePdf === 'function') {
                recordView.actionGeneratePdf();
                return;
            }
            if (this.view && this.view.model) {
                var id = this.view.model.id;
                Espo.Ui.notifyWait('Generando expediente PDF...');
                Espo.Ajax.postRequest('Itinerario/action/generatePdf', { id: id })
                    .then(function (response) {
                        Espo.Ui.notify(false);
                        var attachId = (response && (response.attachmentId || response.id));
                        if (attachId) {
                            window.open('?entryPoint=download&id=' + encodeURIComponent(attachId), '_blank');
                            Espo.Ui.success('Expediente PDF generado correctamente.');
                        } else {
                            Espo.Ui.success('PDF generado exitosamente.');
                        }
                    })
                    .catch(function (xhr) {
                        Espo.Ui.notify(false);
                        Espo.Ui.error((xhr && xhr.responseJSON && xhr.responseJSON.message) || 'Error al generar el PDF del itinerario.');
                    });
            }
        }

        actionOpenPublicWeb(data, event) {
            var recordView = this.getRecordView();
            if (recordView && typeof recordView.actionOpenPublicWeb === 'function') {
                recordView.actionOpenPublicWeb();
                return;
            }
            if (this.view && this.view.model) {
                var token = this.view.model.get('publicAccessToken');
                if (!token) {
                    Espo.Ui.warning('El itinerario aún no tiene un token público asignado.');
                    return;
                }
                var baseUrl = 'http://localhost:8085';
                try {
                    if (typeof this.view.getConfig === 'function') {
                        var cfg = this.view.getConfig();
                        if (cfg && typeof cfg.get === 'function') {
                            baseUrl = cfg.get('travelWebExternalUrl') || baseUrl;
                        }
                    }
                } catch(e) {}
                window.open(baseUrl.replace(/\/$/, '') + '/p/' + token, '_blank');
            }
        }
    };

    return HeaderActions;
});
