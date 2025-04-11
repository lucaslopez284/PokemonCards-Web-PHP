<?php
// Importo las interfaces necesarias para manejar requests y responses
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Importo el middleware JWT para proteger los endpoints que requieren login
use App\Middleware\JwtMiddleware;

// Incluyo la clase de conexión a la base de datos (DB está en config)
require_once __DIR__ . '/../DB.php';

// Función principal que registra todas las rutas relacionadas a mazos
function mazo(App $app) {

    // ------------------------- POST /mazos -------------------------
    // Endpoint para crear un nuevo mazo con hasta 5 cartas
    $app->post('/mazos', function (Request $request, Response $response) {

        // JWTMiddleware agrega este atributo si el token es válido
        $usuarioId = $request->getAttribute('usuario_id');

        // Decodifico el body JSON de la solicitud
        $data = json_decode($request->getBody()->getContents(), true);

        // Verifico que se haya enviado nombre y cartas (y que cartas sea un array)
        if (!isset($data['nombre'], $data['cartas']) || !is_array($data['cartas'])) {
            $response->getBody()->write(json_encode(['error' => 'Faltan datos: nombre o cartas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Valido cantidad de cartas y que no estén repetidas
        $cartas = $data['cartas'];
        if (count($cartas) > 5 || count(array_unique($cartas)) !== count($cartas)) {
            $response->getBody()->write(json_encode(['error' => 'Debe enviar hasta 5 cartas únicas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Me conecto a la base de datos
            $pdo = DB::getConnection();

            // Verifico que todas las cartas existan
            $placeholders = implode(',', array_fill(0, count($cartas), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM carta WHERE id IN ($placeholders)");
            $stmt->execute($cartas);
            if ($stmt->fetchColumn() != count($cartas)) {
                $response->getBody()->write(json_encode(['error' => 'Una o más cartas no existen']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Verifico que el usuario no tenga más de 3 mazos
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            if ($stmt->fetchColumn() >= 3) {
                $response->getBody()->write(json_encode(['error' => 'Máximo de 3 mazos alcanzado']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Inserto el nuevo mazo
            $stmt = $pdo->prepare("INSERT INTO mazo (usuario_id, nombre) VALUES (?, ?)");
            $stmt->execute([$usuarioId, $data['nombre']]);
            $mazoId = $pdo->lastInsertId(); // Guardo el ID del nuevo mazo

            // Inserto las cartas dentro de ese mazo en la tabla mazo_carta
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'en_mazo')");
            foreach ($cartas as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Respondo con el ID y nombre del mazo creado
            $response->getBody()->write(json_encode(['id' => $mazoId, 'nombre' => $data['nombre']]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (PDOException $e) {
            // Si algo falla en la base de datos, devuelvo el error
            $response->getBody()->write(json_encode(['error' => 'Error al crear el mazo: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    })->add(new JwtMiddleware()); // Protejo esta ruta con middleware JWT


    // ------------------------- DELETE /mazos/{mazo} -------------------------
    // Elimina un mazo si no fue usado en partidas
    $app->delete('/mazos/{mazo}', function (Request $request, Response $response, array $args) {
        $usuarioId = $request->getAttribute('usuario_id');
        $mazoId = $args['mazo'];

        try {
            $pdo = DB::getConnection();

            // Verifico si ese mazo ya fue usado en alguna partida
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM partida WHERE mazo_id = ?");
            $stmt->execute([$mazoId]);
            if ($stmt->fetchColumn() > 0) {
                $response->getBody()->write(json_encode(['error' => 'El mazo ya participó en una partida']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Borro las cartas del mazo y luego el mazo en sí
            $pdo->prepare("DELETE FROM mazo_carta WHERE mazo_id = ?")->execute([$mazoId]);
            $pdo->prepare("DELETE FROM mazo WHERE id = ? AND usuario_id = ?")->execute([$mazoId, $usuarioId]);

            $response->getBody()->write(json_encode(['mensaje' => 'Mazo eliminado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Error al eliminar el mazo: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    })->add(new JwtMiddleware());


    // ------------------------- GET /usuarios/{usuario}/mazos -------------------------
    // Lista los mazos creados por el usuario autenticado
    $app->get('/usuarios/{usuario}/mazos', function (Request $request, Response $response) {
        $usuarioId = $request->getAttribute('usuario_id');

        try {
            $pdo = DB::getConnection();

            // Traigo todos los mazos de este usuario
            $stmt = $pdo->prepare("SELECT id, nombre FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $mazos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($mazos));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Error al obtener los mazos: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    })->add(new JwtMiddleware());


    // ------------------------- PUT /mazos/{mazo} -------------------------
    // Permite cambiar el nombre de un mazo ya existente
    $app->put('/mazos/{mazo}', function (Request $request, Response $response, array $args) {
        $usuarioId = $request->getAttribute('usuario_id');
        $mazoId = $args['mazo'];

        $data = json_decode($request->getBody()->getContents(), true);

        // Verifico que venga el nuevo nombre
        if (!isset($data['nombre'])) {
            $response->getBody()->write(json_encode(['error' => 'Falta el nuevo nombre']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $pdo = DB::getConnection();
            $stmt = $pdo->prepare("UPDATE mazo SET nombre = ? WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$data['nombre'], $mazoId, $usuarioId]);

            $response->getBody()->write(json_encode(['mensaje' => 'Nombre del mazo actualizado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Error al actualizar el mazo: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    })->add(new JwtMiddleware());


    // ------------------------- GET /cartas -------------------------
    // Lista cartas filtradas por atributo y/o nombre
    $app->get('/cartas', function (Request $request, Response $response) {
        // Obtengo los parámetros de búsqueda (pueden no venir)
        $params = $request->getQueryParams();
        $atributo = $params['atributo'] ?? null;
        $nombre = $params['nombre'] ?? null;

        try {
            $pdo = DB::getConnection();

            // Armo la query dinámica con filtros
            $sql = "SELECT * FROM carta WHERE 1=1";
            $values = [];

            if ($atributo) {
                $sql .= " AND atributo_id = ?";
                $values[] = $atributo;
            }

            if ($nombre) {
                $sql .= " AND nombre LIKE ?";
                $values[] = '%' . $nombre . '%';
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($cartas));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Error al obtener cartas: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
