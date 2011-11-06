<?php

include('MemcacheSASL.php');

$m = new MemcacheSASL;
$m->addServer('mc7.ec2.northscale.net', '11211');
$m->setSaslAuthData('username', 'password');
var_dump($m->add('test', '123'));
$m->delete('test');

