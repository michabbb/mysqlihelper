<?php
namespace macropage\MySQLiHelper;


class MySQLiResponse {


	public $state;
	public $numrows;
	public $result = [];
	public $affected_rows;
	public $error;
	public $errorno;
	public $sql;
	public $sql_original;
	public $params;
	public $duration;
	public $last_insert_id;
	public $server_info = [];

}