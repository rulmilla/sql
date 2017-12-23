<?php
	/*
	 * Esta clase es para trabajar con una base de datos mysql
	 * v 0.3
	 */
	class db {
		var $errores = array();			// Array de errores
		var $num_rows = null;			// Numero de filas devueltas
		var $rows_affected = null;		// Numero de filas devueltas
		var $insert_id = null;			// id del ultimo INSERT
		var $result = null;				// Resultado de la ultima sentencia SQL
		var $tabla = null;				// nombre de la tabla
		var $tipo = null;				// Tipo de sentencia: SELECT, INSERT, UPDATE, DELETE
		var $query = array();			// Sentencia construida
		var $prefijo = null;			// Prefijo de las tablas
		var $campos = array();			// Campos a tratar
		var $reaprovechar_ids = true;	// Si hay id's vacios, que los reaproveche
		var $link = null;				// Link de la bd
		
		function __construct() {
			$uri = substr($_SERVER['SCRIPT_FILENAME'], 0, strrpos($_SERVER['SCRIPT_FILENAME'], '/'));

			$f = 'dbconfig.php';

			if(file_exists("$uri/$f")) $r = "$uri/$f";
			elseif(file_exists("$uri/includes/$f")) $r = "$uri/includes/$f";
			elseif(file_exists("$uri/../includes/$f")) $r = "$uri/../includes/$f"
			else {
				$this->errores[] = "No se encuentra el fichero \"$f\".";
				exit;
				die($r);
			}
			
			if(!is_readable($r)) {
				$this->errores[] = 'El fichero "'.$r.'" existe pero no tiene permisos de lectura.';
				exit;
			} else {
				include($r);
			}			
		}

		function connect() {
			// Funcion para conectarse al SGBD
			$this->errores = array();

			// Estos campos son obligatorios.
			// Compruebo que estén definidos todos.
			if(!$this->dbhost) $this->errores[] = 'No se ha especificado direccion del servidor.';
			if(!$this->dbuser) $this->errores[] = 'No se ha especificado Usuario para MySQL';
			if(!$this->dbname) $this->errores[] = 'No se ha especificado Base de datos';
			
			// Si falta algún dato, muestro el error y detengo el proceso.
			if(!$this->dbhost || !$this->dbuser) {
				pr($this->errores);
				die;
			}
			
			// Conecto al servidor de MySQL
			$this->link = mysqli_connect($this->dbhost, $this->dbuser, $this->dbpass);
			if(!$this->link) $this->errores[] = 'Error conectando a MySQL ('.$this->dbhost.'). Revisa el nombre del servidor, usuario y clave por favor.';
			// Conecto a la Base de Datos
			if(!mysqli_select_db($this->link, $this->dbname)) $this->errores[] = 'Error conectando a la base de datos ('.$this->dbname.'). Revisa el nombre de la base de datos por favor.';
		}

		function query($query = null, $array = false) {
			// Función para ejecutar una sentencia SQL.
			// Hay que ajustar los resultados cuando query es un array

			if(!$query) $query = $this->query;
			$this->connect();

			// Entramos en modo seguro			
			mysqli_query($this->link, "START TRANSACTION");

			// Por defecto no hay resultados
			$this->num_rows = null;
			$this->rows_affected = null;

			//~ Con este codigo controlo reaprovechar ids menores que el maximo para reaprovechar espacio
			$insert = false;
			if($this->reaprovechar_ids && substr($query, 0, 6) == 'INSERT') {
				$insert = true;
				
				//~ Cojo el nombre de la tabla para recoger los resultados
				$sql = explode(" ", $query);
				$tabla = $sql[2];
				$qs = mysqli_query($this->link, "SELECT id FROM $tabla ORDER BY id DESC");
				$r = mysqli_fetch_assoc($qs);
				
				$ids = array();
				if($r['id'] > mysqli_num_rows($qs)) {
					//~ Meto todos los ids en un array para ver si existen
					do array_push($ids, $r['id']); while($r = mysqli_fetch_assoc($qs));					
					
					//~ Desde el primero, compruebo si el id existe
					for($i = 1; $i <= mysqli_num_rows($qs); $i++) {
						if(!in_array($i, $ids)) {
							//~ Si encuentra un hueco selecciona el id y sale
							$elegido = $i;
							break;
						}
					}
				}
				
				//~ Si hay un id vacio, modifica la sentencia sql para añadir el campo "id" con el id a utilizar
				if(isset($elegido)) {
					$nsql = explode(" (", $query);
					$nsql[1] = "id, ".$nsql[1];
					$nsql[2] = "$elegido, ".$nsql[2];
					$query = implode(" (", $nsql);	
				}
				//~ Si no ha encontrado ningun id disponible, hace un insert normal para recibir el id del autoincrement
			}
			 
			// Ejecuta la sentencia
			$result = mysqli_query($this->link, $query);

			$this->rows_affected = mysqli_affected_rows($this->link);

			if($insert)
				$this->insert_id = mysqli_insert_id($this->link);

			// Si nos deuelve un error...
			if(mysqli_errno($this->link)) {
				// ... mostramos el error por pantalla
				echo "[".$query."].<br />\n";
				printf("<br />\n<br />\nError: %s", mysqli_error($this->link));
				// Y deshacemos la consulta SQL
				mysqli_query($this->link, "ROLLBACK");
				die;
			} elseif($result) {
				
				// Si no nos dá error devolvemos el resultado
				// Apuntamos las filas devueltas
				if(substr($query, 0, 6) == 'SELECT') {
					$this->num_rows = mysqli_num_rows($result);
					if($array) {
						// Devolvemos el resultado en formato de array
						$resultado = array();
						while($row = mysqli_fetch_assoc($result)) {
							$tmp = array();
							foreach($row as $key => $value) {
								$tmp[$key] = $value;
							}
							$resultado[] = $tmp;
						}
						$this->result = $resultado;
						return $resultado;
					} else {
						// Devolvemos el resultado en bruto
						//~ $r = mysqli_fetch_assoc($result);
						$this->result = $result;
						return $result;
					}
				} else $this->num_rows = null;				
			}
			mysqli_query($this->link, "COMMIT");
		}

		function addCampo($nombre, $valor, $default = null) {
			$this->campos[$nombre] = $valor;
			if($valor != "") $this->campos[$nombre] = $default;
		}
		
		function delCampo($nombre) {
			unset($this->campos[$nombre]);
		}
		
		function construye() {
			if($this->prefijo) $t = sprintf("%s_%s", $this->prefijo, $this->tabla);
			else $t = $this->tabla;
			
			$coma = "";
			
			switch($this->tipo) {
				case 'SELECT':
				break;
				case 'INSERT':
					$campos = $valores = null;
					foreach($this->campos as $key => $value) {
						$campos .= $coma.$key;
						if(substr($value, -2) != '()') $valores .= sprintf("%s'%s'", $coma, $value);
						else $valores .= $coma."$value";
						$coma = ', ';
					}
					$this->query = sprintf("INSERT INTO %s (%s) VALUES (%s) LIMIT 1;", $t, $campos, $valores);
				break;
				case 'UPDATE':
					$cambios = null;
					foreach($this->campos as $key => $value) {
						$cambios .= sprintf("%s%s = ''", $coma, $key, $value);
						$coma = ', ';
					}
					$this->query = sprintf("UPDATE %s SET %s LIMIT 1;", $t, $cambios);
				break;
				case 'DELETE':
					//~ $this->query = sprintf("DELETE FROM %s WHERE %s LIMIT 1;", $t, $registro);
				break;
			}

		}
		
		function ejecuta() {
			$this->construye();
			$this->query();
		}
	}	
?>
