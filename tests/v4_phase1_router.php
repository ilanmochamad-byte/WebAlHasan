<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if(preg_match('#^/(?:app|bin|database|docs|storage|tests)(?:/|$)#i',$path)){http_response_code(403);exit;}
if(str_starts_with($path,'/api/v1/')){require dirname(__DIR__).'/api/v1/index.php';return true;}
return false;
