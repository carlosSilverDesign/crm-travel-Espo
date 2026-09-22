<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Templates\Controllers\Base;

class Itinerario extends Base
{
    /**
     * Acción POST para generar o recuperar el expediente PDF del itinerario.
     */
    public function postActionGeneratePdf(...$args)
    {
        $controller = new ItinerarioPdfController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->postActionGeneratePdf(...$args);
    }

    /**
     * Alias de acción para generar el expediente PDF.
     */
    public function actionGeneratePdf(...$args)
    {
        $controller = new ItinerarioPdfController($this->getContainer(), $this->getEntityManager(), $this->getConfig());
        return $controller->actionGeneratePdf(...$args);
    }
}
