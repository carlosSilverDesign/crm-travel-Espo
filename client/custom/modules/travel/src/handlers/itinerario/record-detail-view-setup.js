(function () {
    'use strict';
    var stubHandler = function () {
        return {
            setup: function () {},
            process: function () {}
        };
    };
    if (typeof define === 'function' && define.amd) {
        define('custom:handlers/itinerario/record-detail-view-setup', [], function () { return stubHandler; });
        define('handlers/itinerario/record-detail-view-setup', [], function () { return stubHandler; });
    }
})();
