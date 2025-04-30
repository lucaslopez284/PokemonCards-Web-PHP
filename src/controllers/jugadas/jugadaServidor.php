<?php
// Importo la clase DB para usar la conexión a la base de datos
require_once __DIR__ . '/../../config/DB.php';

/**
 * Función que realiza una jugada automática del servidor.
 * Usa directamente el mazo con ID 1, que corresponde al usuario del servidor (ID 1).
 * Selecciona una carta cuyo estado no sea 'descartado', y devuelve su id.
 *
 * @return int ID de la carta jugada, o -1 si ocurre un error o no hay cartas válidas
 */
function jugadaServidor(): int {
    try {
        // Obtengo la conexión a la base de datos usando la clase DB
        $pdo = DB::getConnection();

        // ID fijo del mazo del servidor (usuario servidor)
        $mazoId = 1;

        // Consulta para seleccionar una carta que no esté descartada
        $sql = "
            SELECT mc.carta_id
            FROM mazo_carta mc
            WHERE mc.mazo_id = :mazoId
              AND mc.estado != 'descartado'
            ORDER BY RAND()
            LIMIT 1
        ";

        // Preparo la consulta
        $stmt = $pdo->prepare($sql);

        // Asigno parámetro
        $stmt->bindParam(':mazoId', $mazoId, PDO::PARAM_INT);

        // Ejecuto la consulta
        $stmt->execute();

        // Obtengo una carta aleatoria no descartada
        $carta = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si no se encontró ninguna carta válida
        if (!$carta) {
            throw new Exception("No hay cartas disponibles (no descartadas) en el mazo del servidor.");
        }

        // Devuelvo el ID de la carta seleccionada
        return (int)$carta['carta_id'];

    } catch (PDOException $e) {
        error_log("Error de PDO en jugadaServidor: " . $e->getMessage());
        return -1;
    } catch (Exception $e) {
        error_log("Error en jugadaServidor: " . $e->getMessage());
        return -1;
    }
}
