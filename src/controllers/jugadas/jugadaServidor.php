<?php
// Importo la clase DB para usar la conexión a la base de datos
require_once __DIR__ . '/../../config/DB.php';;

/**
 * Función que realiza una jugada automática del servidor.
 * Usa directamente el mazo con ID 1, que corresponde al usuario del servidor (ID 1).
 * Selecciona una carta en estado 'en_mano', la cambia a estado 'descartado' y devuelve su id.
 */ 

    const SERVER_MAZO_ID = 1;

    function jugadaServidor(): int
{
    $db = DB::getConnection(); // Nos conectamos a la base de datos
    $idServidor = 1; // ID fijo para el servidor

    // Buscamos las cartas disponibles del servidor
    $stmt = $db->prepare("
        SELECT mc.carta_id
        FROM mazo_carta mc
        JOIN mazo m ON mc.mazo_id = m.id
        WHERE m.usuario_id = :idServidor
          AND mc.estado != 'descartado'
    ");

    $stmt->bindParam(':idServidor', $idServidor);
    $stmt->execute();
    $cartasDisponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($cartasDisponibles)) {
        throw new Exception('No hay cartas disponibles para el servidor'); // Manejo de error si no hay cartas disponibles
        /*throw es una forma de lanzar una excepción en PHP, lo que detiene la ejecución del script y permite manejar el
        error en un bloque try-catch.*/
    }

    // Elegimos una carta al azar
    $idCartaSeleccionada = $cartasDisponibles[array_rand($cartasDisponibles)]; // array_rand devuelve una clave aleatoria de un array, en este caso de las cartas disponibles

    // Actualizamos el estado de la carta a 'descartado'
    $stmt = $db->prepare("
        UPDATE mazo_carta
        SET estado = 'descartado'
        WHERE carta_id = :idCarta
    ");
    $stmt->bindParam(':idCarta', $idCartaSeleccionada);
    $stmt->execute();

    return (int) $idCartaSeleccionada;
}