<?php

/*
__PocketMine Plugin__
name=PeacefulSpawn
description=Players can't harm eachother at spawn
version=1.0
author=wies
class=PeacefulSpawn
apiversion=10,11
*/

class PeacefulSpawn implements Plugin{
	private $api;
	private $server;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->server = ServerAPI::request();
	}
	
	public function init(){
		$this->api->addHandler('entity.health.change', array($this, 'entityHurt'));
	}
	
	public function entityHurt($data){
		$target = $data['entity'];
		$t = new Vector2($target->x, $target->z);
		$s = new Vector2($this->server->spawn->x, $this->server->spawn->z);
		if($t->distance($s) <= $this->api->getProperty('spawn-protection')){
			if(is_numeric($data['cause'])){
				$e = $this->api->entity->get($data['cause']);
				if(($e !== false) and ($e->class === ENTITY_PLAYER)){
					$e->player->sendChat('PvP is not allowed at the spawn');
				}
			}
			return false;
		}
	}
	
	public function __destruct(){}
}