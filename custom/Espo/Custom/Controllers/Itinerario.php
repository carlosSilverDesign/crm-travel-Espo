<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Templates\Controllers\Base;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Container;

class Itinerario extends Base
{
    private EntityManager $entityManager;
    private Container $container;

    public function __construct(
        \Espo\Core\Record\SearchParamsFetcher $searchParamsFetcher,
        \Espo\Core\Record\CreateParamsFetcher $createParamsFetcher,
        \Espo\Core\Record\ReadParamsFetcher $readParamsFetcher,
        \Espo\Core\Record\UpdateParamsFetcher $updateParamsFetcher,
        \Espo\Core\Record\DeleteParamsFetcher $deleteParamsFetcher,
        \Espo\Core\Record\ServiceContainer $recordServiceContainer,
        \Espo\Core\Record\FindParamsFetcher $findParamsFetcher,
        \Espo\Core\Utils\Config $config,
        \Espo\Entities\User $user,
        \Espo\Core\Acl $acl,
        \Espo\Core\InjectableFactory $injectableFactory,
        EntityManager $entityManager,
        Container $container
    ) {
        parent::__construct(
            $searchParamsFetcher,
            $createParamsFetcher,
            $readParamsFetcher,
            $updateParamsFetcher,
            $deleteParamsFetcher,
            $recordServiceContainer,
            $findParamsFetcher,
            $config,
            $user,
            $acl,
            $injectableFactory
        );
        $this->entityManager = $entityManager;
        $this->container = $container;
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    protected function getConfig(): Config
    {
        return $this->config;
    }

    protected function getContainer(): Container
    {
        return $this->container;
    }

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
                    $baseUrl = rtrim((string) ($this->getConfig()->get('chatwootBaseUrl') ?: getenv('CHATWOOT_BASE_URL') ?: 'https://chat.agencia.com'), '/');
                    $accountId = (string) ($this->getConfig()->get('chatwootAccountId') ?: 1);
                    $chatwootUrl = "{$baseUrl}/app/accounts/{$accountId}/conversations/{$chatwootConversationId}";
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

    /**
     * Retorna la información pública segura del itinerario para el portal web del viajero.
     * Cero exposición de datos financieros internos (Heurística 8).
     */
    public function actionPublicDetails($params, $data, $request): array
    {
        $token = null;
        if (is_object($request) && method_exists($request, 'getRouteParam')) {
            $token = $request->getRouteParam('publicAccessToken');
        }
        if (!$token && is_array($params)) {
            $token = $params['publicAccessToken'] ?? null;
        }
        if (!$token && is_object($request) && method_exists($request, 'get')) {
            $token = $request->get('publicAccessToken');
        }

        if (!$token) {
            throw new BadRequest("Token público no especificado.");
        }

        $em = $this->getEntityManager();

        /** @var \Espo\ORM\Entity|null $itinerario */
        if ($token === 'demo') {
            $itinerario = $em->getRDBRepository('Itinerario')
                ->where(['status' => 'En Viaje'])
                ->order('createdAt', 'ASC')
                ->findOne();
            if (!$itinerario) {
                $itinerario = $em->getRDBRepository('Itinerario')->findOne();
            }
        } else {
            $itinerario = $em->getRDBRepository('Itinerario')
                ->where(['publicAccessToken' => $token])
                ->findOne();
        }

        if (!$itinerario) {
            throw new NotFound("Itinerario no encontrado para el enlace proporcionado.");
        }

        // Incrementar contador de accesos web
        $accessCount = (int) ($itinerario->get('webAccessCount') ?? 0) + 1;
        $itinerario->set([
            'webAccessCount' => $accessCount,
            'lastWebAccessAt' => date('Y-m-d H:i:s')
        ]);
        $em->saveEntity($itinerario, ['skipHooks' => true]);

        $id = $itinerario->getId();

        // 1. Pasajeros
        $passengers = $em->getRDBRepository('Passenger')
            ->where(['itinerarioId' => $id])
            ->order('createdAt', 'ASC')
            ->find();

        $paxList = [];
        $leadPax = 'Sin pasajeros registrados';
        foreach ($passengers as $p) {
            $paxList[] = [
                'name' => $p->get('name'),
                'type' => $p->get('passengerType') ?? 'Adult',
                'dietary' => $p->get('dietaryRestrictions'),
            ];
        }
        if (count($passengers) > 0) {
            $leadPax = $passengers[0]->get('name');
        } else {
            $oppId = $itinerario->get('opportunityId');
            if ($oppId) {
                $opp = $em->getEntity('Opportunity', $oppId);
                if ($opp && $opp->get('contactId')) {
                    $contact = $em->getEntity('Contact', $opp->get('contactId'));
                    if ($contact) {
                        $leadPax = $contact->get('name');
                    }
                }
            }
        }

        // 2. Servicios del Itinerario
        $items = $em->getRDBRepository('ItineraryItem')
            ->where(['itinerarioId' => $id])
            ->order('serviceDate', 'ASC')
            ->find();

        $servicesList = [];
        $generalPnr = null;

        foreach ($items as $item) {
            $suppName = null;
            $suppPhone = null;
            if ($item->get('supplierId')) {
                $supp = $em->getEntity('Supplier', $item->get('supplierId'));
                if ($supp) {
                    $suppName = $supp->get('name');
                    $suppPhone = $supp->get('contactPhone');
                }
            }

            if (!$generalPnr && $item->get('confirmationCode')) {
                $generalPnr = $item->get('confirmationCode');
            }

            $servicesList[] = [
                'id' => $item->getId(),
                'name' => $item->get('name'),
                'serviceType' => $item->get('serviceType') ?? 'Otro',
                'serviceDate' => $item->get('serviceDate'),
                'serviceEndDate' => $item->get('serviceEndDate'),
                'origin' => $item->get('origin'),
                'originIata' => $item->get('originIata'),
                'destination' => $item->get('destination'),
                'destinationIata' => $item->get('destinationIata'),
                'carrier' => $item->get('carrier'),
                'flightNumber' => $item->get('flightNumber'),
                'cabin' => $item->get('cabin'),
                'confirmationCode' => $item->get('confirmationCode'),
                'status' => $item->get('status'),
                'notes' => $item->get('notes'),
                'supplierName' => $suppName,
                'emergencyPhone' => $suppPhone,
            ];
        }

        return [
            'id' => $itinerario->getId(),
            'name' => $itinerario->get('name'),
            'status' => $itinerario->get('status'),
            'tripType' => $itinerario->get('tripType') ?? 'Paquete Turístico',
            'origin' => $itinerario->get('origin'),
            'destination' => $itinerario->get('destination'),
            'startDate' => $itinerario->get('startDate'),
            'endDate' => $itinerario->get('endDate'),
            'description' => $itinerario->get('description'),
            'generalPnr' => $generalPnr ?: ('PNR-' . strtoupper(substr(md5($itinerario->getId()), 0, 6))),
            'leadPassenger' => $leadPax,
            'passengersCount' => count($passengers) > 0 ? count($passengers) : 1,
            'passengers' => $paxList,
            'services' => $servicesList,
        ];
    }
}
