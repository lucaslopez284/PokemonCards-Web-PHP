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
require_once __DIR__ . '/../DB.php';

// Middleware JWT para la autenticación 
use App\config\middlewares\JwtMiddleware;

// -------------------------------------------------------------
// RUTA: POST /partidas
// Crea una nueva partida con un mazo del usuario logueado.
// Verifica la propiedad del mazo, cambia el estado de las cartas a "en_mano"
// y devuelve el ID de la partida junto con la lista de cartas del mazo.
// -------------------------------------------------------------
function crearPartida(App $app)
{
    $app->post('/partidas', function (Request $request, Response $response) {
        // Obtenemos el ID del usuario logueado desde el JWT
        $usuarioId = $request->getAttribute('usuario_id');

        // Si no hay usuario logueado, devolvemos error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "Usuario no autenticado"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Leemos el cuerpo del request
        $data = json_decode((string) $request->getBody(), true);

        // Verificamos que se haya enviado un mazo_id
        if (!isset($data['mazo_id'])) {
            $response->getBody()->write(json_encode(["error" => "Falta el id del mazo"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $idMazo = $data['mazo_id'];

        try {
            // Conectamos con la base de datos
            $pdo = DB::getConnection();

            // Verificamos que el mazo pertenezca al usuario logueado
            $stmt = $pdo->prepare('SELECT * FROM mazo WHERE id = :mazo_id AND usuario_id = :usuario_id');
            $stmt->execute([':mazo_id' => $idMazo, ':usuario_id' => $usuarioId]);
            $mazo = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no se encontró el mazo o no pertenece al usuario, error 403
            if (!$mazo) {
                $response->getBody()->write(json_encode(["error" => "El mazo no pertenece al usuario"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Creamos una nueva partida con estado "en_curso"
            $stmt = $pdo->prepare("INSERT INTO partida (usuario_id, mazo_id, fecha, estado) VALUES (?, ?, NOW(), 'en_curso')");
            $stmt->execute([$usuarioId, $idMazo]);

            // Obtenemos el ID de la partida recién creada
            $idPartida = $pdo->lastInsertId();

            // Actualizamos las cartas del mazo a estado "en_mano"
            $stmt = $pdo->prepare("UPDATE mazo_carta SET estado = 'en_mano' WHERE mazo_id = ?");
            $stmt->execute([$idMazo]);

            // Obtenemos la lista de cartas del mazo
            $stmt = $pdo->prepare("SELECT c.id, c.nombre, c.ataque, c.imagen, a.nombre AS atributo 
                                   FROM mazo_carta mc
                                   JOIN carta c ON mc.carta_id = c.id
                                   JOIN atributo a ON c.atributo_id = a.id
                                   WHERE mc.mazo_id = ?");
            $stmt->execute([$idMazo]);
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Devolvemos la respuesta con éxito
            $response->getBody()->write(json_encode([
                "id_partida" => $idPartida,
                "cartas" => $cartas
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (Exception $e) {
            // En caso de error de base de datos, devolvemos error 500
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()])); 
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // Aplicamos el middleware de autenticación
}
