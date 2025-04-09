<?php
// Importamos las clases necesarias del JWT y de PSR-7 para trabajar con Slim
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Definimos la función que configura la ruta '/registro'
function registro(App $app) {
    // Definimos una ruta POST para '/registro'
    $app->post('/registro', function (Request $request, Response $response, $args) {
        // Obtenemos el contenido del cuerpo de la petición (body) como string
        $body = (string) $request->getBody();

        // Convertimos el string JSON en un array asociativo de PHP
        $data = json_decode($body, true);

        // Verificamos si ocurrió un error al parsear el JSON
        if ($data === null) {
            $response->getBody()->write(json_encode(["error" => "Error al leer JSON"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Verificamos si están presentes los campos obligatorios
        if (!isset($data['nombre']) || !isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            // Conexión a la base de datos MySQL usando PDO
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Lanza excepciones ante errores

            // Consulta para verificar si el nombre de usuario ya existe
            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC); // Obtenemos el usuario si existe

            // Si ya hay un usuario con ese nombre, devolvemos error
            if ($user) {
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Encriptamos la contraseña usando BCRYPT para mayor seguridad
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            // Insertamos el nuevo usuario en la base de datos con token vacío temporalmente
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['nombre'],                      // nombre real del usuario
                $data['usuario'],                     // nombre de usuario único
                $hashedPassword,                      // contraseña encriptada
                '',                                   // token vacío por ahora
                date('Y-m-d H:i:s', strtotime('+1 hour')) // vencimiento del token 1 hora desde ahora
            ]);

            // Obtenemos el ID del nuevo usuario insertado para usarlo en el JWT
            $usuarioId = $pdo->lastInsertId();

            // Clave secreta para firmar el token JWT
            $key = "mi_clave_secreta";

            // Creamos el contenido (payload) del JWT
            $payload = [
                'usuario_id' => $usuarioId,     // ID del usuario como identificador
                'exp' => time() + 3600          // Expira en una hora desde ahora
            ];

            // Generamos el token JWT con el payload, clave secreta y algoritmo HS256
            $jwt = JWT::encode($payload, $key, 'HS256');

            // Actualizamos el campo 'token' en la base de datos con el JWT generado
            $stmt = $pdo->prepare("UPDATE usuario SET token = ?, vencimiento_token = ? WHERE id = ?");
            $stmt->execute([
                $jwt,                                    // el token JWT generado
                date('Y-m-d H:i:s', $payload['exp']),    // vencimiento del token (en formato fecha-hora)
                $usuarioId                               // identificador del usuario recién creado
            ]);

            // Respondemos con el token generado en formato JSON
            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (PDOException $e) {
            // Si ocurre un error con la base de datos, respondemos con el mensaje de error
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}
