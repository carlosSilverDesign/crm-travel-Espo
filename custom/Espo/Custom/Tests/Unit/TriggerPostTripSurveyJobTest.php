<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Jobs\TriggerPostTripSurveyJob;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Core\Templates\Repositories\Base as RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;

class TriggerPostTripSurveyJobTest extends TestCase
{
    /**
     * Valida la normalización de teléfonos a formato E.164.
     */
    public function testPhoneNormalizationToE164(): void
    {
        // Caso 1: Teléfono ya en E.164
        $this->assertEquals('+51987654321', TriggerPostTripSurveyJob::normalizePhone('+51987654321'));

        // Caso 2: Teléfono con espacios, guiones y paréntesis
        $this->assertEquals('+15551234567', TriggerPostTripSurveyJob::normalizePhone('+1 (555) 123-4567'));

        // Caso 3: Teléfono con prefijo internacional '00'
        $this->assertEquals('+34600112233', TriggerPostTripSurveyJob::normalizePhone('0034600112233'));

        // Caso 4: Teléfono local peruano de 9 dígitos iniciado en 9
        $this->assertEquals('+51999888777', TriggerPostTripSurveyJob::normalizePhone('999888777'));

        // Casos inválidos (deben retornar null según Ley de Postel)
        $this->assertNull(TriggerPostTripSurveyJob::normalizePhone(null));
        $this->assertNull(TriggerPostTripSurveyJob::normalizePhone(''));
        $this->assertNull(TriggerPostTripSurveyJob::normalizePhone('123'));
        $this->assertNull(TriggerPostTripSurveyJob::normalizePhone('invalido-phone'));
    }

    /**
     * Valida el ensamblado del payload con todos los campos requeridos.
     */
    public function testBuildPayloadWithValidPassengerAndContact(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $logMock = $this->createMock(Log::class);

        $itinerarioMock = $this->createMock(Entity::class);
        $itinerarioMock->method('getId')->willReturn('itin-101');
        $itinerarioMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'opportunityId' => 'opp-202',
            'destination'   => 'Cusco & Machu Picchu',
            default         => null,
        });

        $passengerMock = $this->createMock(Entity::class);
        $passengerMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'      => 'Alejandro Toledo',
            'contactId' => 'contact-303',
            default     => null,
        });

        $contactMock = $this->createMock(Entity::class);
        $contactMock->method('getId')->willReturn('contact-303');
        $contactMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'  => 'Alejandro Toledo',
            'phone' => '+51 987 111 222',
            default => null,
        });

        $passengerBuilder = $this->createStub(RDBSelectBuilder::class);
        $passengerBuilder->method('where')->willReturnSelf();
        $passengerBuilder->method('order')->willReturnSelf();
        $passengerBuilder->method('find')->willReturn(new EntityCollection([$passengerMock]));

        $passengerRepoMock = $this->createMock(RDBRepository::class);
        $passengerRepoMock->method('where')->willReturn($passengerBuilder);

        $emMock->method('getRDBRepository')
            ->with('Passenger')
            ->willReturn($passengerRepoMock);

        $emMock->method('getEntity')
            ->willReturnCallback(function ($type, $id) use ($contactMock) {
                if ($type === 'Contact' && $id === 'contact-303') {
                    return $contactMock;
                }
                return null;
            });

        $job = new TriggerPostTripSurveyJob($emMock, $configMock, $logMock);
        $payload = $job->buildPayload($itinerarioMock);

        $this->assertNotNull($payload);
        $this->assertEquals('itin-101', $payload['itineraryId']);
        $this->assertEquals('contact-303', $payload['contactId']);
        $this->assertEquals('+51987111222', $payload['phone']);
        $this->assertEquals('Alejandro Toledo', $payload['clientName']);
        $this->assertEquals('Cusco & Machu Picchu', $payload['destination']);
    }

    /**
     * Valida que un itinerario sin teléfono válido sea omitido y logueado
     * sin interrumpir el flujo (Ley de Postel).
     */
    public function testBuildPayloadReturnsNullWhenPhoneIsInvalid(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $logMock = $this->createMock(Log::class);

        $logMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('omitido por falta de teléfono válido'));

        $itinerarioMock = $this->createMock(Entity::class);
        $itinerarioMock->method('getId')->willReturn('itin-missing-phone');
        $itinerarioMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'opportunityId' => null,
            'destination'   => 'Paracas',
            default         => null,
        });

        $passengerBuilder = $this->createStub(RDBSelectBuilder::class);
        $passengerBuilder->method('where')->willReturnSelf();
        $passengerBuilder->method('order')->willReturnSelf();
        $passengerBuilder->method('find')->willReturn(new EntityCollection([]));

        $passengerRepoMock = $this->createMock(RDBRepository::class);
        $passengerRepoMock->method('where')->willReturn($passengerBuilder);

        $emMock->method('getRDBRepository')->with('Passenger')->willReturn($passengerRepoMock);

        $job = new TriggerPostTripSurveyJob($emMock, $configMock, $logMock);
        $payload = $job->buildPayload($itinerarioMock);

        $this->assertNull($payload);
    }

    /**
     * Valida que la ejecución del batch con fecha de ayer filtre correctamente
     * y despache los payloads a Activepieces.
     */
    public function testRunExecutesBatchAndDispatchesWebhook(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $logMock = $this->createMock(Log::class);

        $configMock->method('get')
            ->with('activepiecesPostTripWebhookUrl')
            ->willReturn('https://activepieces.test/webhook/post-trip');

        $itinerarioMock = $this->createMock(Entity::class);
        $itinerarioMock->method('getId')->willReturn('itin-returned-yesterday');
        $itinerarioMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'opportunityId' => 'opp-777',
            'destination'   => 'Galápagos',
            default         => null,
        });

        $oppMock = $this->createMock(Entity::class);
        $oppMock->method('get')->with('contactId')->willReturn('contact-888');

        $contactMock = $this->createMock(Entity::class);
        $contactMock->method('getId')->willReturn('contact-888');
        $contactMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'        => 'Beatriz Viajera',
            'phoneNumber' => '+51944556677',
            default       => null,
        });

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $itinBuilder = $this->createStub(RDBSelectBuilder::class);
        $itinBuilder->method('find')->willReturn(new EntityCollection([$itinerarioMock]));

        $itinRepoMock = $this->createMock(RDBRepository::class);
        $itinRepoMock->expects($this->once())
            ->method('where')
            ->with([
                'status'  => 'Confirmado',
                'endDate' => $yesterday,
            ])
            ->willReturn($itinBuilder);

        $passengerBuilder = $this->createStub(RDBSelectBuilder::class);
        $passengerBuilder->method('where')->willReturnSelf();
        $passengerBuilder->method('order')->willReturnSelf();
        $passengerBuilder->method('find')->willReturn(new EntityCollection([]));

        $passengerRepoMock = $this->createMock(RDBRepository::class);
        $passengerRepoMock->method('where')->willReturn($passengerBuilder);

        $emMock->method('getRDBRepository')->willReturnCallback(function ($entityType) use ($itinRepoMock, $passengerRepoMock) {
            return match ($entityType) {
                'Itinerario' => $itinRepoMock,
                'Passenger'  => $passengerRepoMock,
            };
        });

        $emMock->method('getEntity')->willReturnCallback(function ($type, $id) use ($oppMock, $contactMock) {
            if ($type === 'Opportunity' && $id === 'opp-777') return $oppMock;
            if ($type === 'Contact' && $id === 'contact-888') return $contactMock;
            return null;
        });

        $dispatchedPayloads = [];
        $httpSender = function (string $url, array $payload) use (&$dispatchedPayloads) {
            $dispatchedPayloads[] = ['url' => $url, 'payload' => $payload];
            return true;
        };

        $job = new TriggerPostTripSurveyJob($emMock, $configMock, $logMock, $httpSender);
        $job->run();

        $this->assertCount(1, $dispatchedPayloads);
        $this->assertEquals('https://activepieces.test/webhook/post-trip', $dispatchedPayloads[0]['url']);
        $this->assertEquals('itin-returned-yesterday', $dispatchedPayloads[0]['payload']['itineraryId']);
        $this->assertEquals('+51944556677', $dispatchedPayloads[0]['payload']['phone']);
        $this->assertEquals('Beatriz Viajera', $dispatchedPayloads[0]['payload']['clientName']);
        $this->assertEquals('Galápagos', $dispatchedPayloads[0]['payload']['destination']);
    }

    /**
     * Valida que fallos HTTP en el receptor de Activepieces no detengan
     * el procesamiento del cron (Ley de Postel).
     */
    public function testRunHandlesWebhookFailuresGracefully(): void
    {
        $emMock = $this->createMock(EntityManager::class);
        $configMock = $this->createMock(Config::class);
        $logMock = $this->createMock(Log::class);

        $itinerarioMock = $this->createMock(Entity::class);
        $itinerarioMock->method('getId')->willReturn('itin-failing');
        $itinerarioMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'opportunityId' => 'opp-1',
            'destination'   => 'Arequipa',
            default         => null,
        });

        $oppMock = $this->createMock(Entity::class);
        $oppMock->method('get')->with('contactId')->willReturn('contact-1');

        $contactMock = $this->createMock(Entity::class);
        $contactMock->method('getId')->willReturn('contact-1');
        $contactMock->method('get')->willReturnCallback(fn($k) => match ($k) {
            'name'  => 'Carlos Test',
            'phone' => '+51988776655',
            default => null,
        });

        $itinBuilder = $this->createStub(RDBSelectBuilder::class);
        $itinBuilder->method('find')->willReturn(new EntityCollection([$itinerarioMock]));

        $itinRepoMock = $this->createMock(RDBRepository::class);
        $itinRepoMock->method('where')->willReturn($itinBuilder);

        $passengerBuilder = $this->createStub(RDBSelectBuilder::class);
        $passengerBuilder->method('where')->willReturnSelf();
        $passengerBuilder->method('order')->willReturnSelf();
        $passengerBuilder->method('find')->willReturn(new EntityCollection([]));

        $passengerRepoMock = $this->createMock(RDBRepository::class);
        $passengerRepoMock->method('where')->willReturn($passengerBuilder);

        $emMock->method('getRDBRepository')->willReturnCallback(fn($t) => $t === 'Itinerario' ? $itinRepoMock : $passengerRepoMock);
        $emMock->method('getEntity')->willReturnCallback(fn($t, $id) => $t === 'Opportunity' ? $oppMock : $contactMock);

        $logMock->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('Error procesando itinerario itin-failing'));

        $httpSender = function (string $url, array $payload) {
            throw new \RuntimeException("500 Internal Server Error desde Activepieces");
        };

        $job = new TriggerPostTripSurveyJob($emMock, $configMock, $logMock, $httpSender);
        $job->run();
        $this->assertTrue(true);
    }

    /**
     * Valida que el scheduled job esté registrado en los metadatos con el cron 0 9 * * *.
     */
    public function testScheduledJobMetadataConfiguration(): void
    {
        $jobsPath = dirname(__DIR__, 2) . '/Resources/metadata/app/jobs.json';
        $this->assertFileExists($jobsPath);
        $jobs = json_decode(file_get_contents($jobsPath), true);
        $this->assertArrayHasKey('TriggerPostTripSurveyJob', $jobs);
        $this->assertEquals('Espo\Custom\Jobs\TriggerPostTripSurveyJob', $jobs['TriggerPostTripSurveyJob']['jobClassName']);
        $this->assertEquals('0 9 * * *', $jobs['TriggerPostTripSurveyJob']['scheduling']);

        $schedPath = dirname(__DIR__, 2) . '/Resources/metadata/app/scheduledJobs.json';
        $this->assertFileExists($schedPath);
        $sched = json_decode(file_get_contents($schedPath), true);
        $this->assertArrayHasKey('TriggerPostTripSurveyJob', $sched);
        $this->assertEquals('0 9 * * *', $sched['TriggerPostTripSurveyJob']['scheduling']);
    }
}
