<?php
// Importamos las interfaces necesarias de PSR para manejar las solicitudes y respuestas
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Importamos la clase que nos permite crear la app de Slim
use Slim\Factory\AppFactory;

// Cargamos el autoloader de Composer para poder usar todas las dependencias (Slim, JWT, etc.)
require __DIR__ . '/../../vendor/autoload.php';

// Creamos una nueva instancia de la aplicación Slim
$app = AppFactory::create();

// Agregamos el middleware de enrutamiento (Slim lo necesita para manejar rutas)
$app->addRoutingMiddleware();

// Agregamos el middleware para parsear el cuerpo de las peticiones (JSON, etc.)
$app->addBodyParsingMiddleware();

// Agregamos el middleware para manejar errores (muestra excepciones y detalles en pantalla)
$app->addErrorMiddleware(true, true, true);

// ✅ Registramos todas las rutas definidas en el archivo routes.php
(require __DIR__ . '/../config/routes/routes.php')($app); // Importa la función y la ejecuta

// ✔ Ruta de prueba raíz ('/') que devuelve texto plano
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("Hola mundo");
    return $response;
});

// ✔ Ruta de prueba '/hola' que devuelve un mensaje en formato JSON
$app->get('/hola', function (Request $request, Response $response) {
    $data = ["mensaje" => "Hola mundo API"];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// 🚀 Ejecutamos la aplicación Slim (esto siempre debe ir al final)
$app->run();

