<?php
// Definimos el namespace para que funcione el autoload con Composer y se eviten conflictos de nombres
namespace App\Services;

// Importamos las clases PDO y PDOException que usaremos para la conexión a la base de datos
use PDO;
use PDOException;

// Declaramos la clase Database que manejará la conexión a la base de datos
class Database {

    // Propiedad estática privada para almacenar una única instancia de PDO (conexión)
    private static $pdo = null;

    // Método público y estático para obtener la instancia de conexión PDO
    public static function getConnection() {

        // Verificamos si todavía no se creó una conexión
        if (self::$pdo === null) {

            // Incluimos el archivo de configuración y capturamos el array devuelto
            $config = require __DIR__ . '/../../config/db_config.php';


            // Intentamos crear una nueva instancia de PDO usando los datos del archivo de configuración
            try {
                self::$pdo = new PDO(
                    "mysql:host={$config['host']};dbname={$config['dbname']}", // Cadena de conexión
                    $config['username'], // Usuario de la base de datos
                    $config['password']  // Contraseña de la base de datos
                );

                // Configuramos PDO para que lance excepciones en caso de errores
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            } catch (PDOException $e) {
                // Si ocurre un error al conectarse, detenemos el script y mostramos el mensaje
                die("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }

        // Retornamos la instancia PDO ya establecida (o recién creada si no existía)
        return self::$pdo;
    }
}
