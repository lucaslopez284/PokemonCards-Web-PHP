<?php
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

function registro(App $app) {
    $app->post('/registro', function (Request $request, Response $response, $args) {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if ($data === null) {
            $response->getBody()->write(json_encode(["error" => "Error al leer JSON"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (!isset($data['nombre']) || !isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $response->getBody()->write(json_encode(["error" => "El nombre de usuario ya está en uso"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO usuario (nombre, usuario, password, token, vencimiento_token) 
                                   VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['nombre'],
                $data['usuario'],
                $hashedPassword,
                '',
                date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]);

            $usuarioId = $pdo->lastInsertId();
            $key = "mi_clave_secreta";
            $payload = ['usuario_id' => $usuarioId, 'exp' => time() + 3600];
            $jwt = JWT::encode($payload, $key, 'HS256');

            $response->getBody()->write(json_encode(['token' => $jwt]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}