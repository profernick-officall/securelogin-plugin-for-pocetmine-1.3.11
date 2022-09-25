<?php

/*
__PocketMine Plugin__
name=securelogin
description=Prevents people to impersonate an account, requiring registration and login when connecting.
version=0.3.9
author=shoghicp
class=securelogin
apiversion=9,10,11,12
*/

/*

Changelog:

0.1
pre-realise

*/

class securelogin implements Plugin{
	private $api, $server, $config, $sessions, $lastBroadcast = 0;
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->server = ServerAPI::request();
		$this->sessions = array();
		secureloginAPI::set($this);
	}
	
	public function init(){
		$this->config = new Config($this->api->plugin->configPath($this)."config.yml", CONFIG_YAML, array(
			"allowChat" => false,
			"messageInterval" => 5,
			"timeout" => 120,
			"allowRegister" => true,
			"forceSingleSession" => true,
			"minPasswordLength" => 6,
			"authenticateByLastIP" => false,
		));
		@mkdir($this->api->plugin->configPath($this)."players/");
		if(file_exists($this->api->plugin->configPath($this)."players.yml")){			
			$playerFile = new Config($this->api->plugin->configPath($this)."players.yml", CONFIG_YAML, array());
			console("[INFO] [securelogin] Importing old format to new format...");
			foreach($playerFile->getAll() as $k => $value){
				@mkdir($this->api->plugin->configPath($this)."players/".$k{0}."/");
				$d = new Config($this->api->plugin->configPath($this)."players/".$k{0}."/".$k.".yml", CONFIG_YAML, $value);
				$d->save();
			}
			@unlink($this->api->plugin->configPath($this)."players.yml");
		}
		
		$this->api->addHandler("player.quit", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.connect", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.spawn", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.respawn", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.chat", array($this, "eventHandler"), 50);
		$this->api->addHandler("console.command", array($this, "eventHandler"), 50);
		$this->api->addHandler("op.check", array($this, "eventHandler"), 50);
		$this->api->addHandler("entity.health.change", array($this, "eventHandler"), 50);
		$this->api->schedule(20, array($this, "checkTimer"), array(), true);
		$this->api->console->register("unregister", "<password>", array($this, "commandHandler"));		
		$this->api->ban->cmdWhitelist("unregister");

		$this->api->console->register("securelogin", "<command> [parameters...]", array($this, "commandHandler"));
		console("[INFO] securelogin enabled!");
	}
	
	public function getData($iusername){
		$iusername = strtolower($iusername);
		if(!file_exists($this->api->plugin->configPath($this)."players/".$iusername{0}."/$iusername.yml")){
			return false;
		}
		return new Config($this->api->plugin->configPath($this)."players/".$iusername{0}."/$iusername.yml", CONFIG_YAML, array());
	}
	
	public function commandHandler($cmd, $params, $issuer, $alias){
		$output = "";
		switch($cmd){
			case "securelogin":
				if(!isset($params[0])){
					$output .= "Usage: /securelogin <command> [parameters...]\n";
					$output .= "Available commands: help, unregister\n";
				}
				switch(strtolower(array_shift($params))){
					case "unregister":
						if(($player = $this->api->player->get($params[0])) instanceof Player){						
							@unlink($this->api->plugin->configPath($this)."players/".$player->iusername{0}."/".$player->iusername.".yml");
							$this->logout($player);
						}else{
							@unlink($this->api->plugin->configPath($this)."players/".substr(strtolower($params{0}), 0, 1)."/".strtolower($params[0]).".yml");
						}
						break;
					case "help":
					default:
						$output .= "/securelogin help: Shows this information.\n";
						$output .= "/securelogin unregister <player>: Removes the player from the database.\n";
				}
				break;
			case "unregister":
				if(!($issuer instanceof Player)){
					$output .= "Please run this command inside the game.\n";
					break;
				}
				if($this->sessions[$issuer->CID] !== true){
					$output .= "Please login first.\n";
					break;
				}
				$d = $this->getData($issuer->iusername);
				if($d !== false and $d->get("hash") === $this->hash($issuer->iusername, implode(" ", $params))){
					unlink($this->api->plugin->configPath($this)."players/".$issuer->iusername{0}."/".$issuer->iusername.".yml");
					$this->logout($issuer);
					$output .= "[securelogin] Unregistered correctly.\n";
				}else{
					$output .= "[securelogin] Error during authentication.\n";
				}
				break;
		}
		return $output;
	}
	
	public function checkTimer(){
		if($this->config->get("allowRegister") !== false and ($this->lastBroadcast + $this->config->get("messageInterval")) <= time()){
			$broadcast = true;
			$this->lastBroadcast = time();
		}else{
			$broadcast = false;
		}
		
		if(($timeout = $this->config->get("timeout")) <= 0){
			$timeout = false;
		}
		
		foreach($this->sessions as $CID => $timer){
			if($timer !== true and $timer !== false and isset($this->server->clients[$CID]) and ($this->server->clients[$CID] instanceof Player)){				
				if($broadcast === true){
					$d = $this->getData($this->server->clients[$CID]->iusername);
					if($d === false){					
						$this->server->clients[$CID]->sendChat("[securelogin] You must register using /register <password>");
					}else{
						$this->server->clients[$CID]->sendChat("[securelogin] You must authenticate using /login <password>");
					}
				}
				if($timeout !== false and ($timer + $timeout) <= time()){
					$this->server->clients[$CID]->close("authentication timeout");
				}
			}
		}
		
	}
	
	private function hash($salt, $password){
		return bin2hex(hash("sha512", $password . $salt, true) ^ hash("whirlpool", $salt . $password, true));
	}
	
	public function checkLogin(Player $player, $password){
		$d = $this->getData($player->iusername);
		if($d !== false and $d->get("hash") === $this->hash($player->iusername, $password)){
			return true;
		}
		return false;
	}
	
	public function login(Player $player){
		$d = $this->getData($player->iusername);
		if($d !== false){
			$d->set("logindate", time());
			$d->set("lastip", $player->ip);
			$d->save();
		}
		$this->sessions[$player->CID] = true;
		$player->blocked = false;
		$player->entity->setHealth($player->entity->health, "generic");
		$player->sendChat("[securelogin] You've been authenticated.");
		$this->server->handle("securelogin.login", $player);
		return true;
	}
	
	public function logout(Player $player){
		$this->sessions[$player->CID] = time();
		$player->blocked = true;
		$this->server->handle("securelogin.logout", $player);
	}
	
	public function register(Player $player, $password){	
		$d = $this->getData($player->iusername);
		if($d === false){
			@mkdir($this->api->plugin->configPath($this)."players/".$player->iusername{0}."/");
			$d = new Config($this->api->plugin->configPath($this)."players/".$player->iusername{0}."/".$player->iusername.".yml", CONFIG_YAML, array());
			$d->set("registerdate", time());
			$d->set("logindate", time());
			$d->set("lastip", $player->ip);
			$d->set("hash", $this->hash($player->iusername, $password));
			$d->save();
			$this->server->handle("securelogin.register", $player);
			return true;
		}
		return false;
	}
	
	public function eventHandler($data, $event){
		switch($event){
			case "player.quit":
				unset($this->sessions[$data->CID]);
				break;
			case "player.connect":
				$p = $this->api->player->get($data->iusername);
				$this->sessions[$data->CID] = false;
				if($this->config->get("forceSingleSession") === true){
					if(($p instanceof Player) and $p->iusername === $data->iusername){
						$p->close("player already connected", false);
						unset($this->sessions[$data->CID]);
						return false;
					}
				}
				break;
			case "player.spawn":
				if(!isset($this->sessions[$data->CID]) or $this->sessions[$data->CID] !== true){
					$this->sessions[$data->CID] = time();
					$data->blocked = true;
					$data->sendChat("[securelogin] This server uses securelogin to protect your account.");
					if($this->config->get("allowRegister") !== false){
						$d = $this->getData($data->iusername);
						if($this->config->get("authenticateByLastIP") === true and ($d instanceof Config) and $d->get("lastip") == $data->ip){
							$this->login($data);
							break;
						}
						if($d === false){					
							$data->sendChat("[securelogin] You must register using /register <password>");
						}else{
							$data->sendChat("[securelogin] You must authenticate using /login <password>");
						}
					}
				}
				break;
			case "entity.health.change":
				if(($data["entity"]->player instanceof Player) and (!isset($this->sessions[$data["entity"]->player->CID]) or $this->sessions[$data["entity"]->player->CID] !== true)){
					return false;
				}
				break;
			case "console.command":
				if(($data["issuer"] instanceof Player) and $this->sessions[$data["issuer"]->CID] !== true){
					if($data["cmd"] === "login" and $this->checkLogin($data["issuer"], implode(" ", $data["parameters"])) === true){
						$this->login($data["issuer"]);
						return true;
					}elseif($data["cmd"] === "register" and strlen(implode(" ", $data["parameters"])) < $this->config->get("minPasswordLength")){
						$data["issuer"]->sendChat("[securelogin] Password is too short.");
						return true;
					}elseif($this->config->get("allowRegister") !== false and $data["cmd"] === "register" and $this->register($data["issuer"], implode(" ", $data["parameters"])) === true){
						$data["issuer"]->sendChat("[securelogin] You've been sucesfully registered.");
						$this->login($data["issuer"]);
						return true;
					}elseif($data["cmd"] === "login" or $data["cmd"] === "register"){
						$data["issuer"]->sendChat("[securelogin] Error during authentication.");
						return true;
					}
					return false;
				}
				break;
			case "player.chat":
				if($this->config->get("allowChat") !== true and $this->sessions[$data["player"]->CID] !== true){
					return false;
				}
				break;
			case "op.check":
				$p = $this->api->player->get($data);
				if(($p instanceof Player) and (!isset($this->sessions[$p->CID]) or $this->sessions[$p->CID] !== true)){
					return false;
				}
				break;
			case "player.respawn":
				if(!isset($this->sessions[$data->CID]) or $this->sessions[$data->CID] !== true){
					$data->blocked = true;
				}
				break;
		}
		return;
	}
	
	public function __destruct(){
		$this->config->save();
	}

}

class secureloginAPI{
	private static $object;
	public static function set(securelogin $plugin){
		if(secureloginAPI::$object instanceof securelogin){
			return false;
		}
		secureloginAPI::$object = $plugin;
	}
	
	public static function get(){
		return secureloginAPI::$object;
	}
	
	public static function login(Player $player){
		return secureloginAPI::$object->login($player);
	}
	
	public static function logout(Player $player){
		return secureloginAPI::$object->logout($player);
	}
}
