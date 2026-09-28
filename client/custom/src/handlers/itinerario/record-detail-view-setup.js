/**
 * TravelOps - Módulo de Experiencia de Usuario y Visualización de Itinerarios (EspoCRM v8)
 * Monta: Stepper Visual de Estado, Hero Bar Financiero, Portal Web del Viajero y Consola Operativa en Destino.
 */
(function () {
    'use strict';

    function registerAmdStubs() {
        var stubHandler = function () {
            return {
                setup: function () {},
                process: function () {}
            };
        };

        if (typeof define === 'function' && define.amd) {
            try {
                define('custom:handlers/itinerario/record-detail-view-setup', [], function () { return stubHandler; });
                define('handlers/itinerario/record-detail-view-setup', [], function () { return stubHandler; });
            } catch (e) {}
        }
    }
    registerAmdStubs();
})();
