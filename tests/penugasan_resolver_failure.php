<?php
// Synthetic driver failure: no real database connection is opened.
require dirname(__DIR__) . '/app/bootstrap.php';
class FalseResultStatement extends mysqli_stmt {
 public function __construct() {}
 public function bind_param(string $types, mixed &...$vars): bool {return true;}
 public function execute(?array $params = null): bool {return true;}
 public function get_result(): mysqli_result|false {return false;}
 public function close(): true {return true;}
}
class FalseResultConnection extends mysqli {
 public function __construct() {}
 public function prepare(string $query): mysqli_stmt|false {return new FalseResultStatement();}
}
$c=new App\Auth\Capabilities(new FalseResultConnection());
foreach (['activeScopes'=>[1,'murobi'],'rolesFromDatabase'=>[1],'kelasJenjang'=>[1],'tahunAjaranId'=>['2026/2027','Ganjil'],'scalar'=>['SELECT 1',1]] as $method=>$args) {
 $r=(new ReflectionMethod($c,$method))->invoke($c,...$args);
 if($r!==null&&$r!==[])throw new RuntimeException('Must fail closed');
 echo "[lulus] Resolver $method: get_result false tidak fatal dan tidak memberi hak\n";
}
