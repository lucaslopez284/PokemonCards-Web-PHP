<?php
// Importamos interfaces de Slim para manejar peticiones y respuestas
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Importamos el middleware de autenticación JWT
use App\Middleware\JwtMiddleware;

// Importamos la clase que gestiona la conexión a la base de datos
use App\Services\Database;

// Función para registrar las rutas relacionadas con mazos
function mazo(App $app) {

    // Ruta: POST /mazos → Crea un nuevo mazo para un usuario autenticado
    $app->post('/mazos', function (Request $request, Response $response) {
        // Obtenemos el ID del usuario autenticado desde el middleware
        $usuarioId = $request->getAttribute('usuario_id');

        // Leemos el cuerpo de la solicitud y lo decodificamos como JSON
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);

        // Validamos que el JSON contenga el nombre del mazo y un array de cartas
        if (!isset($data['nombre']) || !isset($data['cartas']) || !is_array($data['cartas'])) {
            $response->getBody()->write(json_encode(['error' => 'Faltan datos requeridos (nombre o cartas)']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $nombre = $data['nombre'];
        $cartas = $data['cartas'];

        // Validamos que haya entre 1 y 5 cartas distintas
        if (count($cartas) > 5 || count(array_unique($cartas)) !== count($cartas)) {
            $response->getBody()->write(json_encode(['error' => 'Debe haber entre 1 y 5 cartas distintas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Obtenemos la conexión PDO desde la clase Database
            $pdo = Database::getConnection();

            // Verificamos que las cartas existan
            $placeholders = implode(',', array_fill(0, count($cartas), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM carta WHERE id IN ($placeholders)");
            $stmt->execute($cartas);
            if ($stmt->fetchColumn() != count($cartas)) {
                $response->getBody()->write(json_encode(['error' => 'Una o más cartas no existen']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Validamos que el usuario tenga menos de 3 mazos
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            if ($stmt->fetchColumn() >= 3) {
                $response->getBody()->write(json_encode(['error' => 'Límite de 3 mazos alcanzado']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Insertamos el mazo
            $stmt = $pdo->prepare("INSERT INTO mazo (usuario_id, nombre) VALUES (?, ?)");
            $stmt->execute([$usuarioId, $nombre]);
            $mazoId = $pdo->lastInsertId();

            // Insertamos las cartas en mazo_carta
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'en_mazo')");
            foreach ($cartas as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Devolvemos éxito con ID y nombre del nuevo mazo
            $response->getBody()->write(json_encode(['id' => $mazoId, 'nombre' => $nombre]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (PDOException $e) {
            // Manejamos errores de base de datos
            $response->getBody()->write(json_encode(['error' => 'Error al crear el mazo: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    }); // 👈 cierre correcto de la función POST /mazos
    // ->add(new JwtMiddleware()); // 🔐 Descomentar si querés proteger con autenticación
}
