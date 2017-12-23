Funcionaes para conectar y tabajar con una base de datos:

Ejemplo:
<?php

include("db.php");
$db = new db;
$resultado = $db->query("SELECT * FROM usuarios;");
print_r($resultado);
?>
