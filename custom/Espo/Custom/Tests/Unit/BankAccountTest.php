<?php

namespace Espo\Custom\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Espo\Custom\Acl\BankAccount as BankAccountAcl;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BankAccountTest extends TestCase
{
    /**
     * Valida que el archivo de metadatos de BankAccount contenga todos los atributos
     * exigidos por la especificación del Módulo 06.
     */
    public function testMetadataStructure(): void
    {
        $metadataPath = dirname(__DIR__, 2) . '/Resources/metadata/entityDefs/BankAccount.json';
        $this->assertFileExists($metadataPath, 'El archivo entityDefs/BankAccount.json debe existir.');

        $metadata = json_decode(file_get_contents($metadataPath), true);
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('fields', $metadata);

        $fields = $metadata['fields'];

        // 1. name
        $this->assertArrayHasKey('name', $fields);
        $this->assertEquals('varchar', $fields['name']['type']);
        $this->assertTrue($fields['name']['required']);

        // 2. bankName
        $this->assertArrayHasKey('bankName', $fields);
        $this->assertEquals('enum', $fields['bankName']['type']);
        $this->assertTrue($fields['bankName']['required']);
        $this->assertContains('BCP', $fields['bankName']['options']);
        $this->assertContains('BBVA', $fields['bankName']['options']);

        // 3. country
        $this->assertArrayHasKey('country', $fields);
        $this->assertEquals('PER', $fields['country']['default']);

        // 4. currency (Indexado y opciones USD / PEN)
        $this->assertArrayHasKey('currency', $fields);
        $this->assertEquals('enum', $fields['currency']['type']);
        $this->assertTrue($fields['currency']['required']);
        $this->assertTrue($fields['currency']['index'], 'El campo currency debe estar indexado para optimizar el filtrado de cuentas.');
        $this->assertEquals(['USD', 'PEN'], $fields['currency']['options']);

        // 5. accountHolder
        $this->assertArrayHasKey('accountHolder', $fields);
        $this->assertEquals('varchar', $fields['accountHolder']['type']);
        $this->assertTrue($fields['accountHolder']['required']);

        // 6. accountNumber & cci
        $this->assertArrayHasKey('accountNumber', $fields);
        $this->assertTrue($fields['accountNumber']['required']);
        $this->assertArrayHasKey('cci', $fields);
        $this->assertTrue($fields['cci']['required']);

        // 7. isActive (Indexado)
        $this->assertArrayHasKey('isActive', $fields);
        $this->assertEquals('bool', $fields['isActive']['type']);
        $this->assertTrue($fields['isActive']['index']);
    }

    /**
     * Valida que un usuario Administrador conserve permisos totales de creación,
     * edición, eliminación y campos mutables sobre BankAccount.
     */
    public function testAclAdminPermissions(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $acl = new BankAccountAcl($entityManagerStub);

        $adminUserStub = $this->createStub(User::class);
        $adminUserStub->method('isAdmin')->willReturn(true);

        $entityStub = $this->createStub(Entity::class);

        $this->assertTrue($acl->checkEntityCreate($adminUserStub, $entityStub));
        $this->assertTrue($acl->checkEntityEdit($adminUserStub, $entityStub));
        $this->assertTrue($acl->checkEntityDelete($adminUserStub, $entityStub));
        $this->assertFalse($acl->checkReadOnlyField($entityStub, 'accountNumber', $adminUserStub));
    }

    /**
     * Valida que un usuario estándar sin rol financiero tenga denegado el acceso de escritura (PoLP / RBAC).
     */
    public function testAclStandardUserRestricted(): void
    {
        $entityManagerStub = $this->createStub(EntityManager::class);
        $acl = new BankAccountAcl($entityManagerStub);

        $standardUserStub = $this->createStub(User::class);
        $standardUserStub->method('isAdmin')->willReturn(false);
        $standardUserStub->method('get')->willReturnCallback(function (string $name) {
            if ($name === 'roles') {
                return [];
            }
            return null;
        });

        $entityStub = $this->createStub(Entity::class);

        $this->assertFalse($acl->checkEntityCreate($standardUserStub, $entityStub));
        $this->assertFalse($acl->checkEntityEdit($standardUserStub, $entityStub));
        $this->assertFalse($acl->checkEntityDelete($standardUserStub, $entityStub));
        $this->assertTrue($acl->checkReadOnlyField($entityStub, 'accountNumber', $standardUserStub));
    }
}
