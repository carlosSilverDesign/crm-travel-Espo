<?php

namespace Espo\Custom\Acl;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BankAccount
{
    public function __construct(
        protected EntityManager $entityManager
    ) {}

    public function checkEntityCreate(User $user, Entity $entity): bool
    {
        return $this->isPrivilegedUser($user);
    }

    public function checkEntityEdit(User $user, Entity $entity): bool
    {
        return $this->isPrivilegedUser($user);
    }

    public function checkEntityDelete(User $user, Entity $entity): bool
    {
        return $this->isPrivilegedUser($user);
    }

    public function checkReadOnlyField(Entity $entity, string $field, User $user): bool
    {
        return !$this->isPrivilegedUser($user);
    }

    protected function isPrivilegedUser(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $roles = $user->get('roles');
        if ($roles) {
            foreach ($roles as $role) {
                $roleName = strtolower($role->get('name') ?? '');
                if (str_contains($roleName, 'admin') || str_contains($roleName, 'finan') || str_contains($roleName, 'caj')) {
                    return true;
                }
            }
        }

        return false;
    }
}
