<?php

namespace Espo\Custom\Controllers;

/**
 * Alias de controlador para la entidad Payment.
 * Extiende PaymentController garantizando que el despachador de rutas
 * de EspoCRM resuelva de forma idéntica tanto 'Payment' como 'PaymentController'.
 */
class Payment extends PaymentController
{
}
