<?php

/*
__PocketMine Plugin__
name=AutoTree
description=Growing trees automaticly
version=1.0
author=wies
class=AutoTree
apiversion=9,10
*/
		
class AutoTree implements Plugin{
	private $api;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		$this->api->console->register("autotree", "Commands for auto tree growing", array($this, "command"));
		$this->path = $this->api->plugin->configPath($this);
		$this->config = new Config($this->path."config.yml", CONFIG_YAML, array(
			'Interval' => false,
			'TypeOfTree' => false,
			'Trees' => array()
		));
		$this->config = $this->api->plugin->readYAML($this->path . "config.yml");
		$this->api->addHandler("player.block.touch", array($this, "blockTouch"));
		if($this->config['Interval'] != false){
			$this->api->schedule(20 * $this->config['Interval'], array($this, "growTrees"), array(), true);
		}
		$this->select = array();
	}
	
	public function command($cmd, $args, $issuer){
		if($issuer === 'console'){
			$output = 'You must run this in-game';
			return $output;
		}
		$username = $issuer->username;
		$output = '';
		switch($args[0]){
			case 'grow':	$this->growTrees();
							break;
			case 'select':	$this->select[$username] = true;
							$output = '[AutoTree] You can start selecting the auto tree points with a wooden axe';
							break;
			case 'finish':	unset($this->select[$username]);
							$output = '[AutoTree] Finished the selection';
							break;
			default:		$output = '[AutoTree] Made by Wies';
							break;
		}
		return $output;
	}
	
	public function growTrees(){
		foreach((array)$this->config['Trees'] as $key => $val){
			$level = $this->api->level->get($val['level']);
			$x = $val['x'];
			$y = $val['y'];
			$z = $val['z'];
			$position = new Vector3($x, $y, $z);
			if($level->getBlock($position)->getID() !== 0) continue;
			if($level->getBlock(new Vector3($x, $y + 1, $z))->getID() !== 0) continue;
			if($level->getBlock(new Vector3($x, $y + 2, $z))->getID() !== 0) continue;
			if($this->config['TypeOfTree'] == false){
				$type = rand(0, 2);
			}else{
				$type = $val['type'];
			}
			TreeObject::growTree($level, $position, new Random(), $type);
		}
		$this->api->chat->broadcast('[AutoTree] Trees are regenerated!');
	}
	
	public function blockTouch($data){
		if($data['item']->getID() === 271){
			if(isset($this->select[$data['player']->username])){
				if($this->config['TypeOfTree'] == false){
					$type = rand(0, 2);
				}else{
					$type = $this->config['TypeOfTree'];
				}
				$tree = array(
					'x' => $data['target']->x,
					'y' => $data['target']->y + 1,
					'z' => $data['target']->z,
					'level' => $data['player']->level->getName(),
					'type' => $type
				);
				array_push($this->config['Trees'], $tree);
				$this->api->plugin->writeYAML($this->path . "config.yml", $this->config);
				$data['player']->sendChat('[AutoTree] You set a autotree on position ('.$tree['x'].','.$tree['y'].','.$tree['z'].')');
			}
		}
	}
	
	public function __destruct(){}

}
?>