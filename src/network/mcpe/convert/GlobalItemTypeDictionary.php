<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\ProtocolSingletonTrait;
use Symfony\Component\Filesystem\Path;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

final class GlobalItemTypeDictionary{
	use ProtocolSingletonTrait;

	private const PATHS = [
		ProtocolInfo::CURRENT_PROTOCOL => "",
		ProtocolInfo::PROTOCOL_1_19_40 => "-1.19.40",
		ProtocolInfo::PROTOCOL_1_19_0 => "-1.19.0",
		ProtocolInfo::PROTOCOL_1_18_30 => "-1.18.30",
		ProtocolInfo::PROTOCOL_1_18_10 => "-1.18.10",
		ProtocolInfo::PROTOCOL_1_18_0 => "-1.18.0",
	];

	private static function make(int $protocolId) : self{
		$data = Filesystem::fileGetContents(Path::join(\pocketmine\BEDROCK_DATA_PATH, 'required_item_list' . self::PATHS[$protocolId] . '.json'));
		$table = json_decode($data, true);
		if(!is_array($table)){
			throw new AssumptionFailedError("Invalid item list format");
		}

		$params = [];
		foreach($table as $name => $entry){
			if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
				throw new AssumptionFailedError("Invalid item list format");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
		}
		return new self(new ItemTypeDictionary($params));
	}

	public function __construct(
		private ItemTypeDictionary $dictionary
	){}

	public function getDictionary() : ItemTypeDictionary{ return $this->dictionary; }

	public static function convertProtocol(int $protocolId) : int{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_19_10 && $protocolId <= ProtocolInfo::PROTOCOL_1_19_40){
			return ProtocolInfo::PROTOCOL_1_19_40;
		}

		return $protocolId;
	}
}
