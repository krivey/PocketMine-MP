<?php

namespace pocketmine\inventory\json;

use pocketmine\crafting\json\ItemStackData;

final class CreativeItemData{
	/** @required */
	public ?ItemStackData $item;
	/** @required */
	public int $group_id;
}