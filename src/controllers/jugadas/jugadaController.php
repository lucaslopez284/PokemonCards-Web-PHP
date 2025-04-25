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

function procesarJugada(App $app) {
    // Definimos la ruta POST para procesar una jugada
    $app->post('/jugadas', function (Request $request, Response $response) {
        $usuarioId = $request->getAttribute('usuario_id');

        // Verificamos si el usuario está autenticado
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "No autenticado"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Leemos el cuerpo de la solicitud
        $body = json_decode((string) $request->getBody(), true);
        $cartaId = $body['carta_id'] ?? null;
        $partidaId = $body['partida_id'] ?? null;

        // Validación de datos
        if (!$cartaId || !$partidaId) {
            $response->getBody()->write(json_encode(["error" => "Datos faltantes"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $pdo = DB::getConnection();

            // Verificamos si la carta pertenece al mazo del usuario en la partida
            $stmt = $pdo->prepare("
                SELECT mc.estado FROM mazo_carta mc
                JOIN mazo m ON mc.mazo_id = m.id
                JOIN partida p ON p.mazo_id = m.id
                WHERE mc.carta_id = ? AND m.usuario_id = ? AND p.id = ?
            ");
            $stmt->execute([$cartaId, $usuarioId, $partidaId]);
            $estadoCarta = $stmt->fetchColumn();

            if (!$estadoCarta) {
                $response->getBody()->write(json_encode(["error" => "Carta inválida"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if ($estadoCarta !== 'en_mano') {
                $response->getBody()->write(json_encode(["error" => "Carta ya jugada o descartada"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Invocamos jugadaServidor (supone que devuelve carta_id del servidor)
            $cartaServidorId = jugadaServidor();

            // Verificamos que la carta del servidor no sea nula
            if (!$cartaServidorId) {
                $response->getBody()->write(json_encode(["error" => "La carta del servidor no existe"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Verificamos si la carta del servidor existe en la tabla carta
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM carta WHERE id = ?");
            $stmt->execute([$cartaServidorId]);
            if ($stmt->fetchColumn() == 0) {
                // Si no existe, devolvemos un error
                $response->getBody()->write(json_encode(["error" => "La carta del servidor no existe"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Obtenemos los puntos de ambas cartas
            $stmt = $pdo->prepare("SELECT ataque FROM carta WHERE id = ?");
            $stmt->execute([$cartaId]);
            $ataqueUsuario = $stmt->fetchColumn();

            $stmt->execute([$cartaServidorId]);
            $ataqueServidor = $stmt->fetchColumn();

            // Determinamos el resultado
            if ($ataqueUsuario > $ataqueServidor) {
                $resultado = 'gano';
            } elseif ($ataqueUsuario < $ataqueServidor) {
                $resultado = 'perdio';
            } else {
                $resultado = 'empato';
            }

            // Creamos el registro en la tabla jugada con carta_usuario_id y carta_servidor_id
            $stmt = $pdo->prepare("INSERT INTO jugada (partida_id, carta_id_a, carta_id_b, el_usuario) VALUES (?, ?, ?, ?)");
            $stmt->execute([$partidaId, $cartaId, $cartaServidorId, $resultado]);
            $jugadaId = $pdo->lastInsertId();

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

            if ($cantidadJugadas >= 5) {
                // Obtenemos la cantidad de jugadas ganadas por el usuario
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM jugada WHERE partida_id = ? AND el_usuario = 'gano'");
                $stmt->execute([$partidaId]);
                $ganadas = $stmt->fetchColumn();

                // Determinamos el ganador
                if ($ganadas > 2) {
                    $ganador = 'usuario';
                } elseif ($ganadas < 2) {
                    $ganador = 'servidor';
                } else {
                    $ganador = 'empate';
                }

                // Finalizamos la partida
                $stmt = $pdo->prepare("UPDATE partida SET estado = 'finalizada' WHERE id = ?");
                $stmt->execute([$partidaId]);
            }

            // Respondemos con los resultados
            $response->getBody()->write(json_encode([
                "carta_servidor" => $cartaServidorId,
                "ataque_usuario" => $ataqueUsuario,
                "ataque_servidor" => $ataqueServidor,
                "resultado" => $resultado,
                "ganador" => $ganador
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "Error: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware());
}

function obtenerCartasEnMano(App $app) {
    // Ruta GET para obtener cartas en mano de un usuario en una partida
    $app->get('/usuarios/{usuario}/partidas/{partida}/cartas', function (Request $request, Response $response, $args) {
        // Obtenemos el ID del usuario logueado desde el token
        $usuarioTokenId = $request->getAttribute('usuario_id');
        // Obtenemos el nombre de usuario desde la URL
        $usernameRuta = $args['usuario'];
        // Convertimos el ID de la partida a entero
        $partidaId = (int)$args['partida'];

        try {
            // Nos conectamos a la base de datos
            $pdo = DB::getConnection();

            // Buscamos el ID del usuario a partir de su nombre
            $stmt = $pdo->prepare("SELECT id FROM usuario WHERE usuario = ?");
            $stmt->execute([$usernameRuta]);
            $usuarioRutaRow = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si el usuario no existe, devolvemos error 404
            if (!$usuarioRutaRow) {
                $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Extraemos el ID del usuario
            $usuarioRutaId = (int)$usuarioRutaRow['id'];

            // Validamos que el usuario logueado sea el mismo o el servidor (ID 1)
            if ($usuarioTokenId !== $usuarioRutaId && $usuarioRutaId !== 1) {
                $response->getBody()->write(json_encode([
                    "error" => "Acceso denegado",
                    "usuarioRutaId" => $usuarioRutaId,
                    "usuarioTokenId" => $usuarioTokenId
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Verificamos si la partida existe y pertenece al usuario
            $stmt = $pdo->prepare("
                SELECT p.id 
                FROM partida p
                JOIN mazo m ON p.mazo_id = m.id
                WHERE p.id = ? AND m.usuario_id = ?
            ");
            $stmt->execute([$partidaId, $usuarioRutaId]);
            $partidaExiste = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si la partida no existe o no pertenece al usuario, devolvemos error 404
            if (!$partidaExiste) {
                $response->getBody()->write(json_encode(["error" => "Partida no encontrada o no pertenece al usuario"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Obtenemos las cartas en mano para esa partida
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

            // Devolvemos las cartas como respuesta
            $response->getBody()->write(json_encode($cartas));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // En caso de error en la base de datos, respondemos con error 500
            $response->getBody()->write(json_encode(["error" => "Error: " . $e->getMessage()])); 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware());
}
