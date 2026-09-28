<?php

namespace Espo\Custom\Hooks\Itinerario;

use Espo\ORM\Entity;
use Ramsey\Uuid\Uuid;

class GeneratePublicToken
{
    /**
     * Asigna un identificador criptográficamente seguro UUIDv4 a la entidad Itinerario
     * si no posee un token público previamente asignado.
     *
     * Heurística 5 (Prevención de errores) & Heurística 10 (Seguridad y visibilidad).
     * Ley de Tesler: Asume la complejidad criptográfica sin fricción para el usuario.
     * Umbral de Doherty: Ejecución determinista en <1ms.
     */
    public function beforeSave(Entity $entity, array $options = []): void
    {
        $currentToken = $entity->get('publicAccessToken');

        if (empty($currentToken) || trim((string) $currentToken) === '') {
            $entity->set('publicAccessToken', $this->generateUuid());
        }
    }

    /**
     * Genera un identificador UUIDv4 seguro estándar RFC 4122.
     * Utiliza Ramsey\Uuid\Uuid nativo de EspoCRM con fallback criptográfico a random_bytes.
     */
    private function generateUuid(): string
    {
        if (class_exists(Uuid::class)) {
            return Uuid::uuid4()->toString();
        }

        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4 (0100)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122 (10)

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
