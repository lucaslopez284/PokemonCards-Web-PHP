<?php
// Interfaces PSR-7 para manejar solicitudes (Request) y respuestas (Response)
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Slim\App se necesita para definir las rutas dentro de la app
use Slim\App;

// Incluimos nuestra clase de conexión a la base de datos (ubicada en config/DB.php)
require_once __DIR__ . '/../DB.php';

// Middleware JWT para la autenticación 
use App\config\middlewares\JwtMiddleware; 


// ---------------------------------------------------------------------------
// RUTA: POST /mazos
// Permite crear un nuevo mazo para un usuario con hasta 5 cartas válidas
// ---------------------------------------------------------------------------
function crearMazo(App $app) {
    // Definimos la ruta POST para crear un nuevo mazo
    $app->post('/mazos', function (Request $request, Response $response) {
        // Obtenemos el ID del usuario desde los atributos, lo ha inyectado el middleware JWT
        
        $usuarioId = $request->getAttribute('usuario_id');
        
        // Verificamos si el usuario_id está presente. Si no, devolvemos un error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "No se pudo obtener el ID del usuario"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Leemos el cuerpo de la solicitud como texto
        $body = (string) $request->getBody();

        // Lo decodificamos desde JSON a un arreglo asociativo
        $data = json_decode($body, true);

        // Verificamos que vengan el nombre del mazo y el array de cartas
        if (!isset($data['nombre'], $data['cartas']) || !is_array($data['cartas'])) {
            // Si faltan datos o los datos son inválidos, devolvemos error 400
            $response->getBody()->write(json_encode(["error" => "Datos inválidos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificamos que no haya más de 5 cartas
        if (count($data['cartas']) > 5) {
            // Si hay más de 5 cartas, devolvemos error 400
            $response->getBody()->write(json_encode(["error" => "Un mazo puede tener hasta 5 cartas"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Obtenemos la conexión a la base de datos usando tu clase DB
            $pdo = DB::getConnection();

            // Verificamos si el usuario ya tiene 3 mazos creados
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            $count = $stmt->fetchColumn();

            // Si ya tiene 3, devolvemos error
            if ($count >= 3) {
                $response->getBody()->write(json_encode(["error" => "El usuario ya tiene 3 mazos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Insertamos un nuevo mazo con el nombre enviado y el ID de usuario
            $stmt = $pdo->prepare("INSERT INTO mazo (usuario_id, nombre) VALUES (?, ?)");
            $stmt->execute([$usuarioId, $data['nombre']]);

            // Obtenemos el ID del mazo recién creado
            $mazoId = $pdo->lastInsertId();

            // Insertamos cada carta en la tabla mazo_carta con estado 'activo'
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'activo')");

            // Recorremos las cartas recibidas y las insertamos en la tabla mazo_carta
            foreach ($data['cartas'] as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Devolvemos el ID del mazo nuevo y su nombre en la respuesta
            $response->getBody()->write(json_encode([
                "mazo_id" => $mazoId,
                "nombre" => $data['nombre']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (PDOException $e) {
            // En caso de error con la base, lo mostramos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // CORRECCIÓN: Aplicar middleware con ->add()
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
    })->add(new JwtMiddleware()); // CORRECCIÓN: Aplicar middleware con ->add()
}


// ---------------------------------------------------------------------------
// RUTA: GET /usuarios/{usuario}/mazos
// Devuelve todos los mazos que pertenecen a un usuario específico
// ---------------------------------------------------------------------------
function listarMazos(App $app) {
    $app->get('/usuarios/{usuario}/mazos', function (Request $request, Response $response, array $args) {
        // Obtenemos el nombre de usuario desde la ruta
        $nombreUsuario = $args['usuario'];

        try {
            // Conexión a la base de datos
            $pdo = DB::getConnection();

            // Obtenemos el ID del usuario a partir del nombre
            $stmt = $pdo->prepare("SELECT id FROM usuario WHERE usuario = ?");
            $stmt->execute([$nombreUsuario]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si el usuario no existe, devolvemos error
            if (!$usuario) {
                $response->getBody()->write(json_encode(["error" => "Usuario no encontrado"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $usuarioId = $usuario['id'];

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
    })->add(new JwtMiddleware()); // Middleware JWT aplicado
}


// ---------------------------------------------------------------------------
// RUTA: PUT /mazos/{mazo}
// Permite actualizar el nombre las cartas de un mazo existente
// ---------------------------------------------------------------------------
function actualizarMazo(App $app) {
    $app->put('/mazos/{mazo}', function (Request $request, Response $response, array $args) {
        // Obtenemos el ID del mazo desde la URL
        $mazoId = $args['mazo'];

        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        // Verificamos que vengan cartas válidas (hasta 5)
        if (!isset($data['cartas']) || !is_array($data['cartas']) || count($data['cartas']) > 5) {
            $response->getBody()->write(json_encode(["error" => "Se deben enviar hasta 5 cartas"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conectamos a la base de datos
            $pdo = DB::getConnection();

            // Si se recibió un nuevo nombre, lo actualizamos
            if (isset($data['nombre']) && !empty(trim($data['nombre']))) {
                $stmt = $pdo->prepare("UPDATE mazo SET nombre = ? WHERE id = ?");
                $stmt->execute([$data['nombre'], $mazoId]);
            }

            // Eliminamos las cartas anteriores del mazo
            $stmt = $pdo->prepare("DELETE FROM mazo_carta WHERE mazo_id = ?");
            $stmt->execute([$mazoId]);

            // Insertamos las nuevas cartas asociadas al mazo
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'activo')");
            foreach ($data['cartas'] as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Enviamos un mensaje de éxito
            $response->getBody()->write(json_encode(["mensaje" => "Mazo actualizado correctamente"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Mostramos el error en caso de que ocurra
            $response->getBody()->write(json_encode(["error" => "Error al actualizar el mazo: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // Aplicamos el middleware JWT
}



// ---------------------------------------------------------------------------
// RUTA: GET /cartas
// Devuelve todas las cartas disponibles, con posibilidad de filtrar por atributo y nombre
// ---------------------------------------------------------------------------
function listarCartas(App $app) {
    // Definimos la ruta GET /cartas
    $app->get('/cartas', function (Request $request, Response $response) {
        // Obtenemos los parámetros de la query string (si existen)
        $params = $request->getQueryParams();
        $atributo = $params['atributo'] ?? null; // Filtro por atributo
        $nombre = $params['nombre'] ?? null;     // Filtro por nombre

        try {
            // Obtenemos la conexión a la base de datos usando la clase DB
            $pdo = DB::getConnection();

            // Armamos la consulta base y sus condiciones dinámicamente
            $sql = "SELECT * FROM carta";
            $conditions = [];
            $values = [];

            // Si se pasa un atributo como filtro
            if ($atributo) {
                $conditions[] = "atributo_id = ?";
                $values[] = $atributo;
            }

            // Si se pasa un nombre como filtro
            if ($nombre) {
                $conditions[] = "nombre LIKE ?";
                $values[] = "%" . $nombre . "%"; // Búsqueda parcial por nombre
            }

            // Si hay condiciones, las unimos con AND
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }

            // Preparamos y ejecutamos la consulta
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            // Obtenemos las cartas y las devolvemos como JSON
            $cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response->getBody()->write(json_encode($cartas));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Manejamos cualquier error de base de datos
            $response->getBody()->write(json_encode(["error" => "Error al obtener cartas: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // CORRECCIÓN: Aplicar middleware con ->add()
}