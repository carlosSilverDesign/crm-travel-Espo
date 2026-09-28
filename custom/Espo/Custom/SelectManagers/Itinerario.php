<?php

namespace Espo\Custom\SelectManagers;

class Itinerario
{
    /**
     * Filtro primario / booleano 'currentInDestination'.
     *
     * Aísla las reservas confirmadas cuya ventana de viaje coincide con la fecha
     * actual del sistema (status = 'Confirmado' AND startDate <= CURDATE() AND endDate >= CURDATE()).
     *
     * Heurística 6 de Nielsen (Reconocimiento antes que recuerdo)
     * Umbral de Doherty (<400ms de respuesta mediante indexación)
     *
     * @param array|object $result
     */
    public function boolFilterCurrentInDestination(&$result): void
    {
        $today = date('Y-m-d');

        if (is_array($result)) {
            if (!isset($result['whereClause'])) {
                $result['whereClause'] = [];
            }

            $result['whereClause'][] = [
                'status' => 'Confirmado',
                'startDate<=' => $today,
                'endDate>=' => $today,
            ];
        }
    }
}
