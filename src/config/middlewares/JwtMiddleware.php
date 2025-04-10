<?php
// Declaramos el namespace según tu estructura
namespace App\Middleware;

// Importamos las clases necesarias
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use PDO;

class JwtMiddleware
{
    // Método principal que se ejecuta cuando se usa el middleware
    public function __invoke(Request $request, Handler $handler): Response
    {
        // Obtenemos el encabezado Authorization de la solicitud
        $authHeader = $request->getHeaderLine('Authorization');

        // Verificamos que el encabezado Authorization exista y comience con "Bearer "
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse("Token no proporcionado o formato incorrecto");
        }

        // Extraemos el token eliminando "Bearer " del encabezado
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            // Decodificamos el token con la clave secreta y el algoritmo HS256
            $decoded = JWT::decode($token, new Key("mi_clave_secreta", 'HS256'));

            // Obtenemos el ID del usuario del payload del token
            $usuarioId = $decoded->usuario_id;

            // Conectamos a la base de datos
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Buscamos el token y la fecha de vencimiento del usuario en la base de datos
            $stmt = $pdo->prepare("SELECT token, vencimiento_token FROM usuario WHERE id = ?");
            $stmt->execute([$usuarioId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Validamos que:
            // 1. El usuario exista
            // 2. El token coincida con el que está guardado
            // 3. El token no esté vencido
            if (
                !$user ||
                $user['token'] !== $token ||
                (isset($user['vencimiento_token']) && strtotime($user['vencimiento_token']) < time())
            ) {
                return $this->unauthorizedResponse("Token inválido o vencido");
            }

            // Si todo está bien, inyectamos el usuario_id en la request
            $request = $request->withAttribute('usuario_id', $usuarioId);

            // Continuamos con el siguiente middleware o la ruta
            return $handler->handle($request);

        } catch (\Exception $e) {
            // Si ocurre algún error al decodificar el token, devolvemos error 401
            return $this->unauthorizedResponse("Token inválido: " . $e->getMessage());
        }
    }

    // Función auxiliar para generar respuestas 401 (no autorizado)
    private function unauthorizedResponse($mensaje): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => $mensaje]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
}
