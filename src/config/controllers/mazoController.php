<?php
// Interfaces PSR-7 para manejar solicitudes (Request) y respuestas (Response)
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Slim\App se necesita para definir las rutas dentro de la app
use Slim\App;

// Incluimos nuestra clase de conexión a la base de datos (ubicada en config/DB.php)
require_once __DIR__ . '/../DB.php';


// ---------------------------------------------------------------------------
// RUTA: POST /mazos
// Permite crear un nuevo mazo para un usuario con hasta 5 cartas válidas
// ---------------------------------------------------------------------------
function crearMazo(App $app) {
    $app->post('/mazos', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud como texto
        $body = (string) $request->getBody();

        // Lo decodificamos desde JSON a un arreglo asociativo
        $data = json_decode($body, true);

        // Verificamos que venga el ID de usuario y el array de cartas
        if (!isset($data['usuario_id'], $data['cartas']) || !is_array($data['cartas'])) {
            $response->getBody()->write(json_encode(["error" => "Datos inválidos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificamos que no haya más de 5 cartas
        if (count($data['cartas']) > 5) {
            $response->getBody()->write(json_encode(["error" => "Un mazo puede tener hasta 5 cartas"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Obtenemos la conexión a la base de datos
            $pdo = DB::getConnection();

            // Verificamos si el usuario ya tiene 3 mazos creados
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$data['usuario_id']]);
            $count = $stmt->fetchColumn();

            // Si ya tiene 3, devolvemos error
            if ($count >= 3) {
                $response->getBody()->write(json_encode(["error" => "El usuario ya tiene 3 mazos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Insertamos un nuevo mazo vacío para el usuario
            $stmt = $pdo->prepare("INSERT INTO mazo (usuario_id) VALUES (?)");
            $stmt->execute([$data['usuario_id']]);

            // Obtenemos el ID del mazo recién creado
            $mazoId = $pdo->lastInsertId();

            // Insertamos cada carta en la tabla mazo_carta con estado 'activo'
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'activo')");

            foreach ($data['cartas'] as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Devolvemos el ID del mazo nuevo
            $response->getBody()->write(json_encode(["mazo_id" => $mazoId]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (PDOException $e) {
            // En caso de error con la base, lo mostramos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}


// ---------------------------------------------------------------------------
// RUTA: DELETE /mazos/{mazo}
// Permite eliminar un mazo existente por su ID
// ---------------------------------------------------------------------------
function eliminarMazo(App $app) {
    $app->delete('/mazos/{mazo}', function (Request $request, Response $response, array $args) {
        // Tomamos el ID del mazo desde la URL
        $mazoId = $args['mazo'];

        try {
            // Obtenemos conexión
            $pdo = DB::getConnection();

            // Eliminamos primero las cartas del mazo
            $stmt = $pdo->prepare("DELETE FROM mazo_carta WHERE mazo_id = ?");
            $stmt->execute([$mazoId]);

            // Luego eliminamos el mazo
            $stmt = $pdo->prepare("DELETE FROM mazo WHERE id = ?");
            $stmt->execute([$mazoId]);

            // Respondemos con éxito
            $response->getBody()->write(json_encode(["mensaje" => "Mazo eliminado correctamente"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Mostramos errores de base
            $response->getBody()->write(json_encode(["error" => "Error al eliminar el mazo: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}


// ---------------------------------------------------------------------------
// RUTA: GET /usuarios/{usuario}/mazos
// Devuelve todos los mazos que pertenecen a un usuario específico
// ---------------------------------------------------------------------------
function listarMazos(App $app) {
    $app->get('/usuarios/{usuario}/mazos', function (Request $request, Response $response, array $args) {
        // Obtenemos el ID del usuario desde la ruta
        $usuarioId = $args['usuario'];

        try {
            // Conexión a la base
            $pdo = DB::getConnection();

            // Traemos todos los mazos de ese usuario
            $stmt = $pdo->prepare("SELECT * FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $mazos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enviamos el resultado como JSON
            $response->getBody()->write(json_encode($mazos));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Error de base
            $response->getBody()->write(json_encode(["error" => "Error al obtener mazos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}


// ---------------------------------------------------------------------------
// RUTA: PUT /mazos/{mazo}
// Permite actualizar las cartas de un mazo existente
// ---------------------------------------------------------------------------
function actualizarMazo(App $app) {
    $app->put('/mazos/{mazo}', function (Request $request, Response $response, array $args) {
        // Obtenemos el ID del mazo desde la URL
        $mazoId = $args['mazo'];

        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        // Verificamos que vengan cartas válidas
        if (!isset($data['cartas']) || !is_array($data['cartas']) || count($data['cartas']) > 5) {
            $response->getBody()->write(json_encode(["error" => "Se deben enviar hasta 5 cartas"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conectamos a la base
            $pdo = DB::getConnection();

            // Borramos cartas anteriores
            $stmt = $pdo->prepare("DELETE FROM mazo_carta WHERE mazo_id = ?");
            $stmt->execute([$mazoId]);

            // Insertamos las nuevas cartas
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'activo')");
            foreach ($data['cartas'] as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Confirmamos la actualización
            $response->getBody()->write(json_encode(["mensaje" => "Mazo actualizado correctamente"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Mostramos errores
            $response->getBody()->write(json_encode(["error" => "Error al actualizar el mazo: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}


// ---------------------------------------------------------------------------
// RUTA: GET /cartas
// Devuelve todas las cartas disponibles, con posibilidad de filtrar por atributo
// ---------------------------------------------------------------------------
function listarCartas(App $app) {
    $app->get('/cartas', function (Request $request, Response $response) {
        // Obtenemos los parámetros de la query
        $params = $request->getQueryParams();
        $atributo = $params['atributo'] ?? null;

        try {
            // Conexión activa
            $pdo = DB::getConnection();

            // Si hay filtro por atributo, lo aplicamos
            if ($atributo) {
                $stmt = $pdo->prepare("SELECT * FROM carta WHERE atributo_id = ?");
                $stmt->execute([$atributo]);
            } else {
                // Si no hay filtro, traemos todas las cartas
                $stmt = $pdo->query("SELECT * FROM carta");
            }

            // Devolvemos el resultado en JSON
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response->getBody()->write(json_encode($cartas));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Si hay un error de base
            $response->getBody()->write(json_encode(["error" => "Error al obtener cartas: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
