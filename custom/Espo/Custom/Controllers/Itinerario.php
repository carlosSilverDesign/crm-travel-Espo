<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Templates\Controllers\Base;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;

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

    /**
     * Retorna el resumen operativo en destino para la consola/tarjeta del tablero (TASK-045).
     *
     * Heurística 6 de Nielsen y Ley de Miller (Chunking):
     * - Nombre del titular y total de acompañantes (Pax).
     * - Servicio activo de la jornada (Vuelo, Tour, Traslado) con proveedor local asignado.
     * - Teléfono local de emergencia del operador/guía.
     * - Enlace directo al chat de Chatwoot.
     *
     * Umbral de Doherty: Tiempo de resolución instantáneo (<400ms).
     */
    public function actionOperationalSummary($params, $data, $request): array
    {
        $id = $request->get('id') ?? ($params['id'] ?? null);

        if (!$id) {
            throw new BadRequest("Parámetro 'id' es requerido.");
        }

        $em = $this->getEntityManager();
        /** @var \Espo\ORM\Entity|null $itinerario */
        $itinerario = $em->getEntity('Itinerario', $id);

        if (!$itinerario) {
            throw new NotFound("Itinerario '{$id}' no encontrado.");
        }

        $today = date('Y-m-d');

        // 1. Titular y Pasajeros
        $passengers = $em->getRDBRepository('Passenger')
            ->where(['itinerarioId' => $id])
            ->order('createdAt', 'ASC')
            ->find();

        $paxCount = count($passengers);
        $leadPassengerName = 'Sin pasajeros registrados';

        if ($paxCount > 0) {
            $leadPassengerName = $passengers[0]->get('name');
        } else {
            $oppId = $itinerario->get('opportunityId');
            if ($oppId) {
                $opp = $em->getEntity('Opportunity', $oppId);
                if ($opp && $opp->get('contactId')) {
                    $contact = $em->getEntity('Contact', $opp->get('contactId'));
                    if ($contact) {
                        $leadPassengerName = $contact->get('name');
                        $paxCount = 1;
                    }
                }
            }
        }

        // 2. Chatwoot
        $chatwootUrl = null;
        $chatwootConversationId = null;
        $oppId = $itinerario->get('opportunityId');
        if ($oppId) {
            $opp = $em->getEntity('Opportunity', $oppId);
            if ($opp) {
                $chatwootConversationId = $opp->get('chatwootConversationId');
                if ($chatwootConversationId) {
                    $chatwootUrl = "/app/accounts/1/conversations/{$chatwootConversationId}";
                }
            }
        }

        // 3. Servicio Activo de Hoy (ItineraryItem con fecha de hoy o más próximo)
        $todaysItem = $em->getRDBRepository('ItineraryItem')
            ->where([
                'itinerarioId' => $id,
                'serviceDate>=' => $today . ' 00:00:00',
                'serviceDate<=' => $today . ' 23:59:59',
            ])
            ->order('serviceDate', 'ASC')
            ->findOne();

        if (!$todaysItem) {
            $todaysItem = $em->getRDBRepository('ItineraryItem')
                ->where(['itinerarioId' => $id])
                ->order('serviceDate', 'ASC')
                ->findOne();
        }

        $serviceData = [
            'name' => 'Sin servicios programados',
            'serviceType' => 'N/A',
            'serviceDate' => null,
            'supplierName' => 'No asignado',
            'emergencyPhone' => null,
        ];

        if ($todaysItem) {
            $serviceData['name'] = $todaysItem->get('name');
            $serviceData['serviceType'] = $todaysItem->get('serviceType');
            $serviceData['serviceDate'] = $todaysItem->get('serviceDate');

            $supplierId = $todaysItem->get('supplierId');
            if ($supplierId) {
                $supplier = $em->getEntity('Supplier', $supplierId);
                if ($supplier) {
                    $serviceData['supplierName'] = $supplier->get('name');
                    $serviceData['emergencyPhone'] = $supplier->get('contactPhone');
                }
            }
        }

        return [
            'itinerarioId' => $itinerario->getId(),
            'name' => $itinerario->get('name'),
            'destination' => $itinerario->get('destination'),
            'status' => $itinerario->get('status'),
            'startDate' => $itinerario->get('startDate'),
            'endDate' => $itinerario->get('endDate'),
            'leadPassengerName' => $leadPassengerName,
            'paxCount' => $paxCount,
            'todaysActiveService' => $serviceData['name'],
            'serviceType' => $serviceData['serviceType'],
            'assignedSupplierName' => $serviceData['supplierName'],
            'emergencyPhone' => $serviceData['emergencyPhone'],
            'chatwootConversationId' => $chatwootConversationId,
            'chatwootChatUrl' => $chatwootUrl,
        ];
    }
}
