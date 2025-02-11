<?php

namespace pocketmine\inventory\json;

use pocketmine\crafting\json\ItemStackData;

final class CreativeGroupData{
	/** @required */
	public int $category_id;
	/** @required */
	public string $category_name;
	/** @required */
	public ?ItemStackData $icon;
}