<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Hooks\Feedback\ClassifyNpsScore;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClassifyNpsScoreHookTest extends TestCase
{
    /**
     * Valida que un NPS Score menor a 1 arroje BadRequest (Heurística 5).
     */
    public function testBeforeSaveThrowsBadRequestWhenScoreIsLowerThanOne(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El NPS Score debe ubicarse estrictamente entre 1 y 10.");

        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        $entityMock = $this->createMock(Entity::class);
        $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 0 : null);

        $hook->beforeSave($entityMock);
    }

    /**
     * Valida que un NPS Score mayor a 10 arroje BadRequest (Heurística 5).
     */
    public function testBeforeSaveThrowsBadRequestWhenScoreIsGreaterThanTen(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El NPS Score debe ubicarse estrictamente entre 1 y 10.");

        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        $entityMock = $this->createMock(Entity::class);
        $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? 11 : null);

        $hook->beforeSave($entityMock);
    }

    /**
     * Valida que un NPS Score nulo o inválido arroje BadRequest.
     */
    public function testBeforeSaveThrowsBadRequestWhenScoreIsNull(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("El NPS Score debe ubicarse estrictamente entre 1 y 10.");

        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        $entityMock = $this->createMock(Entity::class);
        $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? null : null);

        $hook->beforeSave($entityMock);
    }

    /**
     * Valida la clasificación para Promotores (NPS 9 y 10).
     */
    public function testBeforeSaveClassifiesPromoterWhenScoreIs9Or10(): void
    {
        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        foreach ([9, 10] as $score) {
            $assigned = [];
            $entityMock = $this->createMock(Entity::class);
            $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? $score : null);
            $entityMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$assigned, $entityMock) {
                $assigned[$k] = $v;
                return $entityMock;
            });

            $hook->beforeSave($entityMock);

            $this->assertEquals('Promoter', $assigned['sentiment']);
            $this->assertFalse($assigned['followUpRequired']);
            $this->assertEquals('NotNeeded', $assigned['followUpStatus']);
            $this->assertEquals($score, $assigned['npsScore']);
        }
    }

    /**
     * Valida la clasificación para Pasivos (NPS 7 y 8).
     */
    public function testBeforeSaveClassifiesPassiveWhenScoreIs7Or8(): void
    {
        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        foreach ([7, 8] as $score) {
            $assigned = [];
            $entityMock = $this->createMock(Entity::class);
            $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? $score : null);
            $entityMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$assigned, $entityMock) {
                $assigned[$k] = $v;
                return $entityMock;
            });

            $hook->beforeSave($entityMock);

            $this->assertEquals('Passive', $assigned['sentiment']);
            $this->assertFalse($assigned['followUpRequired']);
            $this->assertEquals('NotNeeded', $assigned['followUpStatus']);
            $this->assertEquals($score, $assigned['npsScore']);
        }
    }

    /**
     * Valida la clasificación para Detractores (NPS 1 a 6) con alerta de seguimiento (Heurística 9).
     */
    public function testBeforeSaveClassifiesDetractorWhenScoreIs6OrLess(): void
    {
        $emStub = $this->createMock(EntityManager::class);
        $hook = new ClassifyNpsScore($emStub);

        foreach ([1, 4, 6] as $score) {
            $assigned = [];
            $entityMock = $this->createMock(Entity::class);
            $entityMock->method('get')->willReturnCallback(fn($k) => $k === 'npsScore' ? $score : null);
            $entityMock->method('set')->willReturnCallback(function ($k, $v = null) use (&$assigned, $entityMock) {
                $assigned[$k] = $v;
                return $entityMock;
            });

            $hook->beforeSave($entityMock);

            $this->assertEquals('Detractor', $assigned['sentiment']);
            $this->assertTrue($assigned['followUpRequired']);
            $this->assertEquals('Pending', $assigned['followUpStatus']);
            $this->assertEquals($score, $assigned['npsScore']);
        }
    }

    /**
     * Valida que afterSave genere una tarea atómica con prioridad 'Urgent'
     * cuando un nuevo Feedback ingresa clasificado como Detractor.
     */
    public function testAfterSaveCreatesUrgentTaskForNewDetractor(): void
    {
        $taskValues = [];
        $taskMock = $this->createMock(Entity::class);
        $taskMock->method('set')->willReturnCallback(function ($data, $val = null) use (&$taskValues, $taskMock) {
            if (is_array($data)) {
                $taskValues = array_merge($taskValues, $data);
            } else {
                $taskValues[$data] = $val;
            }
            return $taskMock;
        });

        $emMock = $this->createMock(EntityManager::class);
        $emMock->expects($this->once())
            ->method('getNewEntity')
            ->with('Task')
            ->willReturn($taskMock);

        $emMock->expects($this->once())
            ->method('saveEntity')
            ->with($taskMock);

        $hook = new ClassifyNpsScore($emMock);

        $feedbackMock = $this->createMock(Entity::class);
        $feedbackMock->method('getId')->willReturn('feed-1001');
        $feedbackMock->method('isNew')->willReturn(true);
        $feedbackMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'           => 'Encuesta Cusco - Pedro Viajero',
            'npsScore'       => 3,
            'sentiment'      => 'Detractor',
            'comments'       => 'El hotel no tenía agua caliente y el transfer llegó 40 minutos tarde.',
            'assignedUserId' => 'user-support-01',
            default          => null,
        });

        $hook->afterSave($feedbackMock, ['isNew' => true]);

        $this->assertEquals('Atención Urgente Detractor: Encuesta Cusco - Pedro Viajero', $taskValues['name']);
        $this->assertEquals('Urgent', $taskValues['priority']);
        $this->assertEquals('Not Started', $taskValues['status']);
        $this->assertEquals('Feedback', $taskValues['parentType']);
        $this->assertEquals('feed-1001', $taskValues['parentId']);
        $this->assertStringContainsString('Calificación: 3', $taskValues['description']);
        $this->assertStringContainsString('Comentarios: El hotel no tenía agua caliente', $taskValues['description']);
        $this->assertEquals('user-support-01', $taskValues['assignedUserId']);
    }

    /**
     * Valida que afterSave NO cree tareas cuando el Feedback es Promoter o Passive.
     */
    public function testAfterSaveDoesNotCreateTaskForPromoterOrPassive(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $emMock->expects($this->never())->method('getNewEntity');
        $emMock->expects($this->never())->method('saveEntity');

        $hook = new ClassifyNpsScore($emMock);

        foreach (['Promoter', 'Passive'] as $sentiment) {
            $feedbackMock = $this->createMock(Entity::class);
            $feedbackMock->method('get')->willReturnCallback(fn($k) => $k === 'sentiment' ? $sentiment : null);
            $feedbackMock->method('isNew')->willReturn(true);

            $hook->afterSave($feedbackMock);
        }
    }

    /**
     * Valida que afterSave cree una tarea si un Feedback existente cambia su sentimiento a Detractor.
     */
    public function testAfterSaveCreatesUrgentTaskWhenExistingFeedbackChangesToDetractor(): void
    {
        $taskValues = [];
        $taskMock = $this->createMock(Entity::class);
        $taskMock->method('set')->willReturnCallback(function ($data, $val = null) use (&$taskValues, $taskMock) {
            if (is_array($data)) {
                $taskValues = array_merge($taskValues, $data);
            }
            return $taskMock;
        });

        $emMock = $this->createMock(EntityManager::class);
        $emMock->expects($this->once())
            ->method('getNewEntity')
            ->with('Task')
            ->willReturn($taskMock);

        $emMock->expects($this->once())
            ->method('saveEntity')
            ->with($taskMock);

        $hook = new ClassifyNpsScore($emMock);

        $feedbackMock = $this->createMock(Entity::class);
        $feedbackMock->method('getId')->willReturn('feed-2002');
        $feedbackMock->method('isNew')->willReturn(false);
        $feedbackMock->method('isAttributeChanged')->willReturnCallback(fn($k) => $k === 'sentiment');
        $feedbackMock->method('getFetched')->willReturnCallback(fn($k) => $k === 'sentiment' ? 'Passive' : null);
        $feedbackMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'           => 'Encuesta Arequipa',
            'npsScore'       => 5,
            'sentiment'      => 'Detractor',
            'comments'       => 'Pésimo servicio en tour colca.',
            'assignedUserId' => null,
            '_wasNew'        => false,
            default          => null,
        });

        $hook->afterSave($feedbackMock);

        $this->assertEquals('Atención Urgente Detractor: Encuesta Arequipa', $taskValues['name']);
        $this->assertEquals('Urgent', $taskValues['priority']);
    }

    /**
     * Valida que afterSave NO duplique la tarea si un registro existente ya era Detractor
     * y se actualiza algún otro campo sin variar el sentimiento.
     */
    public function testAfterSaveDoesNotDuplicateTaskWhenExistingDetractorRemainsDetractor(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $emMock->expects($this->never())->method('getNewEntity');
        $emMock->expects($this->never())->method('saveEntity');

        $hook = new ClassifyNpsScore($emMock);

        $feedbackMock = $this->createMock(Entity::class);
        $feedbackMock->method('isNew')->willReturn(false);
        $feedbackMock->method('isAttributeChanged')->willReturnCallback(fn($k) => false);
        $feedbackMock->method('getFetched')->willReturnCallback(fn($k) => match($k) {
            'sentiment' => 'Detractor',
            'id'        => 'feed-3003',
            default     => null
        });
        $feedbackMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'sentiment' => 'Detractor',
            '_wasNew'   => false,
            default     => null,
        });

        $hook->afterSave($feedbackMock);
    }
}
