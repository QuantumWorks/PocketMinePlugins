<?php

/*
__PocketMine Plugin__
name=CouponCodes
description=
version=1.0
author=wies
class=CouponCodes
apiversion=9,10
*/
		
class CouponCodes implements Plugin{
	private $api, $coupons, $config, $path, $lastcode;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
	}
	
	public function init(){
		$this->api->console->register("coupon", "Use a coupon code", array($this, "command"));
		$this->api->ban->cmdWhitelist("coupon");
		$this->createConfigs();
		$this->lastcode = ' ';
	}
	
	public function validation($code, $player){
		if(!isset($this->coupons[$code])) return false;
		$data = $this->coupons[$code];
		if($data['commands'] != false){
			foreach($data['commands'] as $command){
				$command = str_replace(array('--player--', '--username--', '--user--', '--name--'), $player->username, $command);
				$this->api->console->run($command);
			}
		}
		$player->sendChat($data['message']);
		++$data['timesUsed'];
		if($data['timesUsed'] >= $data['maxTimesUsed']){
			unset($this->coupons[$code]);
		}else{
			$this->coupons[$code] = $data;
		}
		$this->save();
		return true;
	}
	
	private function save(){
		$this->api->plugin->writeYAML($this->path."coupons.yml", $this->coupons);
	}
	
	private function generateCode(){
		$length = $this->config['code-length'];
		$characters = $this->config['allowed-chars'];
		$charStringLength = strlen($characters);
		do{
			$randomCode = '';
			for($i = 0; $i < $length; $i++){
				$randomCode .= $characters[mt_rand(0, $charStringLength - 1)];
			}
		}while(isset($this->coupons[$randomCode]));
		return $randomCode;
	}
	
	public function command($cmd, $args, $issuer){
		$output = '';
		if(($issuer instanceof Player) and !$this->api->ban->isOp($issuer->username)){
			if(!isset($args[0])){
				return 'Usage: /coupon [code]';
			}
			$valid = $this->validation($args[0], $issuer);
			if($valid === false){
				$output = "That coupon-code isn't valid";
			}
			return $output;
		}
		switch($args[0]){
			case 'create':
				$code = $this->generateCode();
				if(isset($args[1])){
					$type = $args[1];
					if(isset($this->config['coupon-types'][$type])){
						$this->coupons[$code] = $this->config['coupon-types'][$type];
					}else{
						$output = "That coupon type doesn't exists";
						break;
					}
				}else{
					$this->coupons[$code] = $this->config['coupon-types']['default'];
				}
				$this->coupons[$code]['timesUsed'] = 0;
				$this->lastcode = $code;
				$this->save();
				$output = "Created a new coupon with the code:\n".$code;
				break;
				
			case 'addcommand':
				if(!(isset($args[1]) and isset($args[2]))){
					$output = 'Usage: /coupon addcommand [code] [command]';
					break;
				}
				$code = $args[1];
				if($code === 'last'){
					$code = $this->lastcode;
				}
				if(!isset($this->coupons[$code])){
					$output = "That coupon code doesn't exists";
					break;
				}
				$command = implode(array_slice($args, 2), ' ');
				$this->coupons[$code]['commands'][] = $command;
				$this->save();
				$output = "Added the command:\n".$command."\nto the coupon-code:\n".$code;
				break;
				
			case 'changemsg':
				if(!(isset($args[1]) and isset($args[2]))){
					$output = 'Usage: /coupon changemsg [code] [message]';
					break;
				}
				$code = $args[1];
				if($code === 'last'){
					$code = $this->lastcode;
				}
				if(!isset($this->coupons[$code])){
					$output = "That coupon code doesn't exists";
					break;
				}
				$message = implode(array_slice($args, 2), ' ');
				$this->coupons[$code]['message'] = $message;
				$this->save();
				$output = "You changed the message of coupon-code:\n".$message."\nto the message:\n".$code;
				break;
				
			case 'remove':
				if(!isset($args[1])){
					$output = 'Usage: /coupon removecode [code]';
					break;
				}
				$code = $args[1];
				if($code === 'last'){
					$code = $this->lastcode;
				}
				if(!isset($this->coupons[$code])){
					$output = "That coupon code doesn't exists";
					break;
				}
				unset($this->coupons[$code]);
				$this->save();
				break;
				
			default:
				$output = "=============[Commands]=============\n";
				$output .= "/coupon create [optional: type]\n";
				$output .= "/coupon addcommmand [code] [command]\n";
				$output .= "/coupon changemessage [code] [message]\n";
				$output .= "/coupon remove [code]";
				break;
		}
		return $output;
	}
	
	public function createConfigs(){
		$this->path = $this->api->plugin->configPath($this);
		if(file_exists($this->path."config.yml")){
			$this->config = $this->api->plugin->readYAML($this->path."config.yml");
		}else{
			$config = array(
				'code-length' => 8,
				'allowed-chars' => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
				'coupon-types' => array(
					'default' => array(
						'maxTimesUsed' => 1,
						'commands' => array(),
						'message' => 'Coupon-code is successfully executed',
					),
					'op' => array(
						'maxTimesUsed' => 1,
						'commands' => array(
							'op --player--',
						),
						'message' => 'Coupon-code is successfully executed',
					),
				),
			);
			$this->config = $config;
			$this->api->plugin->writeYAML($this->path."config.yml", $config);
			$readme = "##########################################################################################
# CouponCodes config file                                                                #
#                                                                                        #
# code-length  - The default length of the generated coupon codes                        #
# allowed-char - This is a string with all the allowed characters for the coupon codes   #
# coupon-types - A list of all the coupon types, you can add as many types as you want.  #
#                DON'T remove the default coupon type, if no type is specified in the    #
#                command: '/coupon create [type]', than a default one will be created.   #
#                                                                                        #
# structure of the coupon-types:                                                         #
#    maxTimesUsed - How many times the coupon code can be used.                          #
#    commands     - A list with all the commands that needs to be executed when someone  #
#                   use the coupon code.                                                 #
#    message      - The message that will be send when someone use the coupon code       #		 
##########################################################################################";
			$file = file_get_contents($this->path."config.yml");
			$file = $readme."\n".$file;
			file_put_contents($this->path."config.yml", $file);
		}
		$this->coupons = new Config($this->path."coupons.yml", CONFIG_YAML, array());
		$this->coupons = $this->api->plugin->readYAML($this->path . "coupons.yml");
	}
	
	public function __destruct(){}
}
?>