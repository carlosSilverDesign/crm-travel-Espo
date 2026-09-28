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
use Espo\Entities\User;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use stdClass;

/**
 * Controlador para la mesa de auditoría y decisiones de cobro (TASK-041).
 * Permite a cajeros, asesores y administradores confirmar o rechazar
 * los pagos reportados por los clientes con comprobante bancario.
 */
class PaymentController extends Base
{
    public const ALLOWED_REJECTION_REASONS = [
        'wrong_amount',
        'transfer_not_found',
        'invalid_account',
        'unreadable_voucher',
        'duplicate_operation',
        'other',
    ];

    private ?User $user = null;
    private ?Acl $acl = null;

    public function __construct(
        Container $container,
        EntityManager $entityManager,
        Config $config,
        ?User $user = null,
        ?Acl $acl = null
    ) {
        parent::__construct($container, $entityManager, $config);
        $this->user = $user;
        $this->acl = $acl;
    }

    public function getUser(): User
    {
        if ($this->user === null) {
            $container = $this->getContainer();
            if ($container->has('user')) {
                $this->user = $container->get('user');
            }
        }

        if ($this->user === null) {
            throw new Forbidden("No hay sesión de usuario activa para auditar el pago.");
        }

        return $this->user;
    }

    public function getAcl(): ?Acl
    {
        if ($this->acl === null) {
            $container = $this->getContainer();
            if ($container->has('acl')) {
                $this->acl = $container->get('acl');
            }
        }

        return $this->acl;
    }

    /**
     * Valida permisos RBAC para auditar cobros (Admin, Finanzas, Cajero o edición en ACL).
     */
    public function checkAuditPermission(): void
    {
        $user = $this->getUser();

        if ($user->isAdmin()) {
            return;
        }

        $roles = $user->get('roles');
        if ($roles) {
            foreach ($roles as $role) {
                $roleName = strtolower($role->get('name') ?? '');
                if (str_contains($roleName, 'admin') || str_contains($roleName, 'finan') || str_contains($roleName, 'caj')) {
                    return;
                }
            }
        }

        $acl = $this->getAcl();
        if ($acl && $acl->check('Payment', 'edit')) {
            return;
        }

        throw new Forbidden("Acceso denegado. Se requieren permisos de Administración, Finanzas o Caja para auditar cobros.");
    }

    /**
     * Acción POST para confirmar un cobro.
     * Muta el estado a 'Confirmed', asigna auditor y fecha actual,
     * disparando la conciliación contable ACID de MySQL mediante hook.
     */
    public function postActionConfirm(...$args): array
    {
        return $this->handleConfirm($args);
    }

    /**
     * Alias de acción para routers que despachan 'actionConfirm'.
     */
    public function actionConfirm(...$args): array
    {
        return $this->handleConfirm($args);
    }

    /**
     * Acción POST para rechazar un cobro con motivo obligatorio.
     * Muta el estado a 'Rejected', asigna motivo, notas y auditor.
     */
    public function postActionReject(...$args): array
    {
        return $this->handleReject($args);
    }

    /**
     * Alias de acción para routers que despachan 'actionReject'.
     */
    public function actionReject(...$args): array
    {
        return $this->handleReject($args);
    }

    /**
     * Lógica central de confirmación.
     */
    private function handleConfirm(array $args): array
    {
        $this->checkAuditPermission();

        $paymentId = $this->extractPaymentId($args);
        if (empty($paymentId)) {
            throw new BadRequest("El identificador 'id' del cobro es obligatorio.");
        }

        $entityManager = $this->getEntityManager();
        $payment = $entityManager->getEntity('Payment', $paymentId);

        if (!$payment) {
            throw new NotFound("El cobro con ID '{$paymentId}' no fue encontrado.");
        }

        $currentStatus = $payment->get('status');

        if ($currentStatus === 'Confirmed') {
            throw new BadRequest("El cobro ya se encuentra confirmado y conciliado.");
        }

        if ($currentStatus === 'Canceled') {
            throw new BadRequest("No se puede confirmar un cobro que se encuentra cancelado.");
        }

        $currentUser = $this->getUser();
        $now = date('Y-m-d H:i:s');

        $payment->set([
            'status' => 'Confirmed',
            'verifiedById' => $currentUser->getId(),
            'verifiedAt' => $now,
        ]);

        // Guarda la entidad. Esto activa el hook FinancialReconciliation (TASK-039)
        // que recalcula atómicamente el balance de la Opportunity y liquida la cuota del PaymentSchedule.
        $entityManager->saveEntity($payment);

        return [
            'id' => $payment->getId(),
            'name' => $payment->get('name'),
            'status' => $payment->get('status'),
            'paymentReference' => $payment->get('paymentReference'),
            'amount' => $payment->get('amount'),
            'currency' => $payment->get('currency'),
            'verifiedById' => $payment->get('verifiedById'),
            'verifiedAt' => $payment->get('verifiedAt'),
            'opportunityId' => $payment->get('opportunityId'),
            'paymentScheduleId' => $payment->get('paymentScheduleId'),
            'message' => 'Cobro confirmado exitosamente y balance de la reserva actualizado.',
        ];
    }

    /**
     * Lógica central de rechazo.
     */
    private function handleReject(array $args): array
    {
        $this->checkAuditPermission();

        $paymentId = $this->extractPaymentId($args);
        if (empty($paymentId)) {
            throw new BadRequest("El identificador 'id' del cobro es obligatorio.");
        }

        $payload = $this->extractRejectPayload($args);
        $rejectionReason = $payload['rejectionReason'] ?? null;
        $verificationNotes = $payload['verificationNotes'] ?? null;

        if (empty($rejectionReason) || !in_array($rejectionReason, self::ALLOWED_REJECTION_REASONS, true)) {
            throw new BadRequest(
                "Debe especificar un motivo de rechazo válido ('rejectionReason'). Opciones permitidas: " .
                implode(', ', self::ALLOWED_REJECTION_REASONS)
            );
        }

        $entityManager = $this->getEntityManager();
        $payment = $entityManager->getEntity('Payment', $paymentId);

        if (!$payment) {
            throw new NotFound("El cobro con ID '{$paymentId}' no fue encontrado.");
        }

        if ($payment->get('status') === 'Confirmed') {
            throw new Forbidden("No es posible rechazar un cobro que ya ha sido confirmado (Inmutabilidad contable).");
        }

        $currentUser = $this->getUser();
        $now = date('Y-m-d H:i:s');

        $payment->set([
            'status' => 'Rejected',
            'rejectionReason' => $rejectionReason,
            'verificationNotes' => $verificationNotes,
            'verifiedById' => $currentUser->getId(),
            'verifiedAt' => $now,
        ]);

        $entityManager->saveEntity($payment);

        return [
            'id' => $payment->getId(),
            'name' => $payment->get('name'),
            'status' => $payment->get('status'),
            'rejectionReason' => $payment->get('rejectionReason'),
            'verificationNotes' => $payment->get('verificationNotes'),
            'verifiedById' => $payment->get('verifiedById'),
            'verifiedAt' => $payment->get('verifiedAt'),
            'message' => 'El comprobante ha sido rechazado correctamente. Se requiere subsanación por el cliente.',
        ];
    }

    /**
     * Extrae el ID del cobro a partir de los diferentes argumentos del kernel.
     */
    public function extractPaymentId(array $args): ?string
    {
        // 1. Desde el cuerpo decodificado ($data, segundo parámetro)
        $data = $args[1] ?? null;
        if ($data instanceof stdClass && !empty($data->id)) {
            return (string) $data->id;
        }
        if (is_array($data) && !empty($data['id'])) {
            return (string) $data['id'];
        }

        // 2. Desde parámetros de ruta ($params, primer parámetro)
        $params = $args[0] ?? [];
        if (is_array($params) && !empty($params['id'])) {
            return (string) $params['id'];
        }

        // 3. Desde objeto Request
        foreach ($args as $arg) {
            if ($arg instanceof Request) {
                $id = $arg->getRouteParam('id') ?? $arg->getQueryParam('id');
                if ($id) {
                    return (string) $id;
                }
                $parsedBody = $arg->getParsedBody();
                if ($parsedBody instanceof stdClass && !empty($parsedBody->id)) {
                    return (string) $parsedBody->id;
                }
                if (is_array($parsedBody) && !empty($parsedBody['id'])) {
                    return (string) $parsedBody['id'];
                }
            }
        }

        return null;
    }

    /**
     * Extrae el motivo de rechazo y las notas del payload recibido.
     */
    public function extractRejectPayload(array $args): array
    {
        $reason = null;
        $notes = null;

        $data = $args[1] ?? null;
        if ($data instanceof stdClass) {
            $reason = $data->rejectionReason ?? null;
            $notes = $data->verificationNotes ?? null;
        } elseif (is_array($data)) {
            $reason = $data['rejectionReason'] ?? null;
            $notes = $data['verificationNotes'] ?? null;
        }

        if ($reason === null) {
            foreach ($args as $arg) {
                if ($arg instanceof Request) {
                    $parsed = $arg->getParsedBody();
                    if ($parsed instanceof stdClass) {
                        $reason = $parsed->rejectionReason ?? $reason;
                        $notes = $parsed->verificationNotes ?? $notes;
                    } elseif (is_array($parsed)) {
                        $reason = $parsed['rejectionReason'] ?? $reason;
                        $notes = $parsed['verificationNotes'] ?? $notes;
                    }
                }
            }
        }

        return [
            'rejectionReason' => is_string($reason) ? trim($reason) : null,
            'verificationNotes' => is_string($notes) ? trim($notes) : null,
        ];
    }
}
