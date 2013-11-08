<?php

/*
__PocketMine Plugin__
name=PeacefulSpawn
description=Players can't harm eachother at spawn
version=1.0
author=wies
class=PeacefulSpawn
apiversion=10
*/


class PeacefulSpawn implements Plugin{
	private $api;
	private $server;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->server = ServerAPI::request();
	}
	
	public function init(){
	$this->api->addHandler("player.interact", array($this, "playerhit"));
	}
	
	public function playerhit($data){
		$target = $data["target"];
		$t = new Vector2($target->x, $target->z);
		$s = new Vector2($this->server->spawn->x, $this->server->spawn->z);
		if($t->distance($s) <= $this->server->api->getProperty("spawn-protection")){
			return false;
		}
	}
	
	public function __destruct(){}

}
?>