<?php

namespace pocketmine\inventory\data;

use pocketmine\item\Item;

final class CreativeItemGroup{
	public int $categoryId;
	public string $categoryName;
	public ?Item $icon;
}