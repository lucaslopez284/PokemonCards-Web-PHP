<?php
// Importamos los controladores con las funciones de las rutas
require_once __DIR__ . '/../controllers/userController.php';
require_once __DIR__ . '/../controllers/mazo.php';

/**
 * Retorna una función que toma la app Slim y registra todas las rutas
 */
return function ($app) {
    // Ruta POST /login
    login($app);

    // Ruta POST /registro
    registro($app);

    // Rutas para mazos (POST, GET, PUT, DELETE, etc.)
    mazo($app);
};
