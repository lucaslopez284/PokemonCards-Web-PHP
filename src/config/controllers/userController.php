<?php
// Importamos la clase JWT para manejar la generación de tokens
use Firebase\JWT\JWT;

// Importamos las interfaces necesarias de PSR para manejar solicitudes y respuestas HTTP
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Importamos la clase App de Slim
use Slim\App;

// Función que define la ruta POST /login
function login(App $app) {
    $app->post('/login', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();

        // Convertimos el JSON a un array asociativo
        $data = json_decode($body, true);

        // Validamos que se hayan enviado los campos requeridos
        if ($data === null || !isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Datos inválidos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // 🔗 Conexión directa a la base de datos usando PDO
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Buscamos el usuario por nombre de usuario
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificamos que el usuario exista y que la contraseña sea válida
            if (!$user || !password_verify($data['password'], $user['password'])) {
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Clave secreta para firmar el JWT
            $key = "mi_clave_secreta";

            // Creamos el payload con el ID del usuario y el tiempo de expiración
            $payload = [
                'usuario_id' => $user['id'],
                'exp' => time() + 3600 // Token válido por 1 hora
            ];

            // Generamos el token JWT
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Guardamos el token y su vencimiento en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $user['id']]);

            // Enviamos el token como respuesta
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Manejamos errores de base de datos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}

// Función que define la ruta POST /registro
function registro(App $app) {
    $app->post('/registro', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();

        // Convertimos el JSON a array asociativo
        $data = json_decode($body, true);

        // Validamos que los campos necesarios estén presentes
        if ($data === null || !isset($data['nombre'], $data['usuario'], $data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // 🔗 Conexión directa a la base de datos usando PDO
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verificamos que el nombre de usuario no esté repetido
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Encriptamos la contraseña
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            // Insertamos el nuevo usuario con token vacío por ahora
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) VALUES (?, ?, ?, '', ?)");
            $stmt->execute([
                $data['nombre'],
                $data['usuario'],
                $hashedPassword,
                date('Y-m-d H:i:s', strtotime('+1 hour')) // token inválido por ahora
            ]);

            // Obtenemos el ID del usuario recién registrado
            $usuarioId = $pdo->lastInsertId();

            // Generamos el JWT
            $key = "mi_clave_secreta";
            $payload = ['usuario_id' => $usuarioId, 'exp' => time() + 3600];
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Guardamos el token generado en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $usuarioId]);

            // Respondemos con el token generado
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Manejamos errores de base de datos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
