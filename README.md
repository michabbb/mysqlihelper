**Exmaple usage:**

```php
<?php
require 'vendor/autoload.php';

use macropage\MySQLiHelper\MySQLiBase;

$MySQLiHelper = new MySQLiBase(
	[
		'host'    => 'xxxxxxx',
		'port'    => 3306,
		'user'    => 'xxxxxx',
		'pwd'     => 'xxxxxx',
		'db'      => 'xxxxx',
	]
);

the defaults are:

* chareste: utf8
* port: 3306
* trace: true 

$newQuery = $MySQLiHelper->query('select * from table limit 0,10');
foreach ($newQuery->result as $data) {
	echo $data['table_field']."\n";
}
```

**The best:** this class supports regular **AND** _named parameters_ - thanks to PEAR:MDB2 where i got the code from :-)

so both works:

`$MySQLiHelper->query('select * from table where a=? and b=?',[1,1]);`  
  
**IS THE SAME LIKE**
  
`$MySQLiHelper->query('select * from table where a=:my_placeholder and b=:my_placehoöder',['my_placeholder' => 1]);`  
  
  
  
You can call `$MySQLiHelper->connect()` manually, but the class uses lazy connection, so as soon as the first query is done,
a connection to the mysql server will be established.

In case the connection gets lost or a first connection does not work (whyever), there is a default auto-reconnect of 5 tries
with a sleep of 3 seconds. you can change this with:

`$MySQLiHelper->setAutoReconnectMaxTry(x)`  
`$MySQLiHelper->setAutoReconnectSleep(x)`  
`$MySQLiHelper->setAutoReconnect(false)`  

