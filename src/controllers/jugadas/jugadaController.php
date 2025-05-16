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

            // Verifico que la partida exista y sea del usuario
            $stmt = $pdo->prepare("SELECT mazo_id FROM partida WHERE id = ? AND estado = 'en_curso' AND usuario_id = ?");
            $stmt->execute([$partidaId, $usuarioId]);
            $mazoId = $stmt->fetchColumn();
    
            if (!$mazoId) {
                $response->getBody()->write(json_encode(["error"=> "la partida termino, o no existe o no es de tu propiedad."]));
                return $response->withHeader("Content-Type", "application/json")->withStatus(400); // Bad Request
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
            if ((!$cartaServidorId) || (!$cartaServidorId  == -1)) {
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


           /* $stmt = $pdo->prepare("
                UPDATE mazo_carta mc
                JOIN mazo m ON mc.mazo_id = m.id
                JOIN partida p ON m.id = p.mazo_id
                SET mc.estado = 'descartado'
                WHERE mc.carta_id = ? AND m.usuario_id = ? AND p.id = ?
            ");
            $stmt->execute([$cartaId, $usuarioId, $partidaId]); */

            $stmt = $pdo->prepare("
              UPDATE mazo_carta
              SET estado = 'descartado'
              WHERE carta_id = ? AND mazo_id = ? AND EXISTS (
                 SELECT 1 FROM partida WHERE id = ? AND mazo_id = mazo_carta.mazo_id
              )
            ");
            $stmt->execute([$cartaId, $mazoId, $partidaId]);


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


            if ($cantidadJugadas == 5) {
                // Se inicializan los contadores
                $ganadas = 0;
                $perdidas = 0;

                // Se obtienen los resultados de todas las jugadas
                $stmt = $pdo->prepare("SELECT el_usuario FROM jugada WHERE partida_id = ?");
                $stmt->execute([$partidaId]);
                $resultados = $stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($resultados as $res) {
                  if ($res === 'gano') $ganadas++;
                  if ($res === 'perdio') $perdidas++;
                }
                

                // Determinamos el ganador
                if ($ganadas > $perdidas) {
                    $ganador = 'usuario';  // Si el usuario gana más de 2 veces
                    $resultadoUsuario = 'gano';
                } elseif ($ganadas < $perdidas) {
                    $ganador = 'servidor';  // Si el servidor gana más de 2 veces
                    $resultadoUsuario = 'perdio';
                } else {
                    $ganador = 'empate';  // Si hay empate
                    $resultadoUsuario = 'empato';
                }

                // Finalizamos la partida
                $stmt = $pdo->prepare("UPDATE partida SET estado = 'finalizada', el_usuario = ? WHERE id = ?");
                $stmt->execute([$resultadoUsuario, $partidaId]);


                // Vuelvo las cartas al mazo
                $stmt = $pdo->prepare("UPDATE mazo_carta SET estado = 'en_mazo' WHERE mazo_id IN (?, 1)");
                $stmt->execute([$mazoId]);
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
// Permite obtener los atributos de las cartas del servidor en mano en una partida específica.
// Se valida que el usuario este logueado, y que el mazo pertenezca al servidor (ID 1).
// -------------------------------------------------------------
function obtenerAtributosServidorEnMano(App $app) {
    $app->get('/usuarios/{usuario}/partidas/{partida}/cartas', function (Request $request, Response $response, $args) {
        // Extraemos el ID del usuario desde la URL
        $usuarioRutaId = (int)$args['usuario'];

        // Obtenemos el ID del usuario autenticado desde el atributo JWT
        $usuarioId = $request->getAttribute('usuario_id');

        // Si no hay usuario autenticado, devolvemos error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "Usuario no autenticado"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Validamos que el ID del usuario en la ruta sea 1 (servidor)
        if ($usuarioRutaId !== 1) {
            $response->getBody()->write(json_encode([
                "error" => "Ruta inválida: solo se permite el usuario con ID 1 (servidor)"
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conexión a la base de datos
            $pdo = DB::getConnection();

            // ID fijo del mazo del servidor
            $mazoServidorId = 1;

            // Consultamos los atributos de cartas en mano del mazo del servidor
            $stmt = $pdo->prepare("
                SELECT DISTINCT a.nombre AS atributo 
                FROM mazo_carta mc
                JOIN carta c ON mc.carta_id = c.id
                JOIN atributo a ON c.atributo_id = a.id
                WHERE mc.mazo_id = ? AND mc.estado = 'en_mano'
            ");
            $stmt->execute([$mazoServidorId]);
            $atributos = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Respondemos con los atributos
            $response->getBody()->write(json_encode([
                "Atributos en manos del oponenente" => $atributos
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode([
                "error" => "Error de base de datos"
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware());  // Añadimos el middleware JWT para la autenticación
}
