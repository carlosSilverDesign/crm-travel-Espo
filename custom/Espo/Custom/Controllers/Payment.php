<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Controlador de EspoCRM para la entidad Payment.
 * Extiende Base para proveer automáticamente las acciones CRUD estándar (list, read, create, update, delete)
 * y expone los endpoints de decisión de mesa de control (confirm, reject).
 */
class Payment extends Base
{
    public function postActionConfirm(...$args)
    {
        $controller = new PaymentController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->postActionConfirm(...$args);
    }

    public function actionConfirm(...$args)
    {
        $controller = new PaymentController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->postActionConfirm(...$args);
    }

    public function postActionReject(...$args)
    {
        $controller = new PaymentController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->postActionReject(...$args);
    }

    public function actionReject(...$args)
    {
        $controller = new PaymentController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->postActionReject(...$args);
    }
}
