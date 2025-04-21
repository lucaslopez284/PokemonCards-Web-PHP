<?php
// Importo los controladores necesarios para registrar rutas específicas
require_once __DIR__ . '/../controllers/userController.php';           // Registro y login
require_once __DIR__ . '/../controllers/estadisticaController.php';   // Estadísticas globales
require_once __DIR__ . '/../controllers/mazoController.php';          // Funciones relacionadas a mazos

// Importo el middleware JWT para proteger rutas
use App\config\middlewares\JwtMiddleware;  


/**
 * Esta función se llama desde index.php y se encarga de registrar todas las rutas que usa la app.
 * Le paso como parámetro la instancia de Slim\App para poder definir endpoints.
 */
function routes($app) {
    // Rutas de autenticación
    login($app);            // Ruta POST /login
    registro($app);         // Ruta POST /registro

    // Rutas para obtener y editar usuario (protegidas con JWT)
    obtenerUsuario($app);   // Ruta GET /usuarios/{usuario}
    editarUsuario($app);    // Ruta PUT /usuarios/{usuario}

    // Ruta GET /estadisticas (no requiere login)
    estadisticas($app);     // Ruta GET /estadisticas

    // Rutas de mazos (todas protegidas con JWT)
    crearMazo($app);        // Ruta POST /mazos
    eliminarMazo($app);     // Ruta DELETE /mazos/{mazo}
    listarMazos($app);      // Ruta GET /usuarios/{usuario}/mazos
    actualizarMazo($app);   // Ruta PUT /mazos/{mazo}
    listarCartas($app);     // Ruta GET /cartas
}
