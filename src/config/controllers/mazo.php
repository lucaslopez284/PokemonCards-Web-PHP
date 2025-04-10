<?php
//  Importamos las interfaces necesarias de Slim para manejar peticiones y respuestas HTTP
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

//  Importamos nuestro middleware JWT (para verificar autenticación)
use App\Middleware\JwtMiddleware;

//  No usamos la clase Database, se hace la conexión PDO directamente

// Función que registra todas las rutas relacionadas con mazos
function mazo(App $app) {

    //  Ruta: POST /mazos → Crea un nuevo mazo para un usuario autenticado
    $app->post('/mazos', function (Request $request, Response $response) {

        // Obtenemos el ID del usuario autenticado desde el atributo insertado por el middleware JWT
        $usuarioId = $request->getAttribute('usuario_id');

        // Leemos el cuerpo de la solicitud como string
        $body = $request->getBody()->getContents();
        // Convertimos el cuerpo en un arreglo asociativo (JSON → Array)
        $data = json_decode($body, true);

        // Validamos que el JSON tenga 'nombre' y 'cartas', y que 'cartas' sea un array
        if (!isset($data['nombre']) || !isset($data['cartas']) || !is_array($data['cartas'])) {
            $response->getBody()->write(json_encode(['error' => 'Faltan datos requeridos (nombre o cartas)']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
     
        // Guardamos en variables por comodidad
        $nombre = $data['nombre'];
        $cartas = $data['cartas'];

        // Validamos que haya entre 1 y 5 cartas y que no se repitan
        if (count($cartas) > 5 || count(array_unique($cartas)) !== count($cartas)) {
            $response->getBody()->write(json_encode(['error' => 'Debe haber entre 1 y 5 cartas distintas']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        ///
        try {
            //  Conectamos directamente a la base de datos con PDO
            $pdo = new PDO('mysql:host=localhost;dbname=basepokemon', 'root', '');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Verificamos que las cartas existan en la base de datos
            $placeholders = implode(',', array_fill(0, count($cartas), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM carta WHERE id IN ($placeholders)");
            $stmt->execute($cartas);
            if ($stmt->fetchColumn() != count($cartas)) {
                $response->getBody()->write(json_encode(['error' => 'Una o más cartas no existen']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Verificamos que el usuario tenga menos de 3 mazos
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mazo WHERE usuario_id = ?");
            $stmt->execute([$usuarioId]);
            if ($stmt->fetchColumn() >= 3) {
                $response->getBody()->write(json_encode(['error' => 'Límite de 3 mazos alcanzado']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Insertamos el nuevo mazo en la tabla mazo
            $stmt = $pdo->prepare("INSERT INTO mazo (usuario_id, nombre) VALUES (?, ?)");
            $stmt->execute([$usuarioId, $nombre]);
            $mazoId = $pdo->lastInsertId(); // Obtenemos el ID del nuevo mazo

            // Insertamos las cartas asociadas al mazo en la tabla mazo_carta
            $stmt = $pdo->prepare("INSERT INTO mazo_carta (mazo_id, carta_id, estado) VALUES (?, ?, 'en_mazo')");
            foreach ($cartas as $cartaId) {
                $stmt->execute([$mazoId, $cartaId]);
            }

            // Devolvemos una respuesta exitosa con el ID y nombre del mazo creado
            $response->getBody()->write(json_encode(['id' => $mazoId, 'nombre' => $nombre]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (PDOException $e) {
            // Si ocurre un error, devolvemos un mensaje descriptivo
            $response->getBody()->write(json_encode(['error' => 'Error al crear el mazo: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

    }) // 🔚 Cierre de la definición de la ruta
    ->add(new JwtMiddleware()); //  Protegemos esta ruta con autenticación JWT

} // Cierre de la función mazo()
