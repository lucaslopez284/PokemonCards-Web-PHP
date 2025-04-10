<?php
// Importar interfaces necesarias para manejar solicitudes y respuestas HTTP
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
// Importar la fábrica para crear la aplicación Slim
use Slim\Factory\AppFactory;

// Cargar el autoloader de Composer (ubicado dos niveles arriba desde este archivo)
require __DIR__ . '/../../vendor/autoload.php';

// Crear una nueva instancia de la aplicación Slim
$app = AppFactory::create();

require_once __DIR__ . '/../config/routes/routes.php'; //Importa las rutas de la app.


// Registrar las rutas definidas en login.php y registro.php
login($app);     // Registra la ruta POST /login
registro($app);  // Registra la ruta POST /registro

// Ruta GET raíz ('/') que devuelve texto plano "Hola mundo"
$app->get('/', function (Request $request, Response $response, $args) {
    // Escribe "Hola mundo" en el cuerpo de la respuesta
    $response->getBody()->write("Hola mundo");
    return $response;  // Devuelve la respuesta
});

// Ruta GET '/hola' que devuelve un JSON con un mensaje
$app->get('/hola', function (Request $request, Response $response, $args) {
    // Crear un arreglo con el mensaje
    $data = ["mensaje" => "Hola mundo API"];
    // Escribir el JSON en el cuerpo de la respuesta
    $response->getBody()->write(json_encode($data));
    // Añadir el header 'Content-Type: application/json'
    return $response->withHeader('Content-Type', 'application/json');
});

// Ejecutar la aplicación Slim (esto debe estar al final del archivo)
$app->run();
