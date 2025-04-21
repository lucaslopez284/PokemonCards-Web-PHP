<?php
function jugadaServidor($partidaId) {
    try {
        // Conectar a la base de datos
        $pdo = DB::getConnection();
        
        // ID del servidor fijo
        $servidorId = 1;

        // Obtener el mazo del servidor
        $stmt = $pdo->prepare("SELECT mazo_id FROM mazo WHERE usuario_id = ?");
        $stmt->execute([$servidorId]);
        $mazo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mazo) {
            throw new Exception("El servidor no tiene un mazo.");
        }

        // Obtener cartas del mazo del servidor no usadas ni descartadas
        $stmt = $pdo->prepare("SELECT mc.carta_id 
                               FROM mazo_carta mc
                               WHERE mc.mazo_id = ? 
                               AND mc.estado != 'descartado' 
                               AND mc.carta_id NOT IN (
                                   SELECT carta_id FROM jugada WHERE partida_id = ?
                               )");
        $stmt->execute([$mazo['mazo_id'], $partidaId]);
        $cartasDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($cartasDisponibles) === 0) {
            throw new Exception("No hay cartas disponibles para jugar.");
        }

        // Elegir una carta al azar
        $indice = array_rand($cartasDisponibles);
        $cartaJugadaId = $cartasDisponibles[$indice]['carta_id'];

        // Actualizar estado a 'descartado'
        $stmt = $pdo->prepare("UPDATE mazo_carta SET estado = 'descartado' 
                               WHERE carta_id = ? AND mazo_id = ?");
        $stmt->execute([$cartaJugadaId, $mazo['mazo_id']]);

        // Registrar jugada del servidor
        $stmt = $pdo->prepare("INSERT INTO jugada (partida_id, usuario_id, carta_id) 
                               VALUES (?, ?, ?)");
        $stmt->execute([$partidaId, $servidorId, $cartaJugadaId]);

        // Devolver id de la carta jugada
        return $cartaJugadaId;

    } catch (Exception $e) {
        // Mostrar error y devolver 0 como código de error
        echo "Error en jugadaServidor(): " . $e->getMessage();
        return 0;
    }
}
