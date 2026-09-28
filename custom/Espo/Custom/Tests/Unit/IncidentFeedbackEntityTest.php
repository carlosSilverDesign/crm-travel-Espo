<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;

class IncidentFeedbackEntityTest extends TestCase
{
    /**
     * Valida que los metadatos de Incident contengan todos los atributos, tipos,
     * opciones, defaults e índices especificados para el Módulo 07 (TASK-043).
     */
    public function testIncidentMetadataStructure(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Incident.json';
        $this->assertFileExists($metadataPath, 'El archivo entityDefs/Incident.json debe existir.');

        $metadata = json_decode(file_get_contents($metadataPath), true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('fields', $metadata);
        $this->assertArrayHasKey('links', $metadata);

        $fields = $metadata['fields'];
        $links = $metadata['links'];

        // 1. name: varchar, required, maxLength 150
        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('varchar', $fields['name']['type']);
        $this->assertTrue($fields['name']['required']);
        $this->assertEquals(150, $fields['name']['maxLength']);

        // 2. opportunity: link Opportunity, required, index
        $this->assertArrayHasKey('opportunity', $fields);
        $this->assertEquals('link', $fields['opportunity']['type']);
        $this->assertEquals('Opportunity', $fields['opportunity']['entity']);
        $this->assertTrue($fields['opportunity']['required']);
        $this->assertTrue($fields['opportunity']['index']);

        // 3. itinerario: link Itinerario, index
        $this->assertArrayHasKey('itinerario', $fields);
        $this->assertEquals('link', $fields['itinerario']['type']);
        $this->assertEquals('Itinerario', $fields['itinerario']['entity']);
        $this->assertTrue($fields['itinerario']['index']);

        // 4. itemItinerario: link ItineraryItem
        $this->assertArrayHasKey('itemItinerario', $fields);
        $this->assertEquals('link', $fields['itemItinerario']['type']);
        $this->assertEquals('ItineraryItem', $fields['itemItinerario']['entity']);

        // 5. severity: enum, required, options [Low, Medium, High, Critical], default Medium
        $this->assertArrayHasKey('severity', $fields);
        $this->assertEquals('enum', $fields['severity']['type']);
        $this->assertTrue($fields['severity']['required']);
        $this->assertEquals(['Low', 'Medium', 'High', 'Critical'], $fields['severity']['options']);
        $this->assertEquals('Medium', $fields['severity']['default']);

        // 6. category: enum, required, options
        $this->assertArrayHasKey('category', $fields);
        $this->assertEquals('enum', $fields['category']['type']);
        $this->assertTrue($fields['category']['required']);
        $expectedCategories = [
            'FlightDelay',
            'SupplierFailure',
            'HealthEmergency',
            'WeatherForceMajeure',
            'CustomerComplaint',
            'Other'
        ];
        $this->assertEquals($expectedCategories, $fields['category']['options']);

        // 7. status: enum, options [Reported, InInvestigation, Resolved, Escalated], default Reported, index
        $this->assertArrayHasKey('status', $fields);
        $this->assertEquals('enum', $fields['status']['type']);
        $this->assertEquals(['Reported', 'InInvestigation', 'Resolved', 'Escalated'], $fields['status']['options']);
        $this->assertEquals('Reported', $fields['status']['default']);
        $this->assertTrue($fields['status']['index']);

        // 8. costImpact: currency, default 0.0 (Aislamiento contable según Heurística 5)
        $this->assertArrayHasKey('costImpact', $fields);
        $this->assertEquals('currency', $fields['costImpact']['type']);
        $this->assertEquals(0.0, $fields['costImpact']['default']);

        // 9. resolutionPlan: text
        $this->assertArrayHasKey('resolutionPlan', $fields);
        $this->assertEquals('text', $fields['resolutionPlan']['type']);

        // 10. resolvedAt: datetime
        $this->assertArrayHasKey('resolvedAt', $fields);
        $this->assertEquals('datetime', $fields['resolvedAt']['type']);

        // 11. reportedBy: link User
        $this->assertArrayHasKey('reportedBy', $fields);
        $this->assertEquals('link', $fields['reportedBy']['type']);
        $this->assertEquals('User', $fields['reportedBy']['entity']);

        // Links ORM
        $this->assertArrayHasKey('opportunity', $links);
        $this->assertEquals('belongsTo', $links['opportunity']['type']);
        $this->assertEquals('Opportunity', $links['opportunity']['entity']);
        $this->assertEquals('incidents', $links['opportunity']['foreign']);
    }

    /**
     * Valida que los metadatos de Feedback contengan todos los atributos, tipos,
     * opciones, defaults y links requeridos por la especificación (TASK-043).
     */
    public function testFeedbackMetadataStructure(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Feedback.json';
        $this->assertFileExists($metadataPath, 'El archivo entityDefs/Feedback.json debe existir.');

        $metadata = json_decode(file_get_contents($metadataPath), true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('fields', $metadata);
        $this->assertArrayHasKey('links', $metadata);

        $fields = $metadata['fields'];
        $links = $metadata['links'];

        // 1. name: varchar, required, maxLength 100
        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('varchar', $fields['name']['type']);
        $this->assertTrue($fields['name']['required']);
        $this->assertEquals(100, $fields['name']['maxLength']);

        // 2. contact: link Contact, required, index
        $this->assertArrayHasKey('contact', $fields);
        $this->assertEquals('link', $fields['contact']['type']);
        $this->assertEquals('Contact', $fields['contact']['entity']);
        $this->assertTrue($fields['contact']['required']);
        $this->assertTrue($fields['contact']['index']);

        // 3. opportunity: link Opportunity, index
        $this->assertArrayHasKey('opportunity', $fields);
        $this->assertEquals('link', $fields['opportunity']['type']);
        $this->assertEquals('Opportunity', $fields['opportunity']['entity']);
        $this->assertTrue($fields['opportunity']['index']);

        // 4. npsScore: int, required, min 1, max 10
        $this->assertArrayHasKey('npsScore', $fields);
        $this->assertEquals('int', $fields['npsScore']['type']);
        $this->assertTrue($fields['npsScore']['required']);
        $this->assertEquals(1, $fields['npsScore']['min']);
        $this->assertEquals(10, $fields['npsScore']['max']);

        // 5. sentiment: enum [Promoter, Passive, Detractor], readOnly, index
        $this->assertArrayHasKey('sentiment', $fields);
        $this->assertEquals('enum', $fields['sentiment']['type']);
        $this->assertTrue($fields['sentiment']['readOnly']);
        $this->assertTrue($fields['sentiment']['index']);
        $this->assertEquals(['Promoter', 'Passive', 'Detractor'], $fields['sentiment']['options']);

        // 6. comments: text
        $this->assertArrayHasKey('comments', $fields);
        $this->assertEquals('text', $fields['comments']['type']);

        // 7. channel: varchar, default WhatsApp, readOnly
        $this->assertArrayHasKey('channel', $fields);
        $this->assertEquals('varchar', $fields['channel']['type']);
        $this->assertEquals('WhatsApp', $fields['channel']['default']);
        $this->assertTrue($fields['channel']['readOnly']);

        // 8. followUpRequired: bool, default false
        $this->assertArrayHasKey('followUpRequired', $fields);
        $this->assertEquals('bool', $fields['followUpRequired']['type']);
        $this->assertFalse($fields['followUpRequired']['default']);

        // 9. followUpStatus: enum [Pending, Contacted, Resolved, NotNeeded], default NotNeeded
        $this->assertArrayHasKey('followUpStatus', $fields);
        $this->assertEquals('enum', $fields['followUpStatus']['type']);
        $this->assertEquals(['Pending', 'Contacted', 'Resolved', 'NotNeeded'], $fields['followUpStatus']['options']);
        $this->assertEquals('NotNeeded', $fields['followUpStatus']['default']);

        // Links ORM
        $this->assertArrayHasKey('contact', $links);
        $this->assertEquals('belongsTo', $links['contact']['type']);
        $this->assertEquals('Contact', $links['contact']['entity']);
        $this->assertEquals('feedbacks', $links['contact']['foreign']);

        $this->assertArrayHasKey('opportunity', $links);
        $this->assertEquals('belongsTo', $links['opportunity']['type']);
        $this->assertEquals('Opportunity', $links['opportunity']['entity']);
        $this->assertEquals('feedbacks', $links['opportunity']['foreign']);
    }

    /**
     * Valida que las relaciones OpportunityIncident, OpportunityFeedback y ContactFeedback
     * estén configuradas correctamente en metadata/relationships.
     */
    public function testRelationshipsConfiguration(): void
    {
        $baseRelDir = dirname(__DIR__, 2) . '/Resources/metadata/relationships';

        // OpportunityIncident
        $oppIncPath = $baseRelDir . '/OpportunityIncident.json';
        $this->assertFileExists($oppIncPath);
        $oppInc = json_decode(file_get_contents($oppIncPath), true);
        $this->assertEquals('oneToMany', $oppInc['type']);
        $this->assertEquals('Opportunity', $oppInc['entity']);
        $this->assertEquals('incidents', $oppInc['link']);
        $this->assertEquals('Incident', $oppInc['foreignEntity']);
        $this->assertEquals('opportunity', $oppInc['foreignLink']);

        // OpportunityFeedback
        $oppFeedPath = $baseRelDir . '/OpportunityFeedback.json';
        $this->assertFileExists($oppFeedPath);
        $oppFeed = json_decode(file_get_contents($oppFeedPath), true);
        $this->assertEquals('oneToMany', $oppFeed['type']);
        $this->assertEquals('Opportunity', $oppFeed['entity']);
        $this->assertEquals('feedbacks', $oppFeed['link']);
        $this->assertEquals('Feedback', $oppFeed['foreignEntity']);
        $this->assertEquals('opportunity', $oppFeed['foreignLink']);

        // ContactFeedback
        $contFeedPath = $baseRelDir . '/ContactFeedback.json';
        $this->assertFileExists($contFeedPath);
        $contFeed = json_decode(file_get_contents($contFeedPath), true);
        $this->assertEquals('oneToMany', $contFeed['type']);
        $this->assertEquals('Contact', $contFeed['entity']);
        $this->assertEquals('feedbacks', $contFeed['link']);
        $this->assertEquals('Feedback', $contFeed['foreignEntity']);
        $this->assertEquals('contact', $contFeed['foreignLink']);
    }

    /**
     * Valida las extensiones en Opportunity y Contact para los links hasMany.
     */
    public function testOpportunityAndContactLinks(): void
    {
        $oppPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Opportunity.json';
        $oppData = json_decode(file_get_contents($oppPath), true);
        $this->assertArrayHasKey('incidents', $oppData['links']);
        $this->assertEquals('hasMany', $oppData['links']['incidents']['type']);
        $this->assertEquals('Incident', $oppData['links']['incidents']['entity']);
        $this->assertEquals('opportunity', $oppData['links']['incidents']['foreign']);

        $this->assertArrayHasKey('feedbacks', $oppData['links']);
        $this->assertEquals('hasMany', $oppData['links']['feedbacks']['type']);
        $this->assertEquals('Feedback', $oppData['links']['feedbacks']['entity']);
        $this->assertEquals('opportunity', $oppData['links']['feedbacks']['foreign']);

        $contactPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Contact.json';
        $contactData = json_decode(file_get_contents($contactPath), true);
        $this->assertArrayHasKey('feedbacks', $contactData['links']);
        $this->assertEquals('hasMany', $contactData['links']['feedbacks']['type']);
        $this->assertEquals('Feedback', $contactData['links']['feedbacks']['entity']);
        $this->assertEquals('contact', $contactData['links']['feedbacks']['foreign']);
    }

    /**
     * Valida scopes, clientDefs, tabList y bottomPanels.
     */
    public function testScopesClientDefsAndLayouts(): void
    {
        $resDir = dirname(__DIR__, 2) . '/Resources';

        // Scopes
        $incidentScope = json_decode(file_get_contents($resDir . '/metadata/scopes/Incident.json'), true);
        $this->assertTrue($incidentScope['entity']);
        $this->assertEquals('Base', $incidentScope['type']);
        $this->assertEquals('Travel', $incidentScope['module']);

        $feedbackScope = json_decode(file_get_contents($resDir . '/metadata/scopes/Feedback.json'), true);
        $this->assertTrue($feedbackScope['entity']);
        $this->assertEquals('Base', $feedbackScope['type']);
        $this->assertEquals('Travel', $feedbackScope['module']);

        // ClientDefs (iconos y colores para reconocimiento visual rápido)
        $incidentClient = json_decode(file_get_contents($resDir . '/metadata/clientDefs/Incident.json'), true);
        $this->assertEquals('fas fa-exclamation-triangle', $incidentClient['iconClass']);
        $this->assertEquals('#d32f2f', $incidentClient['color']);

        $feedbackClient = json_decode(file_get_contents($resDir . '/metadata/clientDefs/Feedback.json'), true);
        $this->assertEquals('fas fa-star', $feedbackClient['iconClass']);
        $this->assertEquals('#f57c00', $feedbackClient['color']);

        // tabList
        $tabList = json_decode(file_get_contents($resDir . '/metadata/app/tabList.json'), true);
        $this->assertContains('Incident', $tabList);
        $this->assertContains('Feedback', $tabList);

        // bottomPanels de Opportunity
        $bottomPanels = json_decode(file_get_contents($resDir . '/layouts/Opportunity/bottomPanels.json'), true);
        $panelNames = array_column($bottomPanels, 'name');
        $this->assertContains('incidents', $panelNames);
        $this->assertContains('feedbacks', $panelNames);
    }
}
