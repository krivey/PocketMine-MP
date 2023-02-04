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

namespace pocketmine\network\mcpe\serializer;

use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use function count;
use function get_class;

final class ChunkSerializer{
	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the number of subchunks that will be sent from the given chunk.
	 * Chunks are sent in a stack, so every chunk below the top non-empty one must be sent.
	 */
	public static function getSubChunkCount(Chunk $chunk) : int{
		for($y = Chunk::MAX_SUBCHUNK_INDEX, $count = count($chunk->getSubChunks()); $y >= Chunk::MIN_SUBCHUNK_INDEX; --$y, --$count){
			if($chunk->getSubChunk($y)->isEmptyFast()){
				continue;
			}
			return $count;
		}

		return 0;
	}

	/**
	 * @return string[]
	 */
	public static function serializeSubChunks(Chunk $chunk, RuntimeBlockMapping $blockMapper, PacketSerializerContext $encoderContext, int $mappingProtocol) : array
	{
		$stream = PacketSerializer::encoder($encoderContext);
		$stream->setProtocolId($mappingProtocol);
		$subChunks = [];

		$subChunkCount = self::getSubChunkCount($chunk);
		for($y = Chunk::MIN_SUBCHUNK_INDEX, $writtenCount = 0; $writtenCount < $subChunkCount; ++$y, ++$writtenCount){
			$subChunkStream = clone $stream;
			self::serializeSubChunk($chunk->getSubChunk($y), $blockMapper, $subChunkStream, false);
			$subChunks[] = $subChunkStream->getBuffer();
		}

		return $subChunks;
	}

	public static function serializeFullChunk(Chunk $chunk, RuntimeBlockMapping $blockMapper, PacketSerializerContext $encoderContext, int $mappingProtocol, ?string $tiles = null) : string{
		$stream = PacketSerializer::encoder($encoderContext);
		$stream->setProtocolId($mappingProtocol);

		foreach(self::serializeSubChunks($chunk, $blockMapper, $encoderContext, $mappingProtocol) as $subChunk){
			$stream->put($subChunk);
		}

		self::serializeBiomes($chunk, $stream);
		self::serializeChunkData($chunk, $stream, $tiles);

		return $stream->getBuffer();
	}

	public static function serializeBiomes(Chunk $chunk, PacketSerializer $stream) : void{
		$biomeIdMap = LegacyBiomeIdToStringIdMap::getInstance();
		//all biomes must always be written :(
		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
			self::serializeBiomePalette($chunk->getSubChunk($y)->getBiomeArray(), $biomeIdMap, $stream);
		}
	}

	public static function serializeBorderBlocks(PacketSerializer $stream) : void {
		$stream->putByte(0); //border block array count
		//Border block entry format: 1 byte (4 bits X, 4 bits Z). These are however useless since they crash the regular client.
	}

	public static function serializeChunkData(Chunk $chunk, PacketSerializer $stream, ?string $tiles = null) : void{
		self::serializeBorderBlocks($stream);

		if($tiles !== null){
			$stream->put($tiles);
		}else{
			$stream->put(self::serializeTiles($chunk, $stream->getProtocolId()));
		}
	}

	public static function serializeSubChunk(SubChunk $subChunk, RuntimeBlockMapping $blockMapper, PacketSerializer $stream, bool $persistentBlockStates) : void{
		$layers = $subChunk->getBlockLayers();
		$stream->putByte(8); //version

		$stream->putByte(count($layers));

		$blockStateDictionary = $blockMapper->getBlockStateDictionary();

		foreach($layers as $blocks){
			$bitsPerBlock = $blocks->getBitsPerBlock();
			$words = $blocks->getWordArray();
			$stream->putByte(($bitsPerBlock << 1) | ($persistentBlockStates ? 0 : 1));
			$stream->put($words);
			$palette = $blocks->getPalette();

			if($bitsPerBlock !== 0){
				//these LSHIFT by 1 uvarints are optimizations: the client expects zigzag varints here
				//but since we know they are always unsigned, we can avoid the extra fcall overhead of
				//zigzag and just shift directly.
				$stream->putUnsignedVarInt(count($palette) << 1); //yes, this is intentionally zigzag
			}
			if($persistentBlockStates){
				$nbtSerializer = new NetworkNbtSerializer();
				foreach($palette as $p){
					//TODO: introduce a binary cache for this
					$state = $blockStateDictionary->getDataFromStateId($blockMapper->toRuntimeId($p));
					if($state === null){
						$state = $blockMapper->getFallbackStateData();
					}

					$stream->put($nbtSerializer->write(new TreeRoot($state->toNbt())));
				}
			}else{
				foreach($palette as $p){
					$stream->put(Binary::writeUnsignedVarInt($blockMapper->toRuntimeId($p) << 1));
				}
			}
		}
	}

	private static function serializeBiomePalette(PalettedBlockArray $biomePalette, LegacyBiomeIdToStringIdMap $biomeIdMap, PacketSerializer $stream) : void{
		$biomePaletteBitsPerBlock = $biomePalette->getBitsPerBlock();
		$stream->putByte(($biomePaletteBitsPerBlock << 1) | 1); //the last bit is non-persistence (like for blocks), though it has no effect on biomes since they always use integer IDs
		$stream->put($biomePalette->getWordArray());

		//these LSHIFT by 1 uvarints are optimizations: the client expects zigzag varints here
		//but since we know they are always unsigned, we can avoid the extra fcall overhead of
		//zigzag and just shift directly.
		$biomePaletteArray = $biomePalette->getPalette();
		if($biomePaletteBitsPerBlock !== 0){
			$stream->putUnsignedVarInt(count($biomePaletteArray) << 1);
		}

		foreach($biomePaletteArray as $p){
			if($biomeIdMap->legacyToString($p) === null){
				//make sure we aren't sending bogus biomes - the 1.18.0 client crashes if we do this
				$p = BiomeIds::OCEAN;
			}
			$stream->put(Binary::writeUnsignedVarInt($p << 1));
		}
	}

	public static function serializeTiles(Chunk $chunk, int $mappingProtocol) : string{
		$stream = new BinaryStream();
		$nbtSerializer = new NetworkNbtSerializer();
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Spawnable){
				if($mappingProtocol === ProtocolInfo::PROTOCOL_1_19_10){
					//TODO: HACK! we send only the bare essentials to create a tile in the chunk itself, due to a bug in
					//1.19.10 which causes items in tiles (item frames, lecterns) to not load properly when they are sent in
					//a chunk via the classic chunk sending mechanism. We workaround this bug by sendingBlockActorDataPacket
					//in NetworkSession to set the actual tile properties after sending the LevelChunkPacket.
					$nbt = CompoundTag::create()
						->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(get_class($tile)))
						->setInt(Tile::TAG_X, $tile->getPosition()->getFloorX())
						->setInt(Tile::TAG_Y, $tile->getPosition()->getFloorY())
						->setInt(Tile::TAG_Z, $tile->getPosition()->getFloorZ());
					$stream->put($nbtSerializer->write(new TreeRoot($nbt)));
				}else{
					$stream->put($tile->getSerializedSpawnCompound()->getEncodedNbt());
				}
			}
		}

		return $stream->getBuffer();
	}
}
