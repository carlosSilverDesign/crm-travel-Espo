<?php

namespace Espo\Custom\Hooks\Itinerario;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ConfirmationGate
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function beforeSave(Entity $entity, array $options = []): void
    {
        // Solo validar si el estado está transicionando a 'Confirmado'
        if (!$entity->isAttributeChanged('status') || $entity->get('status') !== 'Confirmado') {
            return;
        }

        // REGLA 1: Vigencia de Tarifas
        $quoteValidUntil = $entity->get('quoteValidUntil');

        if (!empty($quoteValidUntil)) {
            if (strtotime($quoteValidUntil) < time()) {
                throw new BadRequest(
                    "La cotización ha expirado. Actualice la fecha de vigencia o recotice las tarifas antes de confirmar."
                );
            }
        }

        // REGLA 2: Cuadre Financiero
        $totalSelling = (float) ($entity->get('totalSelling') ?? 0.0);

        $itinerarioId = $entity->hasId() ? $entity->getId() : null;
        $sumScheduled = 0.0;

        if ($itinerarioId) {
            $paymentSchedules = $this->getEntityManager()
                ->getRDBRepository('PaymentSchedule')
                ->where(['itinerarioId' => $itinerarioId])
                ->find();

            foreach ($paymentSchedules as $schedule) {
                $sumScheduled += (float) ($schedule->get('amount') ?? 0.0);
            }
        }

        if (abs($totalSelling - $sumScheduled) > 0.01) {
            throw new BadRequest(
                "Descuadre financiero: El total de venta ($totalSelling) difiere de la suma de cobros programados ($sumScheduled). Ajuste el cronograma de pagos."
            );
        }
    }
}
