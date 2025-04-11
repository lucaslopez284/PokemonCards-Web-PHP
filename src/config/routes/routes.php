<?php
// Importo los controladores necesarios para registrar rutas específicas
require_once __DIR__ . '/../controllers/userController.php';           // Registro y login
require_once __DIR__ . '/../controllers/estadisticaController.php';   // Estadísticas globales
require_once __DIR__ . '/../controllers/mazoController.php';          // Funciones relacionadas a mazos

/**
 * Esta función se llama desde index.php y se encarga de registrar todas las rutas que usa la app.
 * Le paso como parámetro la instancia de Slim\App para poder definir endpoints.
 */
function routes($app) {
    // Rutas de autenticación
    login($app);
    registro($app);

    // Ruta GET /estadisticas (no requiere login)
    estadisticas($app);

    // Rutas de mazos (todas protegidas con JWT)
    mazo($app);         
}
