<?php

/*
__PocketMine Plugin__
name=AutoTree
description=Growing trees automaticly
version=1.1
author=wies
class=AutoTree
apiversion=11,12
*/
		
class AutoTree implements Plugin{
	private $api;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		$this->api->console->register('autotree', 'Commands for auto tree growing', array($this, 'growTrees'));
		$this->path = $this->api->plugin->configPath($this);
		$this->config = new Config($this->path.'config.yml', CONFIG_YAML, array(
			'Interval' => false,
			'BroadcastMsg' => true,
			'Amount' => array(
				'Oak' => 20,
				'Spruce' => 10,
				'Birch' => 10,
				'Jungle' => 10
			),
			'Height' => array(
				'yMin' => 50,
				'yMax' => 80
			),
			'MaxTry' => 200,
			'Levels' => array(
				'world'
			),
		));
		$this->config = $this->api->plugin->readYAML($this->path . 'config.yml');
		if(file_exists($this->path.'trees.data')){
			$this->trees = json_decode(file_get_contents($this->path.'trees.data'), true);
		}else{
			file_put_contents($this->path.'trees.data', json_encode(array()));
			$this->trees = array();
		}
		if(is_numeric($this->config['Interval'])){
			$this->api->schedule(20 * 60 * $this->config['Interval'], array($this, 'growTrees'), array(), true);
		}
	}
	
	public function growTrees(){
		foreach($this->config['Levels'] as $levelName){
			$level = $this->api->level->get($levelName);
			if($level === false){
				console('[AutoTree] Level '.$levelName.' not found');
				continue;
			}
			$amount = array();
			$amount[0] = (int)$this->config['Amount']['Oak'];
			$amount[1] = (int)$this->config['Amount']['Spruce'];
			$amount[2] = (int)$this->config['Amount']['Birch'];
			$amount[3] = (int)$this->config['Amount']['Jungle'];
			$startAmount = $amount;
			$trees = $this->trees[$levelName];
			foreach($this->trees[$levelName] as $key => $tree){
				$pos = new Vector3($tree[0], $tree[1], $tree[2]);
				if($level->getBlock($pos)->getID() === 17){
					--$amount[$tree[3]];				
				}else{
					$rand = new Random();
					switch($tree[3]){
						case 0:	$treeObj = new SmallTreeObject();
								break;
						case 1:	$treeObj = new SpruceTreeObject();
								break;
						case 2:	$treeObj = new SmallTreeObject();
								$treeObj->type = SaplingBlock::BIRCH;
								break;
						case 3:	$treeObj = new SmallTreeObject();
								$treeObj->type = SaplingBlock::JUNGLE;
								break;
					}
					if($treeObj->canPlaceObject($level, $pos, $rand)){
						$treeObj->placeObject($level, $pos, $rand);
						--$amount[$tree[3]];
					}else{
						unset($trees[$key]);
					}
				}
			}
			$this->trees[$levelName] = $trees;
			$maxTrees = $amount[0] + $amount[1] + $amount[2] + $amount[3];
			$maxTry = $this->config['MaxTry'];
			$try = 0;
			for($i=0;$i<$maxTrees and $try<$maxTry;$i++){
				$try++;
				$x = mt_rand(0,255);
				$z = mt_rand(0,255);
				$oak = $amount[0]/$startAmount[0];
				console($oak);
				$spruce = $amount[1]/$startAmount[1];
				$birch = $amount[2]/$startAmount[2];
				$jungle = $amount[3]/$startAmount[3];
				if($oak >= $spruce and $oak >= $birch and $oak >= $jungle){
					$type = 0;
					$tree = new SmallTreeObject();
				}elseif($spruce >= $birch and $spruce >= $jungle){
					$type = 1;
					$tree = new SpruceTreeObject();
				}elseif($birch >= $jungle){
					$type = 2;
					$tree = new SmallTreeObject();
					$tree->type = SaplingBlock::BIRCH;
				}else{
					$type = 3;
					$tree = new SmallTreeObject();
					$tree->type = SaplingBlock::JUNGLE;
				}
				$dirt = false;
				$rand = new Random();
				$yMax = $this->config['Height']['yMax'] + 1;
				for($y = $this->config['Height']['yMin'];$y < $yMax;$y++){
					$id = $level->getBlock(new Vector3($x, $y, $z))->getID();
					if($id === 0 and $dirt === true){
						$pos = new Vector3($x, $y, $z);
						if($tree->canPlaceObject($level, $pos, $rand)){
							$tree->placeObject($level, $pos, $rand);
							$this->trees[$levelName][] = array($x, $y, $z, $type);
							$amount[$type]--;
							continue 2;;
						}
						$dirt = false;
					}elseif($id === 2 or $id === 3){
						$dirt = true;
					}else{
						$dirt = false;
					}
				}
				$i--;
			}
		}
		file_put_contents($this->path.'trees.data', json_encode($this->trees));
		if($this->config['BroadcastMsg'] == true){
			$this->api->chat->broadcast('[QN] Trees have been regenerated!');
		}
	}
	
	public function __destruct(){}

}
?>
