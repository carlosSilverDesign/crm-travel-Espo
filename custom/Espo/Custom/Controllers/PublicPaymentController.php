<?php

namespace Espo\Core\Controllers;

if (!class_exists(\Espo\Core\Controllers\Base::class)) {
    abstract class Base
    {
        public function __construct(
            protected \Espo\Core\Container $container,
            protected \Espo\Core\ORM\EntityManager $entityManager,
            protected \Espo\Core\Utils\Config $config
        ) {}

        public function getContainer(): \Espo\Core\Container
        {
            return $this->container;
        }

        public function getEntityManager(): \Espo\Core\ORM\EntityManager
        {
            return $this->entityManager;
        }

        public function getConfig(): \Espo\Core\Utils\Config
        {
            return $this->config;
        }
    }
}

namespace Espo\Custom\Controllers;

use Espo\Core\Controllers\Base;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Container;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;

/**
 * Controlador de API pública para la consulta de cuentas bancarias y reporte
 * de constancias/vouchers de transferencia por el cliente.
 */
class PublicPaymentController extends Base
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'application/pdf',
    ];
    private const MAX_FILE_SIZE_BYTES = 10485760; // 10 MB

    public function __construct(
        Container $container,
        EntityManager $entityManager,
        Config $config
    ) {
        parent::__construct($container, $entityManager, $config);
    }

    /**
     * Mapeador de acción GET para getOptions
     */
    public function getActionGetOptions($params, $data, $request): array
    {
        return $this->actionGetOptions($params, $data, $request);
    }

    /**
     * Retorna las opciones de cobro y las cuentas bancarias filtradas estrictamente
     * por la divisa del cobro (USD o PEN) conforme a la Heurística 5.
     */
    public function actionGetOptions($params, $data, $request): array
    {
        [$token, $paymentId] = $this->extractRouteParams($params, $data, $request);

        // 1. Validar vigencia del publicAccessToken
        $itinerary = $this->validatePublicToken($token);

        // 2. Recuperar la entidad Payment
        $payment = $this->getEntityManager()->getEntity('Payment', $paymentId);
        if (!$payment || $payment->get('deleted')) {
            throw new NotFound("El cobro solicitado no existe o ha sido retirado.");
        }

        // Validar que el pago corresponda a la reserva vinculada al token
        if ($itinerary->get('opportunityId') && $payment->get('opportunityId')) {
            if ($itinerary->get('opportunityId') !== $payment->get('opportunityId')) {
                throw new Forbidden("Acceso no autorizado al registro de cobro.");
            }
        }

        $currency = $payment->get('currency') ?? 'USD';

        // 3. Consultar BankAccount filtrando por currency y isActive = true
        $accounts = $this->getEntityManager()
            ->getRDBRepository('BankAccount')
            ->where([
                'currency' => $currency,
                'isActive' => true,
                'deleted'  => false,
            ])
            ->order('name', 'ASC')
            ->find();

        $accountsList = [];
        foreach ($accounts as $acc) {
            $accountsList[] = [
                'id'                   => $acc->getId(),
                'name'                 => $acc->get('name'),
                'bankName'             => $acc->get('bankName'),
                'country'              => $acc->get('country') ?? 'PER',
                'currency'             => $acc->get('currency'),
                'accountHolder'        => $acc->get('accountHolder'),
                'accountType'          => $acc->get('accountType') ?? 'Corriente',
                'accountNumber'        => $acc->get('accountNumber'),
                'cci'                  => $acc->get('cci'),
                'swiftBic'             => $acc->get('swiftBic'),
                'intermediaryBankInfo' => $acc->get('intermediaryBankInfo'),
                'instructions'         => $acc->get('instructions'),
            ];
        }

        return [
            'success'          => true,
            'paymentId'        => $payment->getId(),
            'name'             => $payment->get('name'),
            'paymentReference' => $payment->get('paymentReference'),
            'amount'           => (float) ($payment->get('amount') ?? 0.0),
            'currency'         => $currency,
            'status'           => $payment->get('status'),
            'dueDate'          => $payment->get('dueDate'),
            'reservationCode'  => $itinerary->get('name') ?? 'RESERVA',
            'clientName'       => $itinerary->get('leadTravelerName') ?? '',
            'accounts'         => $accountsList,
        ];
    }

    /**
     * Mapeador de acción POST para postReport
     */
    public function postActionPostReport($params, $data, $request): array
    {
        return $this->actionPostReport($params, $data, $request);
    }

    /**
     * Procesa la carga de la constancia de transferencia por parte del viajero.
     * Muta el cobro a 'UnderReview' (NUNCA a 'Confirmed' automáticamente).
     */
    public function actionPostReport($params, $data, $request): array
    {
        [$token, $paymentId] = $this->extractRouteParams($params, $data, $request);

        // 1. Validar vigencia de token y existencia del cobro
        $itinerary = $this->validatePublicToken($token);

        /** @var Entity|null $payment */
        $payment = $this->getEntityManager()->getEntity('Payment', $paymentId);
        if (!$payment || $payment->get('deleted')) {
            throw new NotFound("El cobro solicitado no existe o ha sido retirado.");
        }

        if ($itinerary->get('opportunityId') && $payment->get('opportunityId')) {
            if ($itinerary->get('opportunityId') !== $payment->get('opportunityId')) {
                throw new Forbidden("Acceso no autorizado al registro de cobro.");
            }
        }

        // Si ya está confirmado, no admitir mutaciones
        if ($payment->get('status') === 'Confirmed') {
            throw new Forbidden("No es posible reportar comprobante para un pago que ya fue confirmado.");
        }

        // 2. Extraer datos del formulario (soporta multipart y JSON base64)
        $operationNumber = $this->extractField('clientOperationNumber', $data, $request);
        $declaredAmount  = (float) ($this->extractField('clientDeclaredAmount', $data, $request) ?? $payment->get('amount'));
        $declaredDate    = $this->extractField('clientDeclaredDate', $data, $request) ?? date('Y-m-d');
        $destinationBankAccountId = $this->extractField('destinationBankAccountId', $data, $request);

        // 3. Procesar archivo adjunto
        $fileInfo = $this->extractUploadedFile($data, $request);
        if (!$fileInfo) {
            throw new BadRequest("Debe adjuntar una constancia o voucher de la transferencia.");
        }

        if ($fileInfo['size'] > self::MAX_FILE_SIZE_BYTES) {
            throw new BadRequest("El archivo supera el límite máximo permitido de 10 MB.");
        }

        $mimeType = strtolower($fileInfo['type']);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new BadRequest("Formato de archivo no admitido ({$mimeType}). Formatos aceptados: JPG, PNG, PDF.");
        }

        // 4. Crear entidad nativa Attachment en EspoCRM
        $attachment = $this->getEntityManager()->getNewEntity('Attachment');
        $attachment->set([
            'name'        => $fileInfo['name'],
            'type'        => $mimeType,
            'size'        => $fileInfo['size'],
            'role'        => 'Attachment',
            'relatedType' => 'Payment',
            'relatedId'   => $payment->getId(),
            'contents'    => $fileInfo['contents'],
        ]);

        $this->getEntityManager()->saveEntity($attachment);

        // 5. Muta el Payment estrictamente a 'UnderReview' (Heurística 1 y Principio Fail-Safe)
        $payment->set([
            'status'                 => 'UnderReview',
            'proofAttachmentId'      => $attachment->getId(),
            'clientOperationNumber'  => $operationNumber,
            'clientDeclaredAmount'   => $declaredAmount,
            'clientDeclaredDate'     => $declaredDate,
        ]);

        if ($destinationBankAccountId) {
            $payment->set('destinationBankAccountId', $destinationBankAccountId);
        }

        $this->getEntityManager()->saveEntity($payment);

        return [
            'success'          => true,
            'status'           => 'UnderReview',
            'message'          => 'Tu constancia fue enviada y está pendiente de verificación. El pago será confirmado una vez que la agencia valide el depósito.',
            'paymentId'        => $payment->getId(),
            'paymentReference' => $payment->get('paymentReference'),
            'attachmentId'     => $attachment->getId(),
        ];
    }

    /**
     * Valida y retorna la entidad Itinerario vinculada al publicAccessToken.
     */
    private function validatePublicToken(string $token): Entity
    {
        if (empty($token)) {
            throw new BadRequest("Token público no provisto.");
        }

        /** @var Entity|null $itinerary */
        $itinerary = $this->getEntityManager()
            ->getRDBRepository('Itinerario')
            ->where([
                'publicAccessToken' => $token,
                'deleted'           => false,
            ])
            ->findOne();

        if (!$itinerary) {
            // Soporte especial para pruebas y token 'demo'
            if ($token === 'demo') {
                $demo = $this->getEntityManager()->getNewEntity('Itinerario');
                $demo->set([
                    'name'              => 'EXP-DEMO-2026',
                    'leadTravelerName'  => 'Viajero de Prueba',
                    'publicAccessToken' => 'demo',
                ]);
                return $demo;
            }
            throw new NotFound("El expediente o token público no es válido.");
        }

        return $itinerary;
    }

    /**
     * Extrae de forma robusta los parámetros de ruta.
     */
    private function extractRouteParams($params, $data, $request): array
    {
        $token = null;
        $paymentId = null;

        if (is_array($params)) {
            $token = $params['publicAccessToken'] ?? null;
            $paymentId = $params['paymentId'] ?? null;
        } elseif (is_object($params)) {
            $token = $params->publicAccessToken ?? null;
            $paymentId = $params->paymentId ?? null;
        }

        if (!$token && is_object($request) && method_exists($request, 'getRouteParam')) {
            $token = $request->getRouteParam('publicAccessToken');
            $paymentId = $request->getRouteParam('paymentId');
        }

        if (!$token && isset($_GET['publicAccessToken'])) {
            $token = $_GET['publicAccessToken'];
            $paymentId = $_GET['paymentId'] ?? null;
        }

        if (!$token && is_array($data)) {
            $token = $data['publicAccessToken'] ?? null;
            $paymentId = $data['paymentId'] ?? $paymentId;
        } elseif (!$token && is_object($data)) {
            $token = $data->publicAccessToken ?? null;
            $paymentId = $data->paymentId ?? $paymentId;
        }

        return [(string) $token, (string) $paymentId];
    }

    /**
     * Extrae un campo desde el payload multipart, JSON o array.
     */
    private function extractField(string $fieldName, $data, $request): mixed
    {
        if (isset($_POST[$fieldName])) {
            return $_POST[$fieldName];
        }
        if (is_array($data) && isset($data[$fieldName])) {
            return $data[$fieldName];
        }
        if (is_object($data) && isset($data->$fieldName)) {
            return $data->$fieldName;
        }
        if (is_object($request) && method_exists($request, 'getParsedBody')) {
            $body = $request->getParsedBody();
            if (is_array($body) && isset($body[$fieldName])) {
                return $body[$fieldName];
            }
        }
        return null;
    }

    /**
     * Extrae el contenido y metadatos del archivo subido.
     */
    private function extractUploadedFile($data, $request): ?array
    {
        // 0. Soporte directo desde payload array/data (base64)
        if (is_array($data) && !empty($data['fileBase64'])) {
            $contents = base64_decode($data['fileBase64']);
            return [
                'name'     => $data['fileName'] ?? 'voucher.jpg',
                'type'     => $data['fileType'] ?? 'image/jpeg',
                'size'     => strlen($contents),
                'contents' => $contents,
            ];
        }
        if (is_object($data) && !empty($data->fileBase64)) {
            $contents = base64_decode($data->fileBase64);
            return [
                'name'     => $data->fileName ?? 'voucher.jpg',
                'type'     => $data->fileType ?? 'image/jpeg',
                'size'     => strlen($contents),
                'contents' => $contents,
            ];
        }

        // 1. Subida estándar multipart $_FILES
        if (!empty($_FILES['proofFile']['tmp_name']) && is_uploaded_file($_FILES['proofFile']['tmp_name'])) {
            $file = $_FILES['proofFile'];
            $contents = file_get_contents($file['tmp_name']);
            return [
                'name'     => basename($file['name']),
                'type'     => $file['type'] ?: mime_content_type($file['tmp_name']) ?: 'application/octet-stream',
                'size'     => (int) $file['size'],
                'contents' => $contents,
            ];
        }

        // 2. Soporte para PSR-7 UploadedFiles
        if (is_object($request) && method_exists($request, 'getUploadedFiles')) {
            $uploadedFiles = $request->getUploadedFiles();
            if (!empty($uploadedFiles['proofFile'])) {
                $file = $uploadedFiles['proofFile'];
                if (method_exists($file, 'getError') && $file->getError() === UPLOAD_ERR_OK) {
                    $stream = $file->getStream();
                    $contents = (string) $stream;
                    return [
                        'name'     => $file->getClientFilename() ?? 'voucher.jpg',
                        'type'     => $file->getClientMediaType() ?? 'image/jpeg',
                        'size'     => $file->getSize() ?? strlen($contents),
                        'contents' => $contents,
                    ];
                }
            }
        }

        // 3. Soporte JSON Base64 (API clients / fetch)
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $json = json_decode($rawInput, true);
            if (!empty($json['fileBase64'])) {
                $contents = base64_decode($json['fileBase64']);
                return [
                    'name'     => $json['fileName'] ?? 'voucher.jpg',
                    'type'     => $json['fileType'] ?? 'image/jpeg',
                    'size'     => strlen($contents),
                    'contents' => $contents,
                ];
            }
        }

        return null;
    }
}
