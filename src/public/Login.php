<?php
// Importar la clase JWT para generar el token
use Firebase\JWT\JWT;
// Importar interfaces de Slim para manejar Request y Response
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// Importar la clase App de Slim
use Slim\App;

// Función que define la ruta POST /login
function login(App $app) {
    // Definir ruta POST /login
    $app->post('/login', function (Request $request, Response $response, $args) {
        // Obtener el cuerpo de la solicitud como string
        $body = (string) $request->getBody();
        // Decodificar el JSON recibido
        $data = json_decode($body, true);

        // Si no se pudo decodificar el JSON (es inválido o vacío)
        if ($data === null) {
            $response->getBody()->write(json_encode(["error" => "Error al leer JSON"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificar que se hayan enviado el usuario y la contraseña
        if (!isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conectar a la base de datos MySQL (localhost, usuario root sin contraseña)
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            // Habilitar excepciones para errores de PDO
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Preparar consulta para buscar al usuario por su nombre de usuario
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            // Obtener el resultado como arreglo asociativo
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no se encuentra el usuario o la contraseña no coincide
            if (!$user || !password_verify($data['password'], $user['password'])) {
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            // Crear clave secreta y payload del token JWT
            $key = "mi_clave_secreta";
            $payload = [
                'usuario_id' => $user['id'], // ID del usuario
                'exp' => time() + 3600       // Tiempo de expiración: 1 hora
            ];
            // Generar el token JWT
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Guardar el token generado en la base de datos para el usuario
            $stmt = $pdo->prepare("UPDATE usuario set token = '".$jwt."' WHERE usuario = '". $data['usuario']."'");
            $stmt->execute();

            // Devolver el token en la respuesta
            $response->getBody()->write(json_encode(['token' => $jwt]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            // Si ocurre un error de base de datos, devolver el mensaje de error
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
