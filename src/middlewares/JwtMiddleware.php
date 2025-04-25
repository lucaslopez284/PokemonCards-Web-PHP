<?php
// Definimos el namespace donde se encuentra este middleware
namespace App\middlewares;

// Importamos las interfaces necesarias para trabajar con PSR-7 y Slim
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use PDO;

// Definimos la clase JwtMiddleware que actuará como middleware de autenticación
class JwtMiddleware
{
    // Método mágico __invoke para que esta clase pueda ser usada como callable middleware
    public function __invoke(Request $request, Handler $handler): Response
    {
        // Obtenemos el encabezado 'Authorization' de la solicitud HTTP
        $authHeader = $request->getHeaderLine('Authorization');

        // Verificamos que el encabezado comience con 'Bearer ', de lo contrario rechazamos
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            // Si no hay token o el formato es incorrecto, respondemos con 401
            return $this->unauthorizedResponse("Token no proporcionado o formato incorrecto");
        }

        // Extraemos el token quitando el prefijo 'Bearer '
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            // Definimos las credenciales de conexión a la base de datos
            $host = 'localhost';
            $dbname = 'basepokemon';
            $user = 'root';
            $pass = '';
            // Creamos una nueva conexión PDO a la base de datos
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
            // Configuramos PDO para lanzar excepciones en caso de errores
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Preparamos la consulta para verificar si el token existe y no está vencido
            $stmt = $pdo->prepare("SELECT id FROM usuario WHERE token = ? AND vencimiento_token > NOW()");
            // Ejecutamos la consulta pasando el token como parámetro
            $stmt->execute([$token]);
            // Obtenemos el resultado como un arreglo asociativo
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no encontramos un usuario válido con ese token, devolvemos error
            if (!$user) {
                return $this->unauthorizedResponse("Token inválido o vencido");
            }

            // Si el token es válido, agregamos el ID del usuario a los atributos de la request
            $request = $request->withAttribute('usuario_id', $user['id']);

            // Pasamos la request al siguiente middleware o a la ruta correspondiente
            return $handler->handle($request);

        } catch (\Exception $e) {
            // Si ocurre un error durante el proceso, respondemos con error 401 y el mensaje
            return $this->unauthorizedResponse("Error al verificar el token: " . $e->getMessage());
        }
    }

    // Función privada auxiliar que devuelve una respuesta JSON con código 401
    private function unauthorizedResponse($mensaje): Response
    {
        // Creamos una nueva respuesta Slim
        $response = new SlimResponse();
        // Escribimos un mensaje de error en formato JSON en el cuerpo de la respuesta
        $response->getBody()->write(json_encode(['error' => $mensaje]));
        // Configuramos el header como JSON y el status HTTP como 401 (no autorizado)
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
}
