<?php
#  MySQL connection class returning iterator resultset objects
#  From: http://www.w3style.co.uk/a-mysql-classiterator-for-php5
class MySQLdb
{
	private $conn;
	private $db;
	private static $instance = null;
	public function __construct($host, $user, $pass, $db=false) {
		$this->connect($host, $user, $pass);

		if ($this->conn && $db) {
			$this->selectDb($db);
		}
	}
	public static function getInstance($host, $user, $pass, $db) {
		if (self::$instance === null) {
			self::$instance = new DB($host, $user, $pass, $db);
		}
		return self::$instance;
	}
	public function connect($host, $user, $pass) {
		$this->conn = @mysql_connect($host, $user, $pass);
	}
	public function selectDb($db) {
		@mysql_select_db($db, $this->conn);
		$this->db = $db;
	}	
	public function getDbName() {
		return $this->db;
	}
	public function isConnected() {
		return is_resource($this->conn);
	}
	public function disconnect() {
		@mysql_close($this->conn);
	}
	public function getError() {
#		return mysql_error($this->conn);
		return mysql_error();
	}	
	public function query($query) {
		#  Eliminate \n's and \t's  8-2009 D. Ison
		$query = str_replace  ("\n", '', $query);  
		$query = str_replace  ("\t", '', $query);  

		$result = mysql_query($query);
		$error = $this->getError();

		if (strlen ($error) > 0) {
			error_log ("MySQL server error: [$error] for [$query] ");
		}
		$insert = false;
		#  Added 8-2009 D. Ison
		$query_reduced = trim (strtolower ($query));
		$insert = (strpos ($query_reduced, 'insert') === 0 || strpos ($query_reduced, 'update') === 0) ? true : false;		
/*
		if (strpos ($query_reduced, 'insert') === 0 || strpos ($query_reduced, 'update') === 0) {
			$insert = true;		
		}
*/		
/*		if (strpos(trim(strtolower($query)), 'insert') === 0) { 
			$insert = true;
		} elseif  (strpos(trim(strtolower($query)), 'update') === 0) {
			$insert = true;
		}
*/
		return new MySQLdb_Result($result, $this->conn, $insert);
	}
	public function info() {
		return mysql_get_server_info($this->conn);
	}
	public function status() {
		$retval = explode ('  ', mysql_stat($this->conn));
		return $retval;
	}
	public function escape($string) {
		return mysql_real_escape_string($string, $this->conn);
	}
}

/**
 * DB_Result class.  Provides an iterator wrapper
 * for working with a MySQL result.
 * @author d11wtq
 */
 
function myfunction($value,$key) {
	echo "The key $key has the value $value<br />";
}

 
class MySQLdb_Result
{
        private $id;  			#  ID resulting from row insertion
        private $length = 0;	#  Resultset size
        private $result;
        private $currentRow 	= array();
        private $position 		= 0;
        private $lastPosition 	= 0;
        private $gotResult 		= false;
        private $affectedRows 	= -1;
       
        public function __construct (&$result, &$conn, $insert=false) {
                $this->result 	= $result;
                $this->conn 	= $conn;

/*
   				#  Locals 8-2009 D. Ison
   				$num_rows = 0;
   				if (isset ($this->result)) {
   					$num_rows = @mysql_num_rows($this->result); 				
   				}  

*/
#                if ((@mysql_num_rows($this->result) >= 0 && $this->result !== false) || $insert)
                if ($insert || ($this->result !== false && @mysql_num_rows($this->result) >= 0)) {
#                if ($insert || ($this->result !== false && $num_rows >= 0)) {
                        if ($insert) {
                        	$this->id = mysql_insert_id($conn);
                        	$this->length = 0;
                        } else {
                        	$this->length = (int) @mysql_num_rows($this->result);
                       	}
                        $this->affectedRows = mysql_affected_rows($conn);
                }
        }
 
        public function __get($field) {
                if ($this->lastPosition != $this->position || !$this->gotResult) {
                        mysql_data_seek($this->result, $this->position);
                        $this->currentRow = mysql_fetch_assoc($this->result);
                        $this->lastPosition = $this->position;
                        $this->gotResult = true;
                }
#    array_walk($this->currentRow,"myfunction");
            

                return $this->currentRow[$field];
        }
        public function id() {
                return $this->id;
        }
        public function length() {
                return $this->length;
        }
        public function first() {
                if ($this->length > 0) {
                        $this->gotorec(0);
                        return true;
                }
                else return false;
        }
        public function last() {
                return $this->gotorec($this->length-1);
        }
        public function end() {
                if ($this->position >= $this->length) return true;
                else return false;
       	}
        public function start() {
                return ($this->position < 0);
       	}
        public function next() {
                return $this->gotorec($this->position+1);
        }
        public function prev() {
                return $this->gotorec($this->position-1);
        }
        public function gotorec($position) {
                if ($position < -1 || $position > $this->length) return false;
                else {
                        $this->position = $position;
                        return true;
                }
        }
        public function affectedRows() {
                return $this->affectedRows;
        }
        public function &get() {
                return $this->result;
        }
        public function position() {
                return $this->position;
        }
}
?>