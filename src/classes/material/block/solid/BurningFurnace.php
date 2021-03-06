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

class BurningFurnaceBlock extends SolidBlock{
	public function __construct($meta = 0){
		parent::__construct(BURNING_FURNACE, $meta, "Burning Furnace");
		$this->isActivable = true;
	}
	public function place(BlockAPI $level, Item $item, Player $player, Block $block, Block $target, $face, $fx, $fy, $fz){
		if($block->inWorld === true){
			$faces = array(
				0 => 4,
				1 => 2,
				2 => 5,
				3 => 3,
			);
			$level->setBlock($block, $this->id, $faces[$player->entity->getDirection()]);
			return true;
		}
		return false;
	}	
	public function getDrops(Item $item, Player $player){
		if($item->isPickaxe() >= 1){
			return array(
				array(FURNACE, 0, 1),
			);
		}else{
			return array();
		}
	}
	
}