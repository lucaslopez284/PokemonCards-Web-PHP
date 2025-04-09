<?php
use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

function login(App $app) {
    $app->post('/login', function (Request $request, Response $response, $args) {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if ($data === null) {
            $response->getBody()->write(json_encode(["error" => "Error al leer JSON"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if (!isset($data['usuario']) || !isset($data['password'])) {
            $response->getBody()->write(json_encode(["error" => "Faltan datos necesarios"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT * FROM usuario WHERE usuario = ?");
            $stmt->execute([$data['usuario']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($data['password'], $user['password'])) {
                $response->getBody()->write(json_encode(["error" => "Usuario o contraseña incorrectos"]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }

            $key = "mi_clave_secreta";
            $payload = ['usuario_id' => $user['id'], 'exp' => time() + 3600];
            $jwt = JWT::encode($payload, $key, 'HS256');

            $stmt = $pdo->prepare("UPDATE usuario set token = '".$jwt."' WHERE usuario = '". $data['usuario']."'");
            $stmt->execute();
            $response->getBody()->write(json_encode(['token' => $jwt]));
          //$response->getBody()->write(json_encode(["UPDATE usuario set token = '".$jwt."' WHERE usuario = '". $data['usuario']."'"]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(["error" => "Error en la base de datos: " . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
}