<?php
// Importamos las clases necesarias
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Incluimos nuestra clase de conexión a la base de datos (ubicada en config/DB.php)
require_once __DIR__ . '/../../config/DB.php';

// Middleware JWT para la autenticación
use App\middlewares\JwtMiddleware;

// Importamos la función jugadaServidor desde el mismo directorio
require_once __DIR__ . '/jugadaServidor.php';

// -------------------------------------------------------------
// RUTA: POST /jugadas
// Procesa una jugada del usuario durante la partida.
// Verifica que el usuario esté autenticado, valida los datos de la jugada,
// verifica que la carta pertenezca al mazo del usuario, y determina el resultado
// entre la carta del usuario y la carta del servidor. 
// Marca la carta del usuario como descartada y, si es la quinta jugada,
// determina el ganador y finaliza la partida.
// -------------------------------------------------------------
function procesarJugada(App $app) {
    // Definimos la ruta POST para procesar una jugada
    $app->post('/jugadas', function (Request $request, Response $response) {
        $usuarioId = $request->getAttribute('usuario_id');  // Obtenemos el ID del usuario desde el token

        // Verificamos si el usuario está autenticado
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "No autenticado"])); // Si no está autenticado, devolvemos error
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401); // Código 401
        }

        // Leemos el cuerpo de la solicitud
        $body = json_decode((string) $request->getBody(), true);
        $cartaId = $body['carta_id'] ?? null;  // Obtenemos el ID de la carta
        $partidaId = $body['partida_id'] ?? null;  // Obtenemos el ID de la partida

        // Validación de datos: si falta carta_id o partida_id, devolvemos error
        if (!$cartaId || !$partidaId) {
            $response->getBody()->write(json_encode(["error" => "Datos faltantes"])); // Error si falta información
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Código 400
        }

        try {
            $pdo = DB::getConnection();  // Establecemos la conexión a la base de datos

            // Verificamos que la partida exista y pertenezca al usuario autenticado
            $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM partida p
            JOIN mazo m ON p.mazo_id = m.id
            WHERE p.id = ? AND m.usuario_id = ?
            ");
            $stmt->execute([$partidaId, $usuarioId]);
            $partidaValida = $stmt->fetchColumn();

            if ($partidaValida == 0) {
              $response->getBody()->write(json_encode(["error" => "Partida no válida o no pertenece al usuario"]));
              return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // Código 403: prohibido
            }

            // Verificamos si la carta pertenece al mazo del usuario en la partida
            $stmt = $pdo->prepare("
                SELECT mc.estado FROM mazo_carta mc
                JOIN mazo m ON mc.mazo_id = m.id
                JOIN partida p ON p.mazo_id = m.id
                WHERE mc.carta_id = ? AND m.usuario_id = ? AND p.id = ?
            ");
            $stmt->execute([$cartaId, $usuarioId, $partidaId]);
            $estadoCarta = $stmt->fetchColumn();

            // Si la carta no está en el mazo o no es válida, devolvemos error
            if (!$estadoCarta) {
                $response->getBody()->write(json_encode(["error" => "Carta inválida"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Código 400
            }

            // Si la carta ya fue jugada o descartada, devolvemos error
            if ($estadoCarta !== 'en_mano') {
                $response->getBody()->write(json_encode(["error" => "Carta ya jugada o descartada"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Código 400
            }

            // Invocamos jugadaServidor (supone que devuelve carta_id del servidor)
            $cartaServidorId = jugadaServidor();  // Procesamos la jugada en el servidor

            // Verificamos que la carta del servidor no sea nula
            if (!$cartaServidorId) {
                $response->getBody()->write(json_encode(["error" => "La carta del servidor no existe"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Código 400
            }

            // Verificamos si la carta del servidor existe en la tabla carta
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM carta WHERE id = ?");
            $stmt->execute([$cartaServidorId]);
            if ($stmt->fetchColumn() == 0) {
                // Si no existe, devolvemos un error
                $response->getBody()->write(json_encode(["error" => "La carta del servidor no existe"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400); // Código 400
            }

            // Obtenemos los puntos de ambas cartas
            $stmt = $pdo->prepare("SELECT ataque FROM carta WHERE id = ?");
            $stmt->execute([$cartaId]);
            $ataqueUsuario = $stmt->fetchColumn();  // Ataque de la carta del usuario

            $stmt->execute([$cartaServidorId]);
            $ataqueServidor = $stmt->fetchColumn();  // Ataque de la carta del servidor

            /* // Definimos las ventajas
            $ventajas = [
                1 => [3, 6, 7], // Fuego gana a Tierra, Piedra, Planta
                2 => [1],       // Agua gana a Fuego
                3 => [6],       // Tierra gana a Piedra
                5 => [4, 7],    // Volador gana a Normal y Planta
                6 => [2],       // Piedra gana a Agua
                7 => [2, 3, 6], // Planta gana a Agua, Tierra, Piedra
            ];

            // Aplicamos ventaja si corresponde 
            if (isset($ventajas[$cartaId]) && in_array($cartaServidorId, $ventajas[$cartaId])) {
                $ataqueUsuario = $ataqueUsuario * 1.3; // Usuario tiene ventaja
            } elseif (isset($ventajas[$cartaServidorId]) &&in_array($cartaId, $ventajas[$cartaServidorId])) {
                $ataqueServidor = $ataqueServidor * 1.3; // Servidor tiene ventaja
            } */

            // Buscamos si la carta del usuario gana a la carta del servidor
            $stmt = $pdo->prepare("
              SELECT COUNT(*) FROM gana_a ga
              JOIN carta c1 ON ga.atributo_id = c1.atributo_id
              JOIN carta c2 ON ga.atributo_id2 = c2.atributo_id
              WHERE c1.id = ? AND c2.id = ?
            ");
            $stmt->execute([$cartaId, $cartaServidorId]);
            $usuarioTieneVentaja = $stmt->fetchColumn() > 0;

            // Buscamos si la carta del servidor gana a la carta del usuario
            $stmt = $pdo->prepare("
              SELECT COUNT(*) FROM gana_a ga
              JOIN carta c1 ON ga.atributo_id = c1.atributo_id
              JOIN carta c2 ON ga.atributo_id2 = c2.atributo_id
              WHERE c1.id = ? AND c2.id = ?
            ");
            $stmt->execute([$cartaServidorId, $cartaId]);
            $servidorTieneVentaja = $stmt->fetchColumn() > 0;

            // Aplicamos ventaja si corresponde
            if ($usuarioTieneVentaja) {
              $ataqueUsuario *= 1.3;
            } elseif ($servidorTieneVentaja) {
              $ataqueServidor *= 1.3;
            }



            // Determinamos el resultado de la jugada
            if ($ataqueUsuario > $ataqueServidor) {
                $resultado = 'gano';  // Si el usuario gana
            } elseif ($ataqueUsuario < $ataqueServidor) {
                $resultado = 'perdio';  // Si el servidor gana
            } else {
                $resultado = 'empato';  // Si hay empate
            }

            // Creamos el registro en la tabla jugada con carta_usuario_id y carta_servidor_id
            $stmt = $pdo->prepare("INSERT INTO jugada (partida_id, carta_id_a, carta_id_b, el_usuario) VALUES (?, ?, ?, ?)");
            $stmt->execute([$partidaId, $cartaId, $cartaServidorId, $resultado]);
            $jugadaId = $pdo->lastInsertId();  // Obtenemos el ID de la jugada

            // Marcamos la carta del usuario como descartada
            $stmt = $pdo->prepare("
                UPDATE mazo_carta mc
                JOIN mazo m ON mc.mazo_id = m.id
                JOIN partida p ON m.id = p.mazo_id
                SET mc.estado = 'descartado'
                WHERE mc.carta_id = ? AND m.usuario_id = ? AND p.id = ?
            ");
            $stmt->execute([$cartaId, $usuarioId, $partidaId]);

            // Verificamos si fue la quinta jugada
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM jugada WHERE partida_id = ?");
            $stmt->execute([$partidaId]);
            $cantidadJugadas = $stmt->fetchColumn();

            $ganador = null;


            // Verificamos si la partida está finalizada
            $checkEstado = $pdo->prepare("SELECT estado FROM partida WHERE id = :partida_id");
            $checkEstado->bindParam(':partida_id', $partidaId);
            $checkEstado->execute();
            $estadoPartida = $checkEstado->fetchColumn();

            // Si la partida ya está finalizada, devolvemos un error
            if ($estadoPartida === 'finalizada') {
              $response->getBody()->write(json_encode(['error' => 'La partida ya está finalizada.']));
              return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }


            if ($cantidadJugadas >= 5) {
                // Obtenemos la cantidad de jugadas ganadas por el usuario
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM jugada WHERE partida_id = ? AND el_usuario = 'gano'");
                $stmt->execute([$partidaId]);
                $ganadas = $stmt->fetchColumn();

                // Determinamos el ganador
                if ($ganadas > 2) {
                    $ganador = 'usuario';  // Si el usuario gana más de 2 veces
                    $resultadoUsuario = 'gano';
                } elseif ($ganadas < 2) {
                    $ganador = 'servidor';  // Si el servidor gana más de 2 veces
                    $resultadoUsuario = 'perdio';
                } else {
                    $ganador = 'empate';  // Si hay empate
                    $resultadoUsuario = 'empato';
                }

                // Finalizamos la partida
                $stmt = $pdo->prepare("UPDATE partida SET estado = 'finalizada', el_usuario = ? WHERE id = ?");
                $stmt->execute([$resultadoUsuario, $partidaId]);


                // Reestablecemos el mazo del servidor para que pueda volver a usarse
                $stmt = $pdo->prepare("
                    UPDATE mazo_carta mc
                    JOIN mazo m ON mc.mazo_id = m.id
                    JOIN partida p ON m.id = p.mazo_id
                    SET mc.estado = 'en_mano'
                    WHERE m.usuario_id = 1 AND p.id = ? AND mc.estado = 'descartado'
                ");
                $stmt->execute([$partidaId]);
            }

            if ($ganador == null){
                $ganador = "En juego";
            }

            // Respondemos con los resultados de la jugada y el número de jugada
            $response->getBody()->write(json_encode([
                "carta_servidor" => $cartaServidorId,
                "ataque_usuario" => $ataqueUsuario,
                "ataque_servidor" => $ataqueServidor,
                "resultado" => $resultado,
                "ganador" => $ganador,
                "numero_jugada" => $cantidadJugadas // Añadimos el número de jugada
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);  // Código 200

        } catch (PDOException $e) {
            // En caso de error en la base de datos, respondemos con error 500
            $response->getBody()->write(json_encode(["error" => "Error: " . $e->getMessage()])); 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);  // Código 500
        }
    })->add(new JwtMiddleware());  // Añadimos el middleware JWT para la autenticación
}


// -------------------------------------------------------------
// RUTA: GET /usuarios/{usuario}/partidas/{partida}/cartas
// Permite obtener las cartas en mano de un usuario en una partida específica.
// Se valida que el usuario logueado sea el mismo que el usuario en la URL,
// y que el mazo no pertenezca al servidor (ID 1).
// Devuelve las cartas que están en mano de dicho usuario durante esa partida.
// -------------------------------------------------------------
function obtenerCartasEnMano(App $app) {
    // Ruta GET para obtener cartas en mano de un usuario en una partida
    $app->get('/usuarios/{usuario}/partidas/{partida}/cartas', function (Request $request, Response $response, $args) {
        // Obtenemos el ID del usuario logueado desde el token JWT
        $usuarioTokenId = $request->getAttribute('usuario_id');
        
        // Obtenemos el nombre de usuario desde los parámetros de la URL
        $usernameRuta = $args['usuario'];
        
        // Convertimos el ID de la partida desde el parámetro de la URL a un entero
        $partidaId = (int)$args['partida'];

        try {
            // Nos conectamos a la base de datos utilizando la clase DB
            $pdo = DB::getConnection();

            // Buscamos el ID del usuario a partir de su nombre de usuario
            $stmt = $pdo->prepare("SELECT id FROM usuario WHERE usuario = ?");
            $stmt->execute([$usernameRuta]);
            $usuarioRutaRow = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si el usuario no existe, respondemos con un error 404
            if (!$usuarioRutaRow) {
                $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Extraemos el ID del usuario encontrado
            $usuarioRutaId = (int)$usuarioRutaRow['id'];

            // Validamos que el usuario logueado sea el mismo que el usuario solicitado
            // o el servidor (usuario con ID 1) pueda acceder a cualquier partida
            if ($usuarioTokenId !== $usuarioRutaId) {
                $response->getBody()->write(json_encode([ 
                    "error" => "Acceso denegado", 
                    "usuarioRutaId" => $usuarioRutaId, 
                    "usuarioTokenId" => $usuarioTokenId 
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Verificamos que la partida exista y pertenezca al usuario
            $stmt = $pdo->prepare(" 
                SELECT p.id, m.usuario_id
                FROM partida p 
                JOIN mazo m ON p.mazo_id = m.id 
                WHERE p.id = ? AND m.usuario_id = ?
            ");
            $stmt->execute([$partidaId, $usuarioRutaId]);
            $partidaExiste = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si la partida no existe o no pertenece al usuario, respondemos con error 404
            if (!$partidaExiste) {
                $response->getBody()->write(json_encode(["error" => "Partida no encontrada o no pertenece al usuario"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Verificamos que el mazo no pertenezca al servidor (usuario con ID 1)
            if ($partidaExiste['usuario_id'] === 1) {
                $response->getBody()->write(json_encode(["error" => "Acceso al mazo del servidor no permitido"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Obtenemos las cartas que están en mano del usuario para esa partida
            $stmt = $pdo->prepare(" 
                SELECT c.id, c.nombre, c.ataque, a.nombre as atributo 
                FROM mazo_carta mc 
                JOIN carta c ON mc.carta_id = c.id 
                JOIN atributo a ON c.atributo_id = a.id 
                JOIN mazo m ON mc.mazo_id = m.id 
                JOIN partida p ON p.mazo_id = m.id 
                WHERE m.usuario_id = ? AND p.id = ? AND mc.estado = 'en_mano'
            ");
            $stmt->execute([$usuarioRutaId, $partidaId]);
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Si no se encontraron cartas en mano, devolvemos un mensaje informativo
            if (!$cartas || count($cartas) === 0) {
                $response->getBody()->write(json_encode([
                    "mensaje" => "No hay cartas en mano para esta partida"
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            }

            // Si hay cartas, las devolvemos en la respuesta
            $response->getBody()->write(json_encode($cartas));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // En caso de error en la base de datos, respondemos con un error 500
            $response->getBody()->write(json_encode(["error" => "Error: " . $e->getMessage()])); 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // Se añade el middleware JwtMiddleware para validar el token de autenticación
}
