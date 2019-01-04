<?php

namespace macropage\MySQLiHelper;

use mysqli_sql_exception;

class MySQLiBase {


	private static $string_quoting     = ['start' => "'", 'end' => "'", 'escape' => false, 'escape_pattern' => false];
	private static $identifier_quoting = ['start' => '"', 'end' => '"', 'escape' => '"'];
	private static $sql_comments       = [
		['start' => '--', 'end' => "\n", 'escape' => false],
		['start' => '/*', 'end' => '*/', 'escape' => false],
	];
	private static $options            = [
		'bindname_format' => '(?:\d+)|(?:[a-zA-Z][a-zA-Z0-9_]*)',
	];
	/**
	 * @var \mysqli
	 */
	private $link;
	private $connectionParams;
	private $autoReconnect       = true;
	private $autoReconnectMaxTry = 5;
	private $autoReconnectCount  = 0;
	private $autoReconnectSleep  = 3;
	private $traceEnabled        = true;
	private $lowerTableFields    = true;

	public function __construct($connectionParams) {
		if (!array_key_exists('port', $connectionParams)) {
			$connectionParams['port'] = 3306;
		}
		if (!array_key_exists('charset', $connectionParams)) {
			$connectionParams['charset'] = 'utf8';
		}
		if (!array_key_exists('socket', $connectionParams)) {
			$connectionParams['socket'] = null;
		}
		if (array_key_exists('trace', $connectionParams) && !$connectionParams['trace']) {
			$this->setTraceEnabled(false);
		}
		$this->connectionParams = $connectionParams;
	}

	/**
	 * @param bool $traceEnabled
	 */
	public function setTraceEnabled($traceEnabled) {
		$this->traceEnabled = $traceEnabled;
	}

	public function begin() {
		$this->connectIfNotConnected();
		$this->link->begin_transaction();
	}

	public function commit() {
		$this->link->commit();
	}

	public function rollback() {
		$this->link->rollback();
	}

	private function connectIfNotConnected() {
		if (!$this->link) {
			$conntect_status = $this->connect();
			if (!$conntect_status->state) {
				return $conntect_status;
			}
		}
		return true;
	}

	/**
	 * @param       $sql
	 * @param array $params
	 *
	 * @return MySQLiResponse
	 */
	public function query($sql, array $params = []) {

		$ResponseObj = new MySQLiResponse();

		$traceStart = 0;

		if ($this->traceEnabled) {
			$traceStart = microtime(true);
		}

		// Lazy Connection
		$lazyConStatus = $this->connectIfNotConnected();
		if ($lazyConStatus!==true) {
			return $lazyConStatus;
		}


		$ResponseObj->sql           = $sql;
		$ResponseObj->sql_original  = $sql;
		$ResponseObj->state         = false;
		$ResponseObj->error         = null;
		$ResponseObj->errorno       = null;
		$ResponseObj->result        = [];
		$ResponseObj->affected_rows = 0;
		$ResponseObj->numrows       = 0;
		$paramsWithTypes            = [];
		$bind_names                 = [];

		if (count($params)) {
			$parsedResult = $this->get_placeholders($sql);
			$sql              = $parsedResult['query'];
			$ResponseObj->sql = $sql;
			// replace named parameters
			if (array_key_exists('positions', $parsedResult) && count($parsedResult['positions'])) {

				foreach ($parsedResult['positions'] as $position => $value) {
					if (array_key_exists($position, $params)) {
						$paramType                  = self::getParamType($params[$position]);
						$paramsWithTypes[$position] = ['value' => $params[$position], 'paramtype' => $paramType];
					}
					if (array_key_exists($value, $params)) {
						$paramType                  = self::getParamType($params[$value]);
						$paramsWithTypes[$position] = ['value' => $params[$value], 'paramtype' => $paramType];
					}
				}
			}
			if (\count($paramsWithTypes)) {
				$ResponseObj->params = count($paramsWithTypes) ? $paramsWithTypes : $params;
				$bind_names[]        = implode('', array_column($paramsWithTypes, 'paramtype'));
				//print_r($bind_names);
				foreach ($paramsWithTypes as $i => $paramtype) {
					$bind_name    = 'bind' . $i;
					$$bind_name   = $paramtype['value'];
					$bind_names[] = &$$bind_name;
				}
			}
		}

		try {
			$stmt = $this->link->prepare($sql);
		} catch (mysqli_sql_exception  $e) {
			$stmt = false;
		} catch (\Exception $e) {
			$stmt = false;
		}

		// Reconnect and redo prepare
		if (!$stmt && $this->autoReconnect && $this->checkMysqlHasGoneAway($this->link->error)) {
			$conntect_status = $this->connect();
			if (!$conntect_status->state) {
				return $conntect_status;
			}
			$stmt = $this->link->prepare($sql);
		}

		if (!$stmt) {

			$ResponseObj->error    = mysqli_error($this->link);
			$ResponseObj->errorno  = mysqli_errno($this->link);
			$ResponseObj->duration = $this->traceEnabled ? (microtime(true) - $traceStart) : 0;

			return $ResponseObj;
		}

		if (count($paramsWithTypes)) {
			\call_user_func_array([$stmt, 'bind_param'], $bind_names);
		}


		if (!$stmt->execute()) {
			$ResponseObj->error   = $stmt->error;
			$ResponseObj->errorno = $stmt->errno;
		} else {

			$ResponseObj->state          = true;
			$ResponseObj->last_insert_id = $this->link->insert_id;
			$ResponseObj->numrows        = 0;
			$ResponseObj->affected_rows  = ($stmt->affected_rows > 0) ? $stmt->affected_rows : 0;

			$meta   = $stmt->result_metadata();

			$fields = [];

			if ($meta) {

				$dupcounter = [];

				while ($field = $meta->fetch_field()) {
					$var          = $field->name;
					$$var         = null;
					if (array_key_exists($field->name,$dupcounter)) {
						$var = $var.$dupcounter[$field->name];
					}
					$fields[$var] = &$$var;
					if (!array_key_exists($field->name,$dupcounter)) {
						$dupcounter[$field->name]=0;
					}
					$dupcounter[$field->name]++;
				}

				\call_user_func_array([$stmt, 'bind_result'], $fields);

				$i = 0;
				while ($stmt->fetch()) {
					$ResponseObj->numrows++;
					$ResponseObj->result[$i] = [];
					foreach ($fields as $k => $v) {
						if ($this->lowerTableFields) {
							$k = strtolower($k);
						}
						$ResponseObj->result[$i][$k] = $v;
					}
					$i++;
				}
			} else {
				/**
				 * @see https://secure.php.net/manual/en/mysqli-stmt.result-metadata.php#97338
				 */
				preg_match('/^\s?(insert|update|delete|alter|drop|rename|modify|truncate)\s/i', $sql, $matches, PREG_OFFSET_CAPTURE, 0);
				if (!count($matches)) {
					$ResponseObj->state = false;
					$ResponseObj->error = 'result_metadata returned false';
				}
			}

			$stmt->free_result();
			$stmt->close();
		}

		$ResponseObj->duration       = $this->traceEnabled ? (microtime(true) - $traceStart) : 0;

		return $ResponseObj;
	}

	public function checkMysqlHasGoneAway($message) {
		$mysql_messages = [
			'server has gone away',
			'no connection to the server',
			'Lost connection',
			'is dead or not enabled',
			'Error while sending',
			'decryption failed or bad record mac',
			'SSL connection has been closed unexpectedly',
		];
		foreach ($mysql_messages as $mysql_message) {
			if (stripos($message,$mysql_message)!==false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return MySQLiResponse
	 */
	public function connect() {
		$this->link = new \mysqli(
			$this->connectionParams['host'],
			$this->connectionParams['user'],
			$this->connectionParams['pwd'],
			$this->connectionParams['db'],
			$this->connectionParams['port'],
			$this->connectionParams['socket']
		);

		$ResponseObj = new MySQLiResponse();

		if ($this->link->connect_errno) {

			if (
				($this->checkMysqlHasGoneAway($this->link->error) || $this->link->connect_errno === 2002)
				&&
				$this->autoReconnect === true
			) {
				while ($this->autoReconnectCount <= $this->autoReconnectMaxTry) {

//					if ($this->autoReconnectCount > 2) {
//						$this->connectionParams['host'] = 'mysql';
//					}

					$this->link = new \mysqli(
						$this->connectionParams['host'],
						$this->connectionParams['user'],
						$this->connectionParams['pwd'],
						$this->connectionParams['db'],
						$this->connectionParams['port'],
						$this->connectionParams['socket']
					);
					$this->autoReconnectCount++;

					// echo 'Warte ' . $this->autoReconnectSleep . ' sekunden dann probieren wir es nochmal....' . "\n";
					sleep($this->autoReconnectSleep);
				}
			}

			if ($this->link->connect_errno) {

				$ResponseObj->state   = false;
				$ResponseObj->errorno = $this->link->connect_errno;
				$ResponseObj->error   = $this->link->connect_error;

				return $ResponseObj;

			}
		}

		$this->autoReconnectCount = 0;

		$this->link->set_charset($this->connectionParams['charset']);

		$ResponseObj->state       = true;
		$ResponseObj->server_info = [
			'server_info'    => $this->link->server_info,
			'server_version' => $this->link->server_version,
			'stat'           => $this->link->stat(),
			'host_info'      => $this->link->host_info
		];

		return $ResponseObj;
	}

	public function get_placeholders($query) {
		$placeholder_type_guess = $placeholder_type = null;
		$question               = '?';
		$colon                  = ':';
		$positions              = [];
		$position               = 0;
		while ($position < strlen($query)) {
			$q_position = strpos($query, $question, $position);
			$c_position = strpos($query, $colon, $position);
			if ($q_position && $c_position) {
				$p_position = min($q_position, $c_position);
			} elseif ($q_position) {
				$p_position = $q_position;
			} elseif ($c_position) {
				$p_position = $c_position;
			} else {
				break;
			}
			if (null === $placeholder_type) {
				$placeholder_type_guess = $query[$p_position];
			}

			$new_pos_data = $this->_skipDelimitedStrings($query, $position, $p_position);
			if (!$new_pos_data['state']) {
				return $new_pos_data;
			}
			$new_pos = $new_pos_data['pos'];
			if ($new_pos !== $position) {
				$position = $new_pos;
				continue; //evaluate again starting from the new position
			}

			//make sure this is not part of an user defined variable
			$new_pos = $this->_skipUserDefinedVariable($query, $position);
			if ($new_pos !== $position) {
				$position = $new_pos;
				continue; //evaluate again starting from the new position
			}

			if ($query[$position] === $placeholder_type_guess) {
				if (null === $placeholder_type) {
					$placeholder_type = $query[$p_position];
					$question         = $colon = $placeholder_type;
				}
				if ($placeholder_type === ':') {
					$regexp    = '/^.{' . ($position + 1) . '}(' . self::$options['bindname_format'] . ').*$/s';
					$parameter = preg_replace($regexp, '\\1', $query);
					if ($parameter === '') {
						return ['state' => false, 'error' => 'named parameter name must match "bindname_format" option', __FUNCTION__];
					}
					$positions[$p_position] = $parameter;
					$query                  = substr_replace($query, '?', $position, strlen($parameter) + 1);
				} else {
					$positions[$p_position] = count($positions);
				}
				$position = $p_position + 1;
			} else {
				$position = $p_position;
			}
		}

		return ['state' => true, 'query' => $query, 'positions' => $positions];
	}

	/**
	 * Utility method, used by prepare() to avoid replacing placeholders within delimited strings.
	 * Check if the placeholder is contained within a delimited string.
	 * If so, skip it and advance the position, otherwise return the current position,
	 * which is valid
	 *
	 * @param string  $query
	 * @param integer $position   current string cursor position
	 * @param integer $p_position placeholder position
	 *
	 * @return mixed integer $new_position on success
	 *               MDB2_Error on failure
	 *
	 * @access  protected
	 */
	private function _skipDelimitedStrings($query, $position, $p_position) {
		$ignores   = [];
		$ignores[] = self::$string_quoting;
		$ignores[] = self::$identifier_quoting;
		$ignores   = array_merge($ignores, self::$sql_comments);

		foreach ($ignores as $ignore) {
			if (!empty($ignore['start']) && is_int($start_quote = strpos($query, $ignore['start'], $position)) && $start_quote < $p_position) {
				$end_quote = $start_quote;
				do {
					if (!is_int($end_quote = strpos($query, $ignore['end'], $end_quote + 1))) {
						if ($ignore['end'] === "\n") {
							$end_quote = strlen($query) - 1;
						} else {
							return ['state' => false, 'error' => 'query with an unterminated text string specified', __FUNCTION__];
						}
					}
				} while ($ignore['escape']
						 && $end_quote - 1 !== $start_quote
						 && $query[$end_quote - 1] === $ignore['escape']
						 && ($ignore['escape_pattern'] !== $ignore['escape']
							 || $query[$end_quote - 2] !== $ignore['escape'])
				);

				$position = $end_quote + 1;

				return ['state' => true, 'pos' => $position];
			}
		}

		return ['state' => true, 'pos' => $position];
	}

	/**
	 * Utility method, used by prepare() to avoid misinterpreting MySQL user
	 * defined variables (SELECT @x:=5) for placeholders.
	 * Check if the placeholder is a false positive, i.e. if it is an user defined
	 * variable instead. If so, skip it and advance the position, otherwise
	 * return the current position, which is valid
	 *
	 * @param string  $query
	 * @param integer $position current string cursor position
	 *
	 * @return integer $new_position
	 * @access protected
	 */
	private function _skipUserDefinedVariable($query, $position) {
		$found = strpos(strrev(substr($query, 0, $position)), '@');
		if (false === $found) {
			return $position;
		}
		$pos       = strlen($query) - strlen(substr($query, $position)) - $found - 1;
		$substring = substr($query, $pos, $position - $pos + 2);
		if (preg_match('/^@\w+\s*:=$/', $substring)) {
			return $position + 1; //found an user defined variable: skip it
		}

		return $position;
	}

	/**
	 * @param $value
	 *
	 * @return string
	 */
	public static function getParamType($value) {

		switch (gettype($value)) {
			case 'NULL':
			case 'string':
				return 's';
				break;
			case 'boolean':
			case 'integer':
				return 'i';
				break;
			case 'blob':
				return 'b';
				break;
			case 'double':
				return 'd';
				break;
		}

		return 's';
	}

	/**
	 *
	 */
	public function disconnect() {
		if ($this->link) {
			$this->link->close();
		}
	}

	/**
	 * @param bool $autoReconnect
	 */
	public function setAutoReconnect($autoReconnect) {
		$this->autoReconnect = $autoReconnect;
	}

	/**
	 * @param int $autoReconnectMaxTry
	 */
	public function setAutoReconnectMaxTry($autoReconnectMaxTry) {
		$this->autoReconnectMaxTry = $autoReconnectMaxTry;
	}

	/**
	 * @param int $autoReconnectSleep
	 */
	public function setAutoReconnectSleep($autoReconnectSleep) {
		$this->autoReconnectSleep = $autoReconnectSleep;
	}

	/**
	 * @param bool $lowerTableFields
	 */
	public function setLowerTableFields($lowerTableFields) {
		$this->lowerTableFields = $lowerTableFields;
	}

}