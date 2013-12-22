<?php

/*
__PocketMine Plugin__
name=ChatControl
description=Plugin that gives you more control over the chat on your server
version=2.0
author=wies
class=ChatControl
apiversion=11
*/

/*
--------License--------
This work is licensed under the Creative Commons Attribution-ShareAlike 4.0 International License.
To view a copy of this license, visit http://creativecommons.org/licenses/by-sa/4.0/deed.en_US.
-----------------------
*/


class ChatControl implements Plugin{
	private $api, $server, $db;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->server = ServerAPI::request();
	}
	
	public function init(){
		$this->path = $this->api->plugin->configPath($this);
		$this->loadFiles();	
		$this->loadDB();
		$this->api->addHandler('player.chat', array($this, 'playerchat'));
		$this->api->addHandler('player.join', array($this, 'playerjoin'));
		$this->api->addHandler('player.quit', array($this, 'playerquit'));
		$this->api->addHandler('server.chat', array($this, 'serverchat'));
		$this->api->addHandler('console.command.tell', array($this, 'commandTell'));
		$this->api->console->register('cc', "ChatControl commands", array($this, 'commands'));
		$this->api->console->register('r', 'Quickly reply the last player messaged you', array($this, 'pmCommands'));
		$this->api->ban->cmdWhitelist('r');
		$this->players = array();
		$this->muted = false;
	}
	
	private function loadDB(){
		$this->db = new SQLite3(":memory:");
		$this->db->exec("PRAGMA encoding = \"UTF-8\";");
		$this->db->exec("PRAGMA secure_delete = OFF;");
		$this->db->exec("CREATE TABLE players(
			ID INTEGER PRIMARY KEY AUTOINCREMENT,
			username TEXT,
			lastMsg TEXT,
			timeLastMsg INTEGER,
			muting INTEGER,
			senderLastPM TEXT,
			blacklist TEXT,
			chatgroup TEXT
		);");
		
	}
	
	public function pmCommands($cmd, $args, $issuer){
		if($this->config['chat']['private']['enabled'] == false){
			return 'Private chat is disabled';
		}
		$username = $issuer->iusername;
		switch($cmd){
			case 'tell':
				if(!(isset($args[0]) and isset($args[1]))){
					return 'Usage /tell <player> <msg>';
				}
				$name = strtolower($args[0]);
				$player = $this->api->player->get($name);
				if($player === false){
					return $name." doesn't exist";
				}
				$msg = implode(' ', array_slice($args, 1));
				break;
				
			case 'r':
				if(!isset($args[0])){
					return 'Usage: /r <msg>';
				}
				$stmt = $this->db->prepare("SELECT senderLastPM FROM players WHERE username = :name;");
				$stmt->bindValue(':name', $username, SQLITE3_TEXT);
				$result = $stmt->execute();
				if($result === ''){
					return 'No player sent you a message';
				}
				$name = $result->fetchArray(SQLITE3_NUM);
				$name = strtolower($name[0]);
				$player = $this->api->player->get($name);
				if($player === false){
					return $name." isn't online anymore";
				}
				$msg = implode(' ', $args);
				break;
		}
		$senderMsg = $this->messagePrepare($msg, $issuer, 'guest', 'private1'); // need to implement group checking
		$receiverMsg = $this->messagePrepare($msg, $player, 'guest', 'private2');
		$issuer->sendChat($senderMsg);
		$player->sendChat($receiverMsg);
		$stmt = $this->db->prepare("UPDATE players SET senderLastPM = :name WHERE username = :username;
									UPDATE players SET senderLastPM = :username WHERE username = :name;");
		$stmt->bindValue(':name', $name, SQLITE3_TEXT);
		$stmt->bindValue(':username', $username, SQLITE3_TEXT);
		$stmt->execute();
		return;
	}
	
	public function commandTell($data){
		$this->pmCommands('tell', $data['parameters'], $data['issuer']);
	}
	
	public function muteCommands($cmd, $args, $issuer){
		if(!$issuer instanceof Player){
			return 'Run this command ingame';
		}
		$username = $issuer->iusername;
		switch($cmd){
			case 'mute':	
				if(isset($args[0])){
					$name = strtolower($args[0]);
					$player = $this->api->player->get($name);
					if($player === false){
						return $name." doesn't exists";
					}
					$stmt = $this->db->prepare("SELECT * FROM players WHERE username = :name;");
					$stmt->bindValue(':name', $name, SQLITE3_TEXT);
					$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
					$blacklist = $result['blacklist'];
					if(!strpos($blacklist, $name)){
						$blacklist = explode(',', $blacklist);
						$blacklist[] = $name;
						$blacklist = implode(',', $blacklist);
						$stmt = $this->db->prepare("UPDATE players SET blacklist = :blacklist WHERE ID = :id;");
						$stmt->bindValue(':blacklist', $blacklist, SQLITE3_TEXT);
						$stmt->bindValue(':id', $result['ID'], SQLITE3_NUM);
						$stmt->execute();
					}
					$output = 'You muted '.$name;
				}else{
					$stmt = $this->db->prepare("UPDATE players SET muting = 1 WHERE username = :username;");
					$stmt->bindValue(':username', $username, SQLITE3_TEXT);
					$stmt->execute();
					return "You won't receive any message from now";
				}
				break;
							
			case 'unmute':	
				if(isset($args[0])){
					$name = strtolower($args[0]);
					$player = $this->api->player->get($args[0]);
					if($player === false){
						return "[ChatControl] That player doesn't exists";
					}
					$stmt = $this->db->prepare("SELECT * FROM players WHERE username = :name;");
					$stmt->bindValue(':name', $name, SQLITE3_TEXT);
					$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
					$blacklist = $result['blacklist'];
					if(strpos($blacklist, $name)){
						$blacklist = explode(',', $blacklist);
						$key = array_search($name, $blacklist);
						unset($blacklist[$key]);
						$blacklist = implode(',', $blacklist);
						$stmt = $this->db->prepare("UPDATE players SET blacklist = :blacklist WHERE ID = :id;");
						$stmt->bindValue(':blacklist', $blacklist, SQLITE3_TEXT);
						$stmt->bindValue(':id', $result['ID'], SQLITE3_NUM);
						$stmt->execute();
					}
					return 'You unmuted '.$name;
				}else{
					$stmt = $this->db->prepare("UPDATE players SET muting = 0 WHERE username = :username;");
					$stmt->bindValue(':username', $username, SQLITE3_TEXT);
					$stmt->execute();
					return 'You can see the chat again';
				}
				break;
		}
	}
	
	public function commands($cmd, $args, $issuer){
		$username = $issuer->iusername;
		switch($args[0]){
			case 'group':
				if(!(isset($args[1]) and isset($args[2]))){
					return 'Usage: /cc group <player> <group>';
				}
				$player = strtolower($args[1]);
				$group = strtolower($args[2]);
				if(!isset($this->groups[$group])){
					return "That group doesn't exists";
				}
				$stmt = $this->db->prepare("SELECT * FROM players WHERE username = :name;");
				$stmt->bindValue(':name', $player);
				$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
				if($result !== false){
					$stmt = $this->db->prepare("UPDATE players SET chatgroup = :group WHERE ID = ".$result['ID']);
					$stmt->bindValue(':group', $group);
					$result = $stmt->execute();
				}
				if(!in_array($player, $this->groups[$group])){
					$this->groups[$group][] = $player;
				}
				return $player.' is now a member of the group '.$group;
				
			case 'ban':
				if(!isset($args[1])){
					return '[ChatControl] Usage: /cc ban <player>';
				}
				$name = strtolower($args[1]);
				if($this->bannedPlayers->exists($name) === true){
					return '[ChatControl] Player already in the chat-ban-list';
				}
				$this->bannedPlayers->set($name);
				$this->bannedPlayers->save();
				return '[ChatControl] '.$name." can't chat anymore";
				break;
				
			case 'unban':		
				if(!isset($args[1])){
					return '[ChatControl] Usage: /cc unban <player>';
				}
				$name = strtolower($args[1]);
				if($this->bannedPlayers->exists($name) === false){
					return "[ChatControl] Player doesn't exists in the chat-ban-list";
				}
				$this->bannedPlayers->remove($name);
				$this->bannedPlayers->save();
				return '[ChatControl] '.$name.' can chat again';
				break;
								
			case 'reload':		
				$this->loadFiles();
				return '[ChatControl] Reloaded';
				break;
								
			case 'prefix':
			case 'suffix':		
				if (!isset($args[1]) or !isset($args[2])) {
					return '[ChatControl] Usage: /cc <prefix|suffix> <group> <text>';
				}
				$group = strtolower($args[1]);
				if(!isset($this->groups[$group])){
					return  "[ChatControl] The group doesn't exist";
				}
				$text = implode(array_slice($args, 2));
				if($args[0] === 'prefix'){
					$this->groups[$group]['prefix'] = $text;
					$output = '[ChatControl] The prefix of '.$group.' is changed to '.$text;
				}else{
					$this->groups[$group]['suffix'] = $text;
					$output = '[ChatControl] The suffix of '.$group.' is changed to '.$text;
				}
				$this->api->plugin->writeYAML($this->path.'groups.yml', $this->groups);
				return $output;
				break;
			
			case 'clear':		
				for($i = 0; $i <= 20; $i++){
					$this->api->chat->broadcast(' ');
				}
				return '[ChatControl] Chat cleared!';
				break;
			
			case 'mute':		
				$this->muted = true;
				return '[ChatControl] nobody can chat now';
				break;
								
			case 'unmute':		
				$this->muted = false;
				return '[ChatControl] un-muted the chat';
				break;
								
			default:			
				$output = "===============[ChatControl Commands]===============\n";
				$output .= "/cc ban - Stop a player from chatting\n";
				$output .= "/cc unban - Let a player chat again\n";
				$output .= "/cc prefix <group> <prefix> - Change the prefix of a group\n";
				$output .= "/cc suffix <group> <suffix> - Change the suffix of a group\n";
				$output .= "/cc reload - Reload the config file\n";
				$output .= "/cc clear - Clear the chat box\n";
				$output .= "/cc mute - Disable chatting on your server\n";
				$output .= "/cc unmute - Enable chatting on your server\n";
				return $output;
				break;
		}
	}
	
	public function getMutingPlayers(){
		$players = array();
		$stmt = $this->db->prepare("SELECT username FROM players WHERE muting = 1;");
		$result = $stmt->execute();
		while($res = $result->fetchArray(SQLITE3_NUM)){
			$players[] = $res[0];
		}
		return $players;
	}
	
	public function serverchat($data){
		$message = $data->get();
		if($this->config['DisableLeaveMsg'] == true){
			if(strpos($message, 'left the game')) return false;
		}
		if($this->config['DisableDieMsg'] == true){
			$dieMessages = array(' was killed by ', ' was killed', ' was pricked to death', ' tried to swim in lava', ' went up in flames', ' burned to death',
								 ' suffocated in a wall', ' drowned', ' fell out of the world', ' hit the ground too hard', ' blew up', ' died');
			foreach($dieMessages as $string){
				if(strpos($message, $string)) return false;
			}
		}
		if($this->config['PlayerCanMuteChat']['Enabled'] == true and $this->config['PlayerCanMuteChat']['MuteBroadcastMsg'] == true){
			$blacklist = $this->getMutingPlayers();
			$this->sendMessage($message, $blacklist);
			return false;
		}
	}
	
	public function sendMessage($msg, $blacklist = array()){
		if($this->config['DisplayChatInConsole'] == true){
			console('[CHAT] '.$msg);
		}
		$players = $this->api->player->getAll();
		foreach($players as $player){
			if(!in_array($player->iusername, $blacklist)){
				$player->sendChat($msg);
			}
		}
	}
	
	public function messagePrepare($msg, $player, $group = 'guest', $type = 'global'){
		$needle = array('%name%', '%map%', '%prefix%', '%suffix%', '%msg%', '%health%');
		$replace = array($player->username, $player->level->getName(), $this->groups[$group]['prefix'], $this->groups[$group]['suffix'], $msg, $player->entity->getHealth());
		if($type === 'private1'){
			$msg = str_replace($needle, $replace, $this->config['chat']['private']['sender']);
		}elseif($type === 'private2'){
			$msg = str_replace($needle, $replace, $this->config['chat']['private']['receiver']);
		}else{
			$msg = str_replace($needle, $replace, $this->config['chat'][$type]['format']);
		}
		$message = $msg;
		if($this->config['SplitLongMessages']['Enabled'] == true){
			$length = $this->config['SplitLongMessages']['Length'];
			if(strlen($msg) > $length){
				$message = '';
				while(strlen($msg) > $length){
					$message .= substr($msg, 0, $length)."\n";
					$msg = substr($msg, $length);
				}
			}
		}
		return $message;
	}
	
	public function playerchat($data){
		$username = $data['player']->iusername;
		$player = $data['player'];
		if($player === 'console') return true;
		if($this->bannedPlayers->exists($username) or $this->muted){
			$player->sendChat('You are not allowed to chat!');
			return false;
		}
		$message = $data['message'];
		$stmt = $this->db->prepare("SELECT * FROM players WHERE username = :username;");
		$stmt->bindValue(':username', $username, SQLITE3_TEXT);
		$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
		$group = $result['chatgroup'];
		if(($this->config['ChatIntervalProtection'] == true) and ($this->groups[$group]['interval'] != 0)){
			if(($result['timeLastMsg'] + $this->groups[$group]['interval']) > time()){
				$player->sendChat("Don't send more than one msg in ".$this->groups[$group]['interval'].' seconds');
				return false;
			}
		}
		$type = 'global';
		$blacklist = array();
		if($this->config['PlayerCanMuteChat']['Enabled'] == true){
			$blacklist = explode(',', $result['blacklist']);
			$blacklist = array_merge($blacklist, $this->getMutingPlayers());
		}
		if(isset($data['type'])){
			if($data['type'] === 'map'){
				$type = 'map';
				$level = $data['level'];
				foreach($this->api->player->getAll() as $p){
					if($p->level->getName() === $level) $blacklist[] = $p->iusername;
				}
			}elseif($data['type'] === 'local'){
				$type = 'local';
				$level = $data['level'];
				$radius = $data['radius'];
				$pos = new Vector2($player->entity->x, $player->entity->z);
				foreach($this->api->player->getAll() as $p){
					if($p->level->getName() === $level){
						if($pos->distance(new Vector2($p->entity->x, $p->entity->z)) > $radius) $blacklist[] = $p->iusername;
					}
				}
			}
		}
		$msg = $this->messagePrepare($message, $player, $group, $type);
		$this->sendMessage($msg, $blacklist);
		$stmt = $this->db->prepare("UPDATE players SET timeLastMsg = :time AND lastMsg = :msg WHERE ID = :id;");
		$stmt->bindValue(':time', time(), SQLITE3_NUM);
		$stmt->bindValue(':msg', $message, SQLITE3_TEXT);
		$stmt->bindValue(':id', $result['ID'], SQLITE3_NUM);
		$stmt->execute();
		return false;
	}
	
	public function playerquit($data){
		$username = $data->iusername;
		$stmt = $this->db->prepare("DELETE FROM players WHERE username = :username;");
		$stmt->bindValue(':username', $username, SQLITE3_TEXT);
		$stmt->execute();
	}
	
	public function playerjoin($data){
		$username = $data->iusername;
		$group = 'guest';
		foreach($this->groups as $key => $val){
			if($key !== 'guest' and in_array($username, $val['players'])){
				$group = $key;
				break;
			}
		}
		$stmt = $this->db->prepare("INSERT INTO players (username,chatgroup,lastMsg,timeLastMsg,muting,senderLastPM,blacklist) VALUES (:username,:group,'',0,0,'','');");
		$stmt->bindValue(':username', $username, SQLITE3_TEXT);
		$stmt->bindValue(':group', $group, SQLITE3_TEXT);
		$stmt->execute();
	}
	
	public function mapCommand($cmd, $args, $issuer){
		if(!$issuer instanceof Player){
			return 'Run this command inn-game';
		}		
		if(!isset($args[0])){
			return 'Usage: /map <message>';
		}
		$data = array('player' => $issuer, 'message' => implode(' ', $args), 'type' => 'map',
					  'level' => $issuer->level->getName());
		$this->playerchat($data);
	}
	
	public function localCommand($cmd, $args, $issuer){
		if(!$issuer instanceof Player){
			return 'Run this command inn-game';
		}		
		if(!isset($args[0])){
			return 'Usage: /map <message>';
		}
		$data = array('player' => $issuer, 'message' => implode(' ', $args), 'type' => 'local',
					  'level' => $issuer->level->getName(), 'radius' => $this->config['chat']['local']['radius']);
		$this->playerchat($data);
	}
	
	private function loadFiles(){
		if(!file_exists($this->path.'config.yml')){
			$data = array(
				'ChatIntervalProtection' => false,
				'DisableLeaveMsg' => true,
				'DisableDieMsg' => true,
				'DisplayChatInConsole' => true,
				'PlayerCanMuteChat' => array(
					'Enabled' => true,
					'MuteBroadcastMsg' => false,
				),
				'SplitLongMessages' => array(
					'Enabled' => true,
					'Length' => 20,
				),
				'chat' => array(
					'global' => array(
						'format' => '%prefix%%name%%suffix%: %msg%',
					),
					'private' => array(
						'enabled' => true,
						'sender' => '<you -> %receiver%> %msg%',
						'receiver' => '<%sender% -> you> %msg%',
					),
					'map' => array(
						'enabled' => true,
						'format' => '<%map%>%prefix%%name%%suffix%: %msg%',
					),
					'local' => array(
						'enabled' => true,
						'radius' => 15,
						'format' => '<local>%prefix%%name%%suffix%: %msg%',
					),
				),
			);
			$this->api->plugin->writeYAML($this->path.'config.yml', $data);
		}
		if(!file_exists($this->path.'groups.yml')){
			$data = array(
				'admin' => array(
					'prefix' => '[admin]',
					'suffix' => '',
					'chatInterval' => 0,
					'players' => array(),
				),
				'guard' => array(
					'prefix' => '[guard]',
					'suffix' => '',
					'chatInterval' => 0,
					'players' => array(),
				),
				'guest' => array(
					'prefix' => '',
					'suffix' => '',
					'chatInterval' => 10,
				),
			);
			$this->api->plugin->writeYAML($this->path.'groups.yml', $data);
		}
		$this->config = $this->api->plugin->readYAML($this->path.'config.yml');
		$this->groups = $this->api->plugin->readYAML($this->path.'groups.yml');
		if($this->config['PlayerCanMuteChat']['Enabled'] == true){
			$this->api->console->register('mute', 'Hide the chat', array($this, 'muteCommands'));
			$this->api->console->register('unmute', 'Display the chat again', array($this, 'muteCommands'));
			$this->api->ban->cmdWhitelist('mute');
			$this->api->ban->cmdWhitelist('unmute');
		}
		if($this->config['chat']['map']['enabled'] == true){
			$this->api->console->register('map', 'Send message to all the players in the same level', array($this, 'mapCommand'));
			$this->api->ban->cmdWhitelist('map');
		}
		if($this->config['chat']['local']['enabled'] == true){
			$this->api->console->register('local', 'Send message to all the players nearby', array($this, 'localCommand'));
			$this->api->ban->cmdWhitelist('local');
		}
		if($this->config['chat']['local']['enabled'] == true){
			$this->api->console->register('local', 'Send message to all the players nearby', array($this, 'localCommand'));
			$this->api->ban->cmdWhitelist('local');
		}
		$this->bannedPlayers = new Config($this->path."bannedPlayers.txt", CONFIG_LIST);
	}
	
	public function __destruct(){}
}