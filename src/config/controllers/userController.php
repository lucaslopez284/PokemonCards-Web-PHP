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
require_once __DIR__ . '/../DB.php';

// -------------------------------------------------------------
// RUTA: POST /login
// Permite a un usuario autenticarse con su nombre de usuario y contraseña.
// Devuelve un JWT válido por 1 hora si las credenciales son correctas.
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

            // Datos del token: ID de usuario y fecha de expiración (una hora desde ahora)
            $key = "mi_clave_secreta";
            $payload = [
                'usuario_id' => $user['id'],
                'exp' => time() + 3600
            ];

            // Generamos el token JWT
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Guardamos el token y la fecha de expiración en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $user['id']]);

            // Devolvemos el token como respuesta
            $response->getBody()->write(json_encode(['token' => $jwt]));
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

            // Validamos que no exista ya un usuario con ese nombre
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Encriptamos la contraseña antes de guardarla en la base de datos
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            // Insertamos el nuevo usuario con un token vacío (se llenará después)
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) VALUES (?, ?, ?, '', ?)");
            $stmt->execute([
                $data['nombre'],
                $data['usuario'],
                $hashedPassword,
                date('Y-m-d H:i:s', strtotime('+1 hour')) // Vencimiento provisorio
            ]);

            // Obtenemos el ID del usuario recién insertado
            $usuarioId = $pdo->lastInsertId();

            // Creamos el JWT para el nuevo usuario
            $key = "mi_clave_secreta";
            $payload = ['usuario_id' => $usuarioId, 'exp' => time() + 3600];
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Guardamos el token y la expiración en la base
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $usuarioId]);

            // Devolvemos el token como respuesta
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // En caso de error con la base de datos, lo mostramos en la respuesta
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
