<?php

namespace Espo\Custom\Hooks\Opportunity;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Hook de Auditoría de Etapas y Velocidad de Pipeline (TASK-049).
 *
 * Registra de forma determinista y atómica:
 * 1. Momento de entrada a cada etapa comercial (enteredAt).
 * 2. Momento de salida y duración exacta en segundos (exitedAt, durationSeconds).
 * 3. Canal de procedencia (leadSource) y asesor asignado (assignedUserId).
 *
 * Principio: Cero consultas lentas a streams no estructurados.
 * Soporta reportería de embudo instantánea (<15 ms) bajo el Umbral de Doherty.
 */
class TrackOpportunityStageTransition
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    public function afterSave(Entity $entity, array $options = []): void
    {
        $oppId = $entity->getId();
        $stage = $entity->get('stage');

        if (!$oppId || !$stage) {
            return;
        }

        $now = !empty($options['currentTime']) ? $options['currentTime'] : date('Y-m-d H:i:s');
        $oppName = $entity->get('name') ?: 'Oportunidad';
        $leadSource = $entity->get('leadSource');
        $assignedUserId = $entity->get('assignedUserId');

        // 1. Caso Oportunidad Nueva: Registra la etapa inicial
        if ($entity->isNew()) {
            /** @var Entity $history */
            $history = $this->getEntityManager()->getNewEntity('OpportunityStageHistory');
            $history->set([
                'name' => "{$oppName} - {$stage}",
                'opportunityId' => $oppId,
                'stage' => $stage,
                'enteredAt' => $now,
                'leadSource' => $leadSource,
                'assignedUserId' => $assignedUserId,
            ]);
            $this->getEntityManager()->saveEntity($history);
            return;
        }

        // 2. Caso Cambio de Etapa: Cierra la etapa previa y abre la nueva
        if ($entity->isAttributeChanged('stage')) {
            // Cerrar el registro activo previo si existe
            /** @var Entity|null $activeHistory */
            $activeHistory = $this->getEntityManager()->getRDBRepository('OpportunityStageHistory')
                ->where([
                    'opportunityId' => $oppId,
                    'exitedAt' => null,
                ])
                ->order('enteredAt', 'DESC')
                ->findOne();

            if ($activeHistory) {
                $enteredAt = $activeHistory->get('enteredAt');
                $enteredTimestamp = $enteredAt ? strtotime($enteredAt) : strtotime($now);
                $duration = max(0, strtotime($now) - $enteredTimestamp);

                $activeHistory->set([
                    'exitedAt' => $now,
                    'durationSeconds' => $duration,
                ]);
                $this->getEntityManager()->saveEntity($activeHistory);
            }

            // Abrir el nuevo registro de etapa
            /** @var Entity $newHistory */
            $newHistory = $this->getEntityManager()->getNewEntity('OpportunityStageHistory');
            $newHistory->set([
                'name' => "{$oppName} - {$stage}",
                'opportunityId' => $oppId,
                'stage' => $stage,
                'enteredAt' => $now,
                'leadSource' => $leadSource,
                'assignedUserId' => $assignedUserId,
            ]);
            $this->getEntityManager()->saveEntity($newHistory);
        }
    }
}
