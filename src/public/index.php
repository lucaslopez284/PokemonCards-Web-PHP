<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../../vendor/autoload.php';

$app = AppFactory::create();

// Incluir archivos con rutas
require_once __DIR__ . '/login.php';
require_once __DIR__ . '/registro.php';

// Definir rutas de login y registro
login($app);
registro($app);

// Ruta raíz: Hola mundo en texto
$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hola mundo");
    return $response;
});

// Ruta JSON de prueba
$app->get('/hola', function (Request $request, Response $response, $args) {
    $data = ["mensaje" => "Hola mundo API"];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();