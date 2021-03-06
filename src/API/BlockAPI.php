<?php

/*

           -
         /   \
      /         \
   /   PocketMine  \
/          MP         \
|\     @shoghicp     /|
|.   \           /   .|
| ..     \   /     .. |
|    ..    |    ..    |
|       .. | ..       |
\          |          /
   \       |       /
      \    |    /
         \ | /

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.


*/


define("BLOCK_UPDATE_NORMAL", 0);
define("BLOCK_UPDATE_RANDOM", 1);
define("BLOCK_UPDATE_SCHEDULED", 2);
define("BLOCK_UPDATE_WEAK", 3);





class BlockAPI{
	private $server;
	
	public static function get($id, $meta = 0, $v = false){
		$id = (int) $id;
		if(isset(Block::$class[$id])){
			$classname = Block::$class[$id];
			$b = new $classname($meta);
		}else{
			$b = new GenericBlock($id, $meta);
		}
		if($v instanceof Vector3){
			$b->position($v);
		}
		return $b;
	}
	
	public static function getItem($id, $meta = 0, $count = 1){
		$id = (int) $id;
		if(isset(Item::$class[$id])){
			$classname = Item::$class[$id];
			$i = new $classname($meta, $count);
		}else{
			$i = new Item($id, $meta, $count);
		}
		return $i;
	}
	
	public function setBlock($block, $id, $meta, $update = true, $tiles = false){
		if(($block instanceof Vector3) or (($block instanceof Block) and $block->inWorld === true)){
			$this->server->api->level->setBlock($block->x, $block->y, $block->z, (int) $id, (int) $meta, $update, $tiles);
			return true;
		}
		return false;
	}
	
	public function getBlock($x, $y = 0, $z = 0){
		if($x instanceof Vector3){
			$y = $x->y;
			$z = $x->z;
			$x = $x->x;
		}
		$b = $this->server->api->level->getBlock($x, $y, $z);
		return BlockAPI::get($b[0], $b[1], new Vector3($b[2][0], $b[2][1], $b[2][2]));
	}
	
	public function getBlockFace(Block $block, $face){
		$data = array("x" => $block->x, "y" => $block->y, "z" => $block->z);
		BlockFace::setPosition($data, $face);
		return $this->getBlock($data["x"], $data["y"], $data["z"]);
	}
	
	function __construct(PocketMinecraftServer $server){
		$this->server = $server;
	}

	public function init(){
		$this->server->addHandler("block.update", array($this, "updateBlockRemote"), 1);
		$this->server->api->console->register("give", "Give items to a player", array($this, "commandHandler"));
	}

	public function commandHandler($cmd, $params){
		switch($cmd){
			case "give":
				if(!isset($params[0]) or !isset($params[1])){
					console("[INFO] Usage: /give <username> <item> [amount] [damage]");
					break;
				}
				$username = $params[0];
				$b = explode(":", $params[1]);
				if(!isset($b[1])){
					$meta = 0;
				}else{
					$meta = ((int) $b[1]) & 0xFFFF;
				}
				
				if(defined(strtoupper($b[0]))){
					$item = BlockAPI::getItem(constant(strtoupper($b[0])), $meta);
					if($item->getID() === 0){
						$item = BlockAPI::getItem(((int) $b[0]) & 0xFFFF, $meta);
					}
				}else{
					$item = BlockAPI::getItem(((int) $b[0]) & 0xFFFF, $meta);
				}
				
				if(!isset($params[2])){
					$amount = 64;
				}else{
					$amount = (int) $params[2];
				}
				if(isset($params[3])){
					$meta = ((int) $params[3]) & 0xFFFF;
				}
				if(($player = $this->server->api->player->get($username)) !== false){
					$this->drop($player->entity->x - 0.5, $player->entity->y, $player->entity->z - 0.5, $item->getID(), $item->getMetadata(), $amount);
					console("[INFO] Giving ".$amount." of ".$item->getName()." (".$item->getID().":".$item->getMetadata().") to ".$username);
				}else{
					console("[INFO] Unknown player");
				}

				break;
		}
	}

	private function cancelAction(Block $block){
		$this->server->api->dhandle("block.change", array(
			"x" => $block->x,
			"y" => $block->y,
			"z" => $block->z,
			"block" => $block->getID(),
			"meta" => $block->getMetadata(),
			"fake" => true,
		));
		return false;
	}

	public function playerBlockBreak(Player $player, Vector3 $vector){

		$target = $this->getBlock($vector);
		$item = $player->equipment;
		if(!$target->isBreakable($item, $player) or $this->server->gamemode === 2){
			return $this->cancelAction($target);
		}
		
		
		if($this->server->api->dhandle("player.block.break", array("player" => $player, "target" => $target, "item" => $item)) !== false){
			$drops = $target->getDrops($item, $player);
			$target->onBreak($this, $item, $player);
		}else{
			return $this->cancelAction($target);
		}
		
		
		if(count($drops) > 0){
			foreach($drops as $drop){
				$this->drop($target->x, $target->y, $target->z, $drop[0] & 0xFFFF, $drop[1] & 0xFFFF, $drop[2] & 0xFF);
			}
		}
		return false;
	}

	public function drop($x, $y, $z, $block, $meta, $stack = 1){
		if($block === AIR or $stack <= 0 or $this->server->gamemode === 1){
			return;
		}
		$data = array(
			"x" => $x,
			"y" => $y,
			"z" => $z,
			"meta" => $meta,
			"stack" => $stack,
		);
		$data["x"] += mt_rand(2, 8) / 10;
		$data["y"] += 0.19;
		$data["z"] += mt_rand(2, 8) / 10;
		if($this->server->api->handle("block.drop", $data) !== false){
			for($count = $stack; $count > 0; ){
				$data["stack"] = min(64, $count);
				$count -= $data["stack"];
				$server = ServerAPI::request();
				$e = $server->api->entity->add(ENTITY_ITEM, $block, $data);
				//$e->speedX = mt_rand(-10, 10) / 100;
				//$e->speedY = mt_rand(0, 5) / 100;
				//$e->speedZ = mt_rand(-10, 10) / 100;
				$server->api->entity->spawnToAll($e->eid);
			}
		}
	}

	public function playerBlockAction(Player $player, Vector3 $vector, $face, $fx, $fy, $fz){
		if($face < 0 or $face > 5){
			return false;
		}

		$target = $this->getBlock($vector);		
		$block = $this->getBlockFace($target, $face);
		$item = $player->equipment;
		
		if($target->getID() === AIR){ //If no block exists
			$this->cancelAction($target);
			return $this->cancelAction($block);
		}

		if($target->isActivable === true){
			if($this->server->api->dhandle("player.block.activate", array("player" => $player, "block" => $block, "target" => $target, "item" => $item)) !== false and $target->onActivate($this, $item, $player) === true){
				return false;
			}
		}
		if($this->server->gamemode === 2){ //Adventure mode!!
			return $this->cancelAction($block);
		}
		
		/*if(isset(Material::$activable[$target[0]])){
			switch($target[0]){
				case 54:
					$cancelPlace = true;
					if($this->server->gamemode === 1){
						break;
					}
					$this->server->api->player->getByEID($data["eid"])->dataPacket(MC_CONTAINER_OPEN, array(
						"windowid" => 1,
						"type" => WINDOW_CHEST,
						"slots" => 27,
						"title" => "Chest",
					));
					break;
				case 61:
				case 62:
					$cancelPlace = true;
					if($this->server->gamemode === 1){
						break;
					}
					$this->server->api->player->getByEID($data["eid"])->dataPacket(MC_CONTAINER_OPEN, array(
						"windowid" => 1,
						"type" => WINDOW_FURNACE,
						"slots" => 3,
						"title" => "Furnace",
					));
					break;
				default:
					$cancelPlace = true;
					break;
			}
		}*/

		if($block->y > 127 or $block->y < 0){
			return false;
		}
		
		if($item->isActivable === true and $item->onActivate($this, $player, $block, $target, $face, $fx, $fy, $fz)){
			return $this->cancelAction($block);
		}

		if($item->isPlaceable()){
			$hand = $item->getBlock();
		}else{
			return $this->cancelAction($block);
		}
		
		if(!($block->isReplaceable === true or ($hand->getID() === SLAB and $block->getID() === SLAB))){
			return $this->cancelAction($block);
		}
		
		if($hand->isTransparent === false and $player->entity->inBlock($block->x, $block->y, $block->z)){
			return $this->cancelAction($block); //Entity in block
		}
		
		if($this->server->api->dhandle("player.block.place", array("player" => $player, "block" => $block, "target" => $target, "item" => $item)) === false){
			return $this->cancelAction($block);
		}elseif($hand->place($this, $item, $player, $block, $target, $face, $fx, $fy, $fz) === false){
			return $this->cancelAction($block);
		}
		if($hand->getID() === SIGN_POST or $hand->getID() === WALL_POST){
			$t = $this->server->api->tileentity->addSign($block->x, $block->y, $block->z);
			$t->data["creator"] = $player->username;
		}

		if($this->server->gamemode === 0 or $this->server->gamemode === 2){
			$player->removeItem($item->getID(), $item->getMetadata(), 1);
		}

		return false;
	}

	public function blockScheduler($data){
		$this->updateBlock($data["x"], $data["y"], $data["z"], BLOCK_UPDATE_SCHEDULED);
	}

	public function updateBlockRemote($data, $event){
		if($event !== "block.update"){
			return;
		}
		$this->updateBlock($data["x"], $data["y"], $data["z"], isset($data["type"]) ? $data["type"]:BLOCK_UPDATE_RANDOM);
	}

	public function flowLavaOn($source, $face){
		$down = 0;
		if($face === BlockFace::BOTTOM){
			$level = 0;
			$down = 1;
		}else{
			$level = ($source[1] & 0x07) + 2;
			if($level > 0x07){
				return false;
			}
		}
		$spread = $this->server->api->level->getBlockFace($source, $face);
		if(($source[0] === 10 or $source[0] === 11) and $spread[0] === 10){
			if($level < ($spread[1] & 0x07)){
				$this->server->schedule(20, array($this, "blockScheduler"), array(
					"x" => $spread[2][0],
					"y" => $spread[2][1],
					"z" => $spread[2][2],
					"type" => BLOCK_UPDATE_NORMAL,
				));
				$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], $spread[0], $level | $down, false);
				return true;
			}
		}elseif($spread[0] === 9 or $spread[0] === 8){
			if($source[0] === 11){
				$this->server->api->level->setBlock($source[2][0], $source[2][1], $source[2][2], 49, 0);
			}elseif($face === 0){
				$this->server->api->level->setBlock($source[2][0], $source[2][1], $source[2][2], 1, 0);
			}else{
				$this->server->api->level->setBlock($source[2][0], $source[2][1], $source[2][2], 4, 0);
			}
			return true;
		}elseif(isset(Material::$flowable[$spread[0]])){
			$this->server->schedule(20, array($this, "blockScheduler"), array(
				"x" => $spread[2][0],
				"y" => $spread[2][1],
				"z" => $spread[2][2],
				"type" => BLOCK_UPDATE_NORMAL,
			));
			$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], 10, $level | $down, false);
			return true;
		}elseif(($source[1] & 0x08) === 0x08){
			$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], $source[0], $source[1] & 0x07, false);
			return true;
		}
		return false;
	}

	public function flowWaterOn($source, $face, &$spread = null){
		$down = 0;
		if($face === BlockFace::BOTTOM){
			$level = 0;
			$down = 1;
		}else{
			$level = ($source[1] & 0x07) + 1;
			if($level > 0x07){
				return false;
			}
		}
		$spread = $this->server->api->level->getBlockFace($source, $face);
		if(($source[0] === 8 or $source[0] === 9) and $spread[0] === 8){
			if($level < ($spread[1] & 0x07)){
				$this->server->schedule(10, array($this, "blockScheduler"), array(
					"x" => $spread[2][0],
					"y" => $spread[2][1],
					"z" => $spread[2][2],
					"type" => BLOCK_UPDATE_NORMAL,
				));
				$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], $spread[0], $level | $down, false);
				return true;
			}
		}elseif($spread[0] === 11){
			$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], 49, 0, true);
			return true;
		}elseif($spread[0] === 10){
			if($face === 0 or ($spread[1] & 0x08) === 0){
				$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], 4, 0, true);
				return true;
			}
		}elseif(isset(Material::$flowable[$spread[0]])){
			$this->server->schedule(10, array($this, "blockScheduler"), array(
				"x" => $spread[2][0],
				"y" => $spread[2][1],
				"z" => $spread[2][2],
				"type" => BLOCK_UPDATE_NORMAL,
			));
			$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], 8, $level | $down, false);
			return true;
		}elseif(($source[1] & 0x08) === 0x08){
			$this->server->api->level->setBlock($spread[2][0], $spread[2][1], $spread[2][2], $source[0], $source[1] & 0x07, false);
			return true;
		}
		return false;
	}

	public function updateBlock($x, $y, $z, $type = BLOCK_UPDATE_NORMAL){
		$block = $this->server->api->level->getBlock($x, $y, $z);
		$changed = false;

		switch($block[0]){
			case 8:
			case 9:
				$faces = array();
				if(!$this->flowWaterOn($block, 0, $floor) or $block[0] === 9){
					$this->flowWaterOn($block, 2, $faces[0]);
					$this->flowWaterOn($block, 3, $faces[1]);
					$this->flowWaterOn($block, 4, $faces[2]);
					$this->flowWaterOn($block, 5, $faces[3]);
				}
				if($block[0] === 8){
					//Source creation
					if(!isset(Material::$flowable[$floor[0]])){
						$sources = 0;
						foreach($faces as $i => $b){
							if($b[0] === 9){
								++$sources;
							}
						}
						if($sources >= 2){
							$this->server->api->level->setBlock($block[2][0], $block[2][1], $block[2][2], 9, 0, false);
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							break;
						}
					}
					
					$drained = true;
					$level = $block[1] & 0x07;
					$up = $this->server->api->level->getBlockFace($block, BlockFace::UP);
					if($up[0] === 8 or $up[0] === 9){
						$drained = false;
					}else{
						$b = $this->server->api->level->getBlockFace($block, BlockFace::NORTH);
						if($b[0] === 9 or ($b[0] === 8 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
							$drained = false;
						}else{
							$b = $this->server->api->level->getBlockFace($block, BlockFace::SOUTH);
							if($b[0] === 9 or ($b[0] === 8 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
								$drained = false;
							}else{
								$b = $this->server->api->level->getBlockFace($block, BlockFace::EAST);
								if($b[0] === 9 or ($b[0] === 8 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
									$drained = false;
								}else{
									$b = $this->server->api->level->getBlockFace($block, BlockFace::WEST);
									if($b[0] === 9 or ($b[0] === 8 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
										$drained = false;
									}
								}
							}
						}
					}
					if($drained === true){
						++$level;
						if($level > 0x07){
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0] + 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0] - 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] + 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] - 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1] - 1,
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->api->level->setBlock($block[2][0], $block[2][1], $block[2][2], 0, 0, false);
						}else{
							$block[1] = ($block[1] & 0x08) | $level;
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0] + 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0] - 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] + 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] - 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1] - 1,
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(10, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->api->level->setBlock($block[2][0], $block[2][1], $block[2][2], $block[0], $block[1], false);
						}
					}
				}
				break;
			case 10:
			case 11:
				if(!$this->flowLavaOn($block, 0) or $block[0] === 11){
					$this->flowLavaOn($block, 2);
					$this->flowLavaOn($block, 3);
					$this->flowLavaOn($block, 4);
					$this->flowLavaOn($block, 5);
				}
				if($block[0] === 10){
					$drained = true;
					$level = $block[1] & 0x07;
					$up = $this->server->api->level->getBlockFace($block, BlockFace::UP);
					if($up[0] === 10 or $up[0] === 11){
						$drained = false;
					}else{
						$b = $this->server->api->level->getBlockFace($block, BlockFace::NORTH);
						if($b[0] === 11 or ($b[0] === 10 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
							$drained = false;
						}else{
							$b = $this->server->api->level->getBlockFace($block, BlockFace::SOUTH);
							if($b[0] === 11 or ($b[0] === 10 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
								$drained = false;
							}else{
								$b = $this->server->api->level->getBlockFace($block, BlockFace::EAST);
								if($b[0] === 11 or ($b[0] === 10 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
									$drained = false;
								}else{
									$b = $this->server->api->level->getBlockFace($block, BlockFace::WEST);
									if($b[0] === 11 or ($b[0] === 10 and ($b[1] & 0x08) === 0 and ($b[1] & 0x07) < $level)){
										$drained = false;
									}
								}
							}
						}
					}
					if($drained === true){
						++$level;
						if($level > 0x07){
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0] + 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0] - 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] + 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] - 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1] - 1,
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->api->level->setBlock($block[2][0], $block[2][1], $block[2][2], 0, 0, false);
						}else{
							$block[1] = ($block[1] & 0x08) | $level;
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0] + 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0] - 1,
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] + 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2] - 1,
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1] - 1,
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->schedule(20, array($this, "blockScheduler"), array(
								"x" => $block[2][0],
								"y" => $block[2][1],
								"z" => $block[2][2],
								"type" => BLOCK_UPDATE_NORMAL,
							));
							$this->server->api->level->setBlock($block[2][0], $block[2][1], $block[2][2], $block[0], $block[1], false);
						}
					}
				}

				break;
			case 74:
				if($type === BLOCK_UPDATE_SCHEDULED or $type === BLOCK_UPDATE_RANDOM){
					$changed = true;
					$this->server->api->level->setBlock($x, $y, $z, 73, $block[1], false);
					$type = BLOCK_UPDATE_WEAK;
				}
				break;
			case 73:
				if($type === BLOCK_UPDATE_NORMAL){
					$changed = true;
					$this->server->api->level->setBlock($x, $y, $z, 74, $block[1], false);
					$this->server->schedule(mt_rand(40, 100), array($this, "blockScheduler"), array(
						"x" => $x,
						"y" => $y,
						"z" => $z,
					));
					$type = BLOCK_UPDATE_WEAK;
				}
				break;
		}
		if($type === BLOCK_TYPE_SCHEDULED){
			$type = BLOCK_UPDATE_WEAK;
		}
		if($changed === true){
			$this->updateBlocksAround($x, $y, $z, $type);
		}
	}

	public function updateBlocksAround($x, $y, $z, $type){
		$this->updateBlock($x + 1, $y, $z, $type);
		$this->updateBlock($x, $y + 1, $z, $type);
		$this->updateBlock($x, $y, $z + 1, $type);
		$this->updateBlock($x - 1, $y, $z, $type);
		$this->updateBlock($x, $y - 1, $z, $type);
		$this->updateBlock($x, $y, $z - 1, $type);
	}
}