<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PaymentEntityTest extends TestCase
{
    /**
     * Valida que el archivo de metadatos de Payment contenga todos los atributos
     * y restricciones requeridos por la especificación del Módulo 06 (TASK-038).
     */
    public function testPaymentMetadataStructure(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Payment.json';
        $this->assertFileExists($metadataPath, 'El archivo entityDefs/Payment.json debe existir.');

        $metadata = json_decode(file_get_contents($metadataPath), true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('fields', $metadata);
        $this->assertArrayHasKey('links', $metadata);

        $fields = $metadata['fields'];
        $links = $metadata['links'];

        // 1. name (readOnly)
        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('varchar', $fields['name']['type']);
        $this->assertTrue($fields['name']['readOnly']);

        // 2. paymentReference (readOnly, index)
        $this->assertArrayHasKey('paymentReference', $fields);
        $this->assertEquals('varchar', $fields['paymentReference']['type']);
        $this->assertTrue($fields['paymentReference']['readOnly']);
        $this->assertTrue($fields['paymentReference']['index'], 'paymentReference debe estar indexado para búsquedas rápidas.');

        // 3. opportunity (required, index, link)
        $this->assertArrayHasKey('opportunity', $fields);
        $this->assertEquals('link', $fields['opportunity']['type']);
        $this->assertEquals('Opportunity', $fields['opportunity']['entity']);
        $this->assertTrue($fields['opportunity']['required']);
        $this->assertTrue($fields['opportunity']['index']);

        // 4. paymentSchedule (link, index)
        $this->assertArrayHasKey('paymentSchedule', $fields);
        $this->assertEquals('link', $fields['paymentSchedule']['type']);
        $this->assertEquals('PaymentSchedule', $fields['paymentSchedule']['entity']);
        $this->assertTrue($fields['paymentSchedule']['index']);

        // 5. amount & currency
        $this->assertArrayHasKey('amount', $fields);
        $this->assertEquals('currency', $fields['amount']['type']);
        $this->assertTrue($fields['amount']['required']);

        $this->assertArrayHasKey('currency', $fields);
        $this->assertEquals('enum', $fields['currency']['type']);
        $this->assertTrue($fields['currency']['required']);
        $this->assertEquals(['USD', 'PEN'], $fields['currency']['options']);

        // 6. status (enum con 7 estados finitos, default Pending, index)
        $this->assertArrayHasKey('status', $fields);
        $this->assertEquals('enum', $fields['status']['type']);
        $this->assertEquals('Pending', $fields['status']['default']);
        $this->assertTrue($fields['status']['index']);
        $expectedStatuses = ['Draft', 'Pending', 'UnderReview', 'Confirmed', 'Rejected', 'Expired', 'Canceled'];
        $this->assertEquals($expectedStatuses, $fields['status']['options']);

        // 7. method y gatewayProvider
        $this->assertArrayHasKey('method', $fields);
        $this->assertEquals('bank_transfer', $fields['method']['default']);
        $this->assertContains('bank_transfer', $fields['method']['options']);
        $this->assertContains('payment_gateway', $fields['method']['options']);

        $this->assertArrayHasKey('gatewayProvider', $fields);
        $this->assertEquals('none', $fields['gatewayProvider']['default']);
        $this->assertContains('stripe', $fields['gatewayProvider']['options']);
        $this->assertContains('culqi', $fields['gatewayProvider']['options']);
        $this->assertContains('mercadopago', $fields['gatewayProvider']['options']);

        // 8. destinationBankAccount
        $this->assertArrayHasKey('destinationBankAccount', $fields);
        $this->assertEquals('BankAccount', $fields['destinationBankAccount']['entity']);

        // 9. proofAttachment
        $this->assertArrayHasKey('proofAttachment', $fields);
        $this->assertEquals('Attachment', $fields['proofAttachment']['entity']);

        // 10. verifiedBy & verifiedAt (readOnly)
        $this->assertArrayHasKey('verifiedBy', $fields);
        $this->assertEquals('User', $fields['verifiedBy']['entity']);
        $this->assertTrue($fields['verifiedBy']['readOnly']);

        $this->assertArrayHasKey('verifiedAt', $fields);
        $this->assertEquals('datetime', $fields['verifiedAt']['type']);
        $this->assertTrue($fields['verifiedAt']['readOnly']);

        // 11. rejectionReason
        $this->assertArrayHasKey('rejectionReason', $fields);
        $expectedReasons = ['wrong_amount', 'transfer_not_found', 'invalid_account', 'unreadable_voucher', 'duplicate_operation', 'other'];
        $this->assertEquals($expectedReasons, $fields['rejectionReason']['options']);

        // Links de ORM
        $this->assertArrayHasKey('opportunity', $links);
        $this->assertEquals('belongsTo', $links['opportunity']['type']);
        $this->assertEquals('payments', $links['opportunity']['foreign']);

        $this->assertArrayHasKey('paymentSchedule', $links);
        $this->assertEquals('belongsTo', $links['paymentSchedule']['type']);
        $this->assertEquals('payments', $links['paymentSchedule']['foreign']);
    }

    /**
     * Valida que Opportunity contenga los campos derivados de solo lectura y el link hasMany hacia Payment.
     */
    public function testOpportunityFinancialExtensions(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/Opportunity.json';
        $metadata = json_decode(file_get_contents($metadataPath), true);

        $fields = $metadata['fields'];
        $links = $metadata['links'];

        // amountPaid (currency, default 0, readOnly)
        $this->assertArrayHasKey('amountPaid', $fields);
        $this->assertEquals('currency', $fields['amountPaid']['type']);
        $this->assertEquals(0, $fields['amountPaid']['default']);
        $this->assertTrue($fields['amountPaid']['readOnly']);

        // pendingBalance (currency, readOnly)
        $this->assertArrayHasKey('pendingBalance', $fields);
        $this->assertEquals('currency', $fields['pendingBalance']['type']);
        $this->assertTrue($fields['pendingBalance']['readOnly']);

        // financialStatus (enum, default Unpaid, readOnly)
        $this->assertArrayHasKey('financialStatus', $fields);
        $this->assertEquals('enum', $fields['financialStatus']['type']);
        $this->assertEquals('Unpaid', $fields['financialStatus']['default']);
        $this->assertTrue($fields['financialStatus']['readOnly']);
        $this->assertEquals(['Unpaid', 'PartiallyPaid', 'PaidInFull', 'Overpaid'], $fields['financialStatus']['options']);

        // Link payments (hasMany)
        $this->assertArrayHasKey('payments', $links);
        $this->assertEquals('hasMany', $links['payments']['type']);
        $this->assertEquals('Payment', $links['payments']['entity']);
        $this->assertEquals('opportunity', $links['payments']['foreign']);
    }

    /**
     * Valida que PaymentSchedule contenga el link hasMany hacia Payment.
     */
    public function testPaymentScheduleExtensions(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/PaymentSchedule.json';
        $metadata = json_decode(file_get_contents($metadataPath), true);

        $links = $metadata['links'];
        $this->assertArrayHasKey('payments', $links);
        $this->assertEquals('hasMany', $links['payments']['type']);
        $this->assertEquals('Payment', $links['payments']['entity']);
        $this->assertEquals('paymentSchedule', $links['payments']['foreign']);
    }

    /**
     * Valida que las definiciones de relaciones 1:N en metadata/relationships existan y estén bien configuradas.
     */
    public function testRelationshipDefinitions(): void
    {
        $oppPaymentPath = dirname(__DIR__, 2) . '/Resources/metadata/relationships/OpportunityPayment.json';
        $this->assertFileExists($oppPaymentPath);
        $oppPayment = json_decode(file_get_contents($oppPaymentPath), true);
        $this->assertEquals('oneToMany', $oppPayment['type']);
        $this->assertEquals('Opportunity', $oppPayment['entity']);
        $this->assertEquals('payments', $oppPayment['link']);
        $this->assertEquals('Payment', $oppPayment['foreignEntity']);
        $this->assertEquals('opportunity', $oppPayment['foreignLink']);

        $schedPaymentPath = dirname(__DIR__, 2) . '/Resources/metadata/relationships/PaymentSchedulePayment.json';
        $this->assertFileExists($schedPaymentPath);
        $schedPayment = json_decode(file_get_contents($schedPaymentPath), true);
        $this->assertEquals('oneToMany', $schedPayment['type']);
        $this->assertEquals('PaymentSchedule', $schedPayment['entity']);
        $this->assertEquals('payments', $schedPayment['link']);
        $this->assertEquals('Payment', $schedPayment['foreignEntity']);
        $this->assertEquals('paymentSchedule', $schedPayment['foreignLink']);
    }
}
