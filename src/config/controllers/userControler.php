<?php
// Importamos las clases necesarias
use Firebase\JWT\JWT; // Para generar tokens JWT
use Psr\Http\Message\ResponseInterface as Response; // Interfaz de Slim para respuestas HTTP
use Psr\Http\Message\ServerRequestInterface as Request; // Interfaz de Slim para solicitudes HTTP
use Slim\App; // Clase principal de la app Slim

// Función para registrar la ruta POST /login
function login(App $app) {
    // Registramos la ruta POST /login en la app Slim
    $app->post('/login', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();
        // Decodificamos el JSON recibido en un array asociativo
        $data = json_decode($body, true);

        // Verificamos si el JSON no se pudo decodificar o faltan campos
        if ($data === null || !isset($data['usuario']) || !isset($data['password'])) {
            // Enviamos una respuesta de error 400 por datos inválidos
            $response->getBody()->write(json_encode(["error" => "Datos inválidos"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conexión a la base de datos MySQL
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            // Activamos las excepciones para errores de base de datos
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Buscamos al usuario en la base de datos por nombre de usuario
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificamos si el usuario existe y si la contraseña es correcta
            if (!$user || !password_verify($data['password'], $user['password'])) {
                // Si falla, devolvemos error 401
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Generamos un token JWT con clave secreta
            $key = "mi_clave_secreta";
            $payload = [
                'usuario_id' => $user['id'],        // ID del usuario
                'exp' => time() + 3600              // Expira en 1 hora
            ];
            $jwt = JWT::encode($payload, $key, 'HS256'); // Codificamos el token

            // Guardamos el token y su vencimiento en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $user['id']]);

            // Devolvemos el token al cliente
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            // Si hay un error con la base de datos, lo devolvemos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}

// Función para registrar la ruta POST /registro
function registro(App $app) {
    // Registramos la ruta POST /registro
    $app->post('/registro', function (Request $request, Response $response) {
        // Leemos el cuerpo de la solicitud
        $body = (string) $request->getBody();
        // Decodificamos el JSON recibido
        $data = json_decode($body, true);

        // Validamos que estén todos los campos necesarios
        if ($data === null || !isset($data['nombre'], $data['usuario'], $data['password'])) {
            // Si no están, devolvemos error 400
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conectamos a la base de datos
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verificamos que el nombre de usuario no exista ya
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                // Si ya existe, devolvemos error 400
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Encriptamos la contraseña
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            // Insertamos el nuevo usuario
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) VALUES (?, ?, ?, '', ?)");
            $stmt->execute([
                $data['nombre'],
                $data['usuario'],
                $hashedPassword,
                date('Y-m-d H:i:s', strtotime('+1 hour')) // Fecha de vencimiento provisoria
            ]);

            // Obtenemos el ID del usuario recién insertado
            $usuarioId = $pdo->lastInsertId();

            // Generamos el token JWT
            $key = "mi_clave_secreta";
            $payload = ['usuario_id' => $usuarioId, 'exp' => time() + 3600];
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Actualizamos el token y vencimiento en la base de datos
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([$jwt, date('Y-m-d H:i:s', $payload['exp']), $usuarioId]);

            // Devolvemos el token generado al cliente
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            // Si hay un error con la base de datos
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
