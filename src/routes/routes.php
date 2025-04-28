<?php
// Importo los controladores necesarios para registrar rutas específicas
require_once __DIR__ . '/../controllers/userController.php';           // Registro y login
require_once __DIR__ . '/../controllers/estadisticaController.php';   // Estadísticas globales
require_once __DIR__ . '/../controllers/mazoController.php';          // Funciones relacionadas a mazos
require_once __DIR__ . '/../controllers/partidaController.php';       // Funciones relacionadas a partidas
require_once __DIR__ . '/../controllers/jugadas/jugadaController.php'; // Funciones relacionadas a jugadas



// Importo el middleware JWT para proteger rutas
use App\middlewares\JwtMiddleware;  


/**
 * Esta función se llama desde index.php y se encarga de registrar todas las rutas que usa la app.
 * Le paso como parámetro la instancia de Slim\App para poder definir endpoints.
 * 
 * Ademas, me sirve como resumen de todos los endpoints que incluyo en el proyecto
 */
function routes($app) {
    // Rutas de autenticación
    login($app);            // Ruta POST /login
    registro($app);         // Ruta POST /registro

    // Rutas para obtener y editar usuario (protegidas con JWT)
    obtenerUsuario($app);   // Ruta GET /usuarios/{usuario}
    editarUsuario($app);    // Ruta PUT /usuarios/{usuario}

    // Rutas de partidas (protegidas con JWT)
    crearPartida($app);       // Ruta POST /partidas

    // Rutas de jugadas (protegidas con JWT)
    procesarJugada($app);   // Ruta POST /jugadas
    obtenerCartasEnMano($app);     // Ruta GET /usuarios/{usuario}/partidas/{partida}/cartas


    // Ruta GET /estadisticas (no requiere login)
    estadisticas($app);     // Ruta GET /estadisticas

    // Rutas de mazos (todas protegidas con JWT)
    crearMazo($app);        // Ruta POST /mazos
    eliminarMazo($app);     // Ruta DELETE /mazos/{mazo}
    listarMazos($app);      // Ruta GET /usuarios/{usuario}/mazos
    actualizarMazo($app);   // Ruta PUT /mazos/{mazo}
    listarCartas($app);     // Ruta GET /cartas
}
