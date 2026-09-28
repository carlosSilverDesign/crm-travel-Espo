<?php

namespace Espo\Custom\Hooks\Feedback;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClassifyNpsScore
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    protected function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * Valida el rango de NPS Score (1 a 10) y calcula automáticamente
     * la clasificación de sentimiento y el estado de seguimiento.
     *
     * Heurística 5: Prevención de errores (rango numérico estricto).
     * Ley de Tesler: Delegación del cálculo de sentimiento al backend.
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew() || !empty($options['isNew'])) {
            $entity->set('_wasNew', true);
        }

        $score = $entity->get('npsScore');

        // Validación estricta de rango (1 a 10)
        if ($score === null || !is_numeric($score) || (int) $score < 1 || (int) $score > 10) {
            throw new BadRequest("El NPS Score debe ubicarse estrictamente entre 1 y 10.");
        }

        $npsScore = (int) $score;
        $entity->set('npsScore', $npsScore);

        // Clasificación de sentimiento y requerimiento de seguimiento
        if ($npsScore >= 9) {
            $entity->set('sentiment', 'Promoter');
            $entity->set('followUpRequired', false);
            $entity->set('followUpStatus', 'NotNeeded');
        } elseif ($npsScore >= 7) {
            $entity->set('sentiment', 'Passive');
            $entity->set('followUpRequired', false);
            $entity->set('followUpStatus', 'NotNeeded');
        } else {
            $entity->set('sentiment', 'Detractor');
            $entity->set('followUpRequired', true);
            $entity->set('followUpStatus', 'Pending');
        }
    }

    /**
     * Si la entidad queda clasificada como Detractor en una creación nueva
     * o ante un cambio de sentimiento, crea atómicamente una tarea urgente.
     *
     * Peak-End Rule & Heurística 9: Alerta inmediata de remediación para detractores.
     */
    public function afterSave(Entity $entity, array $options = []): void
    {
        if ($entity->get('sentiment') !== 'Detractor') {
            return;
        }

        $isNew = $entity->isNew() ||
            !empty($options['isNew']) ||
            (bool) $entity->get('_wasNew') ||
            $entity->getFetched('sentiment') === null;

        $sentimentChanged = $entity->isAttributeChanged('sentiment') ||
            ($entity->getFetched('sentiment') !== null && $entity->getFetched('sentiment') !== 'Detractor');

        if (!$isNew && !$sentimentChanged) {
            return;
        }

        $comments = $entity->get('comments') ?? '';

        /** @var \Espo\ORM\Entity $task */
        $task = $this->getEntityManager()->getNewEntity('Task');
        $task->set([
            'name' => 'Atención Urgente Detractor: ' . $entity->get('name'),
            'priority' => 'Urgent',
            'status' => 'Not Started',
            'parentType' => 'Feedback',
            'parentId' => $entity->getId(),
            'description' => 'Calificación: ' . $entity->get('npsScore') . "\nComentarios: " . $comments,
        ]);

        if ($entity->get('assignedUserId')) {
            $task->set('assignedUserId', $entity->get('assignedUserId'));
        }

        $this->getEntityManager()->saveEntity($task);
    }
}
