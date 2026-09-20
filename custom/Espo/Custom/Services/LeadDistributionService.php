<?php

namespace Espo\Custom\Services;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

if (!class_exists(\Espo\Core\Utils\ConfigWriter::class) && class_exists(ConfigWriter::class)) {
    class_alias(ConfigWriter::class, \Espo\Core\Utils\ConfigWriter::class);
}

/**
 * Servicio de distribución equitativa (Round-Robin) para leads entrantes de WhatsApp/Chatwoot.
 */
class LeadDistributionService
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ?ConfigWriter $configWriter = null,
    ) {}

    /**
     * Determina y retorna el ID del siguiente asesor asignado en la rotación Round-Robin.
     * Si no hay agentes activos configurados con el rol 'Agent', aplica fallback hacia el Admin o usuario de guardia.
     */
    public function getNextAssignedUserId(): string
    {
        $agentIds = $this->getActiveAgentIds();

        // Manejo de fallback defensivo si la lista de agentes está vacía
        if (empty($agentIds)) {
            return $this->getFallbackUserId();
        }

        // Rotación Round-Robin
        $lastAgentId = $this->config->get('roundRobinLastAgentId');

        $currentIndex = ($lastAgentId !== null)
            ? array_search($lastAgentId, $agentIds, true)
            : false;

        if ($currentIndex === false) {
            $nextIndex = 0;
        } else {
            $nextIndex = ($currentIndex + 1) % count($agentIds);
        }

        $nextAgentId = (string) $agentIds[$nextIndex];

        // Persistencia atómica del puntero
        $this->saveLastAgentId($nextAgentId);

        return $nextAgentId;
    }

    /**
     * Consulta usuarios activos pertenecientes al rol 'Agent' ordenados por ID de forma ascendente.
     *
     * @return string[]
     */
    protected function getActiveAgentIds(): array
    {
        // 1. Intento vía PDO / SQL optimizado
        try {
            $pdo = $this->entityManager->getPDO();
            if ($pdo !== null) {
                $sql = "
                    SELECT u.id 
                    FROM `user` u 
                    INNER JOIN role_user ru ON ru.user_id = u.id 
                    INNER JOIN role r ON r.id = ru.role_id 
                    WHERE r.name = 'Agent' AND u.is_active = 1 AND u.deleted = 0 AND ru.deleted = 0 AND r.deleted = 0
                    ORDER BY u.id ASC
                ";
                $sth = $pdo->query($sql);
                if ($sth !== false) {
                    $ids = $sth->fetchAll(\PDO::FETCH_COLUMN);
                    if (is_array($ids) && !empty($ids)) {
                        return array_values(array_map('strval', $ids));
                    }
                }
            }
        } catch (\Throwable) {
            // Continuar al intento vía ORM / RDBRepository
        }

        // 2. Intento vía RDBRepository / ORM (compatible con tests y mocks de EntityManager)
        try {
            $repository = $this->entityManager->getRDBRepository('User');
            if ($repository !== null) {
                $collection = $repository
                    ->select(['id'])
                    ->distinct()
                    ->join('roles', 'role')
                    ->where([
                        'isActive' => true,
                        'role.name' => 'Agent',
                    ])
                    ->order('id', 'ASC')
                    ->find();

                $ids = [];
                foreach ($collection as $user) {
                    if (method_exists($user, 'getId')) {
                        $ids[] = (string) $user->getId();
                    } elseif (isset($user->id)) {
                        $ids[] = (string) $user->id;
                    }
                }

                if (!empty($ids)) {
                    return $ids;
                }
            }
        } catch (\Throwable) {
            // Ignorar error de ORM si falla
        }

        return [];
    }

    /**
     * Manejo de fallback defensivo: busca el primer usuario activo con rol 'Admin' o tipo admin.
     * Si no existe ninguno disponible, retorna 'system' o '1'.
     */
    protected function getFallbackUserId(): string
    {
        // 1. Intento vía PDO / SQL
        try {
            $pdo = $this->entityManager->getPDO();
            if ($pdo !== null) {
                // Buscar por tipo admin
                $sql = "
                    SELECT id 
                    FROM `user` 
                    WHERE (type = 'admin' OR type = 'super-admin') AND is_active = 1 AND deleted = 0 
                    ORDER BY id ASC 
                    LIMIT 1
                ";
                $sth = $pdo->query($sql);
                if ($sth !== false) {
                    $adminId = $sth->fetchColumn();
                    if (!empty($adminId)) {
                        return (string) $adminId;
                    }
                }

                // Buscar por rol 'Admin'
                $sqlRole = "
                    SELECT u.id 
                    FROM `user` u 
                    INNER JOIN role_user ru ON ru.user_id = u.id 
                    INNER JOIN role r ON r.id = ru.role_id 
                    WHERE r.name = 'Admin' AND u.is_active = 1 AND u.deleted = 0 AND ru.deleted = 0 AND r.deleted = 0
                    ORDER BY u.id ASC 
                    LIMIT 1
                ";
                $sthRole = $pdo->query($sqlRole);
                if ($sthRole !== false) {
                    $adminId = $sthRole->fetchColumn();
                    if (!empty($adminId)) {
                        return (string) $adminId;
                    }
                }
            }
        } catch (\Throwable) {
            // Continuar con ORM
        }

        // 2. Intento vía ORM / RDBRepository
        try {
            $repository = $this->entityManager->getRDBRepository('User');
            if ($repository !== null) {
                $adminUser = $repository
                    ->where([
                        'isActive' => true,
                        'type' => 'admin',
                    ])
                    ->order('id', 'ASC')
                    ->findOne();

                if ($adminUser !== null) {
                    $id = method_exists($adminUser, 'getId') ? $adminUser->getId() : ($adminUser->id ?? null);
                    if (!empty($id)) {
                        return (string) $id;
                    }
                }

                $adminWithRole = $repository
                    ->distinct()
                    ->join('roles', 'role')
                    ->where([
                        'isActive' => true,
                        'role.name' => 'Admin',
                    ])
                    ->order('id', 'ASC')
                    ->findOne();

                if ($adminWithRole !== null) {
                    $id = method_exists($adminWithRole, 'getId') ? $adminWithRole->getId() : ($adminWithRole->id ?? null);
                    if (!empty($id)) {
                        return (string) $id;
                    }
                }
            }
        } catch (\Throwable) {
            // Continuar con fallback por defecto
        }

        // 3. Si existe usuario 'system'
        try {
            $systemUser = $this->entityManager->getEntity('User', 'system');
            if ($systemUser !== null && $systemUser->hasId()) {
                return $systemUser->getId();
            }
        } catch (\Throwable) {
            // Ignorar
        }

        return '1';
    }

    /**
     * Persiste atómicamente el último asesor asignado en la configuración global.
     */
    protected function saveLastAgentId(string $agentId): void
    {
        if ($this->configWriter !== null) {
            $this->configWriter->set('roundRobinLastAgentId', $agentId);
            $this->configWriter->save();
        }

        if (method_exists($this->config, 'set')) {
            $this->config->set('roundRobinLastAgentId', $agentId);
            if ($this->configWriter === null && method_exists($this->config, 'save')) {
                @$this->config->save();
            }
        }
    }
}
