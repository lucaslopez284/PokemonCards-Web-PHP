<?php
// Incluyo la clase DB que me da la conexión a la base de datos
require_once __DIR__ . '/../../config/DB.php';

// -----------------------------------------------------------------------------
// ENDPOINT: GET /estadisticas
// -----------------------------------------------------------------------------
// Este endpoint permite obtener estadísticas generales de las partidas jugadas
// por todos los usuarios registrados en el sistema.
// -----------------------------------------------------------------------------


// Defino la función que registra la ruta GET /estadisticas
function estadisticas($app) {

    $app->get('/estadisticas', function ($request, $response) {
        try {
            // Obtengo la conexión a la base de datos
            $pdo = DB::getConnection();
    
            // Consulta para traer todos los usuarios y las estadísticas de sus partidas
            $sql = "SELECT u.usuario, p.el_usuario
                    FROM usuario u
                    LEFT JOIN partida p ON u.id = p.usuario_id AND p.estado = 'finalizada'";
    
            // Ejecutamos la consulta
            $stmt = $pdo->query($sql);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            // Array para almacenar las estadísticas por usuario
            $estadisticas = [];
    
            // Inicializamos contadores globales
            $totalGanadas = 0;
            $totalPerdidas = 0;
            $totalEmpatadas = 0;
    
            // Procesamos los resultados uno por uno
            foreach ($resultados as $fila) {
                $usuario = $fila['usuario'];
                $estado = $fila['el_usuario']; // Puede ser: gano, perdio, empato o NULL (sin partida)
    
                // Inicializamos si es la primera vez que vemos este usuario
                if (!isset($estadisticas[$usuario])) {
                    $estadisticas[$usuario] = [
                        'usuario' => $usuario,
                        'ganadas' => 0,
                        'perdidas' => 0,
                        'empatadas' => 0
                    ];
                }
    
                // Si el usuario ha jugado una partida (estado no es NULL)
                if ($estado !== null) {
                    // Sumamos según el resultado de la partida
                    switch ($estado) {
                        case 'gano':
                            $estadisticas[$usuario]['ganadas']++;
                            $totalGanadas++;
                            break;
                        case 'perdio':
                            $estadisticas[$usuario]['perdidas']++;
                            $totalPerdidas++;
                            break;
                        case 'empato':
                            $estadisticas[$usuario]['empatadas']++;
                            $totalEmpatadas++;
                            break;
                    }
                }
            }
    
            // Convertimos el array asociativo a numérico para que sea un JSON plano
            $estadisticasPorUsuario = array_values($estadisticas);
    
            // Añadimos los totales globales al final del array
            $estadisticasPorUsuario[] = [
                'total_partidas' => [
                    'ganadas' => $totalGanadas,
                    'perdidas' => $totalPerdidas,
                    'empatadas' => $totalEmpatadas
                ]
            ];
    
            // Devolvemos el resultado en formato JSON
            $response->getBody()->write(json_encode($estadisticasPorUsuario));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    
        } catch (PDOException $e) {
            // En caso de error con la base de datos
            $response->getBody()->write(json_encode(['error' => 'Error al obtener estadísticas: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    });
    
}