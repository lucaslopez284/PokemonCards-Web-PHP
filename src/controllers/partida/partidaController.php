<?php
// -------------------------------------------------------------
// Archivo: partidaController.php
// Contiene las funciones relacionadas con el manejo de partidas.
// -------------------------------------------------------------

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Incluimos directamente la clase DB personalizada, ubicada en config/DB.php
// No usa namespace, así que con require_once es suficiente
require_once __DIR__ . '/../../config/DB.php';

// Middleware JWT para la autenticación 
use App\middlewares\JwtMiddleware;

// -------------------------------------------------------------
// RUTA: POST /partidas
// Crea una nueva partida con un mazo del usuario logueado.
// Verifica la propiedad del mazo, cambia el estado de las cartas a "en_mano"
// y devuelve el ID de la partida junto con la lista de cartas del mazo.
// -------------------------------------------------------------
function crearPartida(App $app)
{
    // Definimos la ruta POST /partidas
    $app->post('/partidas', function (Request $request, Response $response) {
        // Obtenemos el ID del usuario autenticado desde el atributo JWT
        $usuarioId = $request->getAttribute('usuario_id');

        // Si no hay usuario autenticado, devolvemos error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "Usuario no autenticado"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Obtenemos los datos enviados en el body del request
        $data = json_decode((string) $request->getBody(), true);

        // Verificamos que se haya enviado el mazo_id
        if (!isset($data['mazo_id'])) {
            $response->getBody()->write(json_encode(["error" => "Falta el id del mazo"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Guardamos el mazo_id recibido
        $idMazo = $data['mazo_id'];

        try {
            // Obtenemos la conexión a la base de datos usando la clase DB
            $pdo = DB::getConnection();

            // Verificamos que el mazo exista y pertenezca al usuario autenticado
            $stmt = $pdo->prepare("SELECT * FROM mazo WHERE id = :mazo_id AND usuario_id = :usuario_id");
            $stmt->execute([':mazo_id' => $idMazo, ':usuario_id' => $usuarioId]);
            $mazo = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no se encontró el mazo, o no pertenece al usuario, devolvemos error 403
            if (!$mazo) {
                $response->getBody()->write(json_encode(["error" => "El mazo no pertenece al usuario"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Verificamos si el mazo ya está en uso en otra partida
            $stmt = $pdo->prepare("SELECT * FROM partida WHERE mazo_id = :mazo_id AND estado = 'en_curso'");
            $stmt->execute([':mazo_id' => $idMazo]);
            $partidaEnCurso = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si el mazo ya está en curso, devolvemos un error
            if ($partidaEnCurso) {
                $response->getBody()->write(json_encode(["error" => "Este mazo ya está en uso en otra partida"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Insertamos una nueva partida en la base de datos con estado "en_curso"
            $stmt = $pdo->prepare("INSERT INTO partida (usuario_id, mazo_id, fecha, estado) VALUES (?, ?, NOW(), 'en_curso')");
            $stmt->execute([$usuarioId, $idMazo]);

            // Obtenemos el ID de la partida recién creada
            $idPartida = $pdo->lastInsertId();
 
            // Actualizamos el estado de las cartas del mazo a "en_mano"
            $stmt = $pdo->prepare("UPDATE mazo_carta SET estado = 'en_mano' WHERE mazo_id IN (?, 1)");
            $stmt->execute([$idMazo]);

            // Obtenemos la información de las cartas del mazo actual
            $stmt = $pdo->prepare("SELECT c.id, c.nombre, c.ataque, c.imagen, a.nombre AS atributo
                                   FROM mazo_carta mc
                                   JOIN carta c ON mc.carta_id = c.id
                                   JOIN atributo a ON c.atributo_id = a.id
                                   WHERE mc.mazo_id = ?");
            $stmt->execute([$idMazo]);
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Devolvemos el ID de la partida creada y la lista de cartas en mano
            $response->getBody()->write(json_encode([
                "id_partida" => $idPartida,
                "cartas" => $cartas
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (Exception $e) {
            // Si ocurre un error, devolvemos mensaje con error 500
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    // Aplicamos el middleware de autenticación JWT a esta ruta
    })->add(new JwtMiddleware());
}
