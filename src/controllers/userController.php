<?php
// Importamos la clase JWT de Firebase para generar y firmar los tokens de sesión
use Firebase\JWT\JWT;

// Interfaces PSR-7 para manejar peticiones (Request) y respuestas (Response)
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Slim\App se necesita para definir rutas dentro de la aplicación
use Slim\App;

// Incluimos directamente la clase DB personalizada, ubicada en config/DB.php
// No usa namespace, así que con require_once es suficiente
require_once __DIR__ . '/../config/DB.php';



// Middleware JWT para la autenticación 
use App\middlewares\JwtMiddleware;

// -------------------------------------------------------------
// RUTA: POST /login
// Permite a un usuario autenticarse con su nombre de usuario y contraseña.
// Devuelve un JWT válido por 1 hora si las credenciales son correctas.
// -------------------------------------------------------------
// -------------------------------------------------------------
// RUTA: POST /login
// Permite a un usuario autenticarse con su nombre de usuario y contraseña.
// Devuelve un JWT válido por 1 hora si las credenciales son correctas,
// incluyendo la hora de vencimiento en horario argentino.
// -------------------------------------------------------------
function login(App $app) {
    $app->post('/login', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud como string
        $body = (string) $request->getBody();

        // Decodificamos el JSON del cuerpo de la solicitud
        $data = json_decode($body, true);

        // Verificamos que el JSON haya sido bien formado y que tenga los campos requeridos
        if ($data === null || !isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Datos inválidos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Obtenemos una conexión activa a la base de datos utilizando nuestra clase DB
            $pdo = DB::getConnection();

            // Buscamos al usuario por su nombre de usuario
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no se encuentra el usuario o la contraseña no coincide, devolvemos error
            if (!$user || !password_verify($data['password'], $user['password'])) {
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Definimos la clave secreta para firmar el token
            $key = "mi_clave_secreta";

            // Creamos el payload del token con el ID de usuario y la expiración a 1 hora
            $payload = [
                'usuario_id' => $user['id'],
                'exp' => time() + 3600
            ];

            // Generamos el token JWT
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Convertimos el tiempo de expiración a horario argentino
            $expTimestamp = $payload['exp'];
            $expDateTime = new DateTime("@$expTimestamp"); // Creamos objeto DateTime desde timestamp
            $expDateTime->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); // Ajustamos a Buenos Aires
            $fechaVencimientoArg = $expDateTime->format('Y-m-d H:i:s'); // Formateamos la fecha

            // Guardamos el token y la fecha de vencimiento en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, $fechaVencimientoArg, $user['id']]);

            // Devolvemos el token y la fecha de vencimiento como respuesta
            $response->getBody()->write(json_encode([
                'token' => $jwt,
                'vencimiento' => $fechaVencimientoArg
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // En caso de error con la base de datos, devolvemos el mensaje de error
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}

// -------------------------------------------------------------
// RUTA: POST /registro
// Permite registrar un nuevo usuario en la base de datos.
// Devuelve un token JWT generado automáticamente para el nuevo usuario.
// -------------------------------------------------------------
// -------------------------------------------------------------
// RUTA: POST /registro
// Permite registrar un nuevo usuario en la base de datos.
// Devuelve un token JWT generado automáticamente para el nuevo usuario,
// incluyendo la hora de vencimiento en horario argentino.
// -------------------------------------------------------------
function registro(App $app) {
    $app->post('/registro', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud como string
        $body = (string) $request->getBody();

        // Convertimos el JSON en un array asociativo
        $data = json_decode($body, true);

        // Validamos que se hayan enviado todos los campos requeridos
        if ($data === null || !isset($data['nombre'], $data['usuario'], $data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Establecemos la conexión con la base de datos
            $pdo = DB::getConnection();

            // Verificamos que no exista ya un usuario con el mismo nombre de usuario
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Encriptamos la contraseña antes de guardarla en la base de datos
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            // Insertamos el nuevo usuario en la base de datos con token vacío
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) VALUES (?, ?, ?, '', '')");
            $stmt->execute([
                $data['nombre'],
                $data['usuario'],
                $hashedPassword
            ]);

            // Obtenemos el ID del usuario recién insertado
            $usuarioId = $pdo->lastInsertId();

            // Definimos la clave secreta para firmar el token
            $key = "mi_clave_secreta";

            // Creamos el payload del token con el ID de usuario y la expiración a 1 hora
            $payload = [
                'usuario_id' => $usuarioId,
                'exp' => time() + 3600
            ];

            // Generamos el token JWT
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Convertimos el tiempo de expiración a horario argentino
            $expTimestamp = $payload['exp'];
            $expDateTime = new DateTime("@$expTimestamp"); // Creamos objeto DateTime desde timestamp
            $expDateTime->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires')); // Ajustamos a Buenos Aires
            $fechaVencimientoArg = $expDateTime->format('Y-m-d H:i:s'); // Formateamos la fecha

            // Actualizamos el usuario recién creado con el token y la fecha de vencimiento
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, $fechaVencimientoArg, $usuarioId]);

            // Devolvemos el token y la fecha de vencimiento como respuesta
            $response->getBody()->write(json_encode([
                'token' => $jwt,
                'vencimiento' => $fechaVencimientoArg
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // En caso de error con la base de datos, devolvemos el mensaje de error
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}

// -------------------------------------------------------------
// RUTA: GET /usuarios/{usuario}
// Permite obtener la información de un usuario específico.
// Devuelve los detalles del usuario solicitados en formato JSON.
// Requiere que el usuario esté autenticado mediante JWT.
// -------------------------------------------------------------

function obtenerUsuario(App $app) {
    $app->get('/usuarios/{usuario}', function (Request $request, Response $response, array $args) {
        // Obtenemos el ID del usuario desde los atributos, lo ha inyectado el middleware JWT
        $usuarioId = $request->getAttribute('usuario_id');
        
        // Verificamos si el usuario_id está presente. Si no, devolvemos un error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "No se pudo obtener el ID del usuario"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Obtenemos el nombre de usuario de los parámetros de la ruta
        $usuarioParam = $args['usuario'];

        try {
            // Conectamos a la base de datos
            $pdo = DB::getConnection();

            // Obtenemos los datos del usuario desde la base
            $stmt = $pdo->prepare("SELECT id, nombre, usuario FROM usuario WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificamos que el nombre de usuario coincida con el que se solicita
            if (!$usuario || $usuario['usuario'] !== $usuarioParam) {
                $response->getBody()->write(json_encode(["error" => "Acceso denegado"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Devolvemos la información del usuario
            $response->getBody()->write(json_encode($usuario));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (Exception $e) {
            // En caso de error con la base
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // Aplicamos el middleware JwtMiddleware
}

// -------------------------------------------------------------
// RUTA: PUT /usuarios/{usuario}
// Permite editar la información de un usuario específico.
// Requiere que el usuario esté autenticado mediante JWT.
// Los cambios se aplican a los detalles del usuario en la base de datos.
// -------------------------------------------------------------

function editarUsuario(App $app) {
    $app->put('/usuarios/{usuario}', function (Request $request, Response $response, array $args) {
        // Obtenemos el ID del usuario desde los atributos, lo ha inyectado el middleware JWT
        $usuarioId = $request->getAttribute('usuario_id');
        
        // Verificamos si el usuario_id está presente. Si no, devolvemos un error 401
        if (!$usuarioId) {
            $response->getBody()->write(json_encode(["error" => "No se pudo obtener el ID del usuario"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // Obtenemos el nombre de usuario de los parámetros de la ruta
        $usuarioParam = $args['usuario'];

        // Leemos el cuerpo de la solicitud como string
        $body = (string) $request->getBody();

        // Convertimos el JSON en un array asociativo
        $data = json_decode($body, true);

        // Validamos que se hayan enviado al menos nombre o password
        if ($data === null || (!isset($data['nombre']) && !isset($data['password']))) {
            $response->getBody()->write(json_encode(["error" => "No se enviaron datos para actualizar"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conectamos a la base de datos
            $pdo = DB::getConnection();

            // Obtenemos al usuario para validar que sea el correcto
            $stmt = $pdo->prepare("SELECT usuario FROM usuario WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario || $usuario['usuario'] !== $usuarioParam) {
                $response->getBody()->write(json_encode(["error" => "Acceso denegado"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Construimos el SQL dinámico según los campos enviados
            $campos = [];
            $valores = [];

            if (isset($data['nombre'])) {
                $campos[] = "nombre = ?";
                $valores[] = $data['nombre'];
            }

            if (isset($data['password'])) {
                $campos[] = "password = ?";
                $valores[] = password_hash($data['password'], PASSWORD_BCRYPT);
            }

            // Agregamos el ID del usuario al final para el WHERE
            $valores[] = $usuarioId;

            // Ejecutamos la actualización
            $stmt = $pdo->prepare("UPDATE usuario SET " . implode(', ', $campos) . " WHERE id = ?");
            $stmt->execute($valores);

            // Confirmamos la actualización
            $response->getBody()->write(json_encode(["mensaje" => "Usuario actualizado correctamente"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (Exception $e) {
            // En caso de error con la base
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    })->add(new JwtMiddleware()); // Aplicamos el middleware JwtMiddleware
}
