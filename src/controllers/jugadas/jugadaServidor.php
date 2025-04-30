<?php
// Importo la clase DB para usar la conexión a la base de datos
require_once __DIR__ . '/../../config/DB.php';;

/**
 * Función que realiza una jugada automática del servidor.
 * Usa directamente el mazo con ID 1, que corresponde al usuario del servidor (ID 1).
 * Selecciona una carta en estado 'en_mano', la cambia a estado 'descartado' y devuelve su id.
 *
 * @return int ID de la carta jugada, o -1 si ocurre un error
 */
function jugadaServidor(): int {
    try {
        // Obtengo la conexión a la base de datos usando la clase DB
        $pdo = DB::getConnection();

        // El ID fijo del mazo del servidor (se asume siempre es 1)
        $mazoId = 1;

        // Preparo la consulta para buscar una carta del mazo del servidor, sin importar el estado
        $sql = "
            SELECT mc.carta_id, mc.id 
            FROM mazo_carta mc
            WHERE mc.mazo_id = :mazoId
            ORDER BY RAND()
            LIMIT 1
        ";

        // Preparo el statement con la consulta SQL
        $stmt = $pdo->prepare($sql);

        // Asigno el valor del parámetro :mazoId a la variable $mazoId
        $stmt->bindParam(':mazoId', $mazoId, PDO::PARAM_INT);

        // Ejecuto la consulta
        $stmt->execute();

        // Obtengo el resultado como un array asociativo
        $carta = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si no se encontró una carta (lo cual sería raro), lanzo una excepción
        if (!$carta) {
            throw new Exception("No hay cartas disponibles en el mazo del servidor.");
        }

        // En esta versión NO actualizamos el estado a 'descartado' ya que puede volver a usarse

        // Devuelvo el ID de la carta que se jugó
        return (int)$carta['carta_id'];

    } catch (PDOException $e) {
        // Capturo errores de PDO y los registro en el log
        error_log("Error de PDO en jugadaServidor: " . $e->getMessage());

        // Devuelvo -1 para indicar error
        return -1;
    } catch (Exception $e) {
        // Capturo errores generales y los registro en el log
        error_log("Error en jugadaServidor: " . $e->getMessage());

        // Devuelvo -1 para indicar error
        return -1;
    }
} 