<?php

namespace ipad54\BedrockEditionClient\utils;

use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils as PMUtils;

class Utils{

	private const PATH = "vendor/pocketmine/bedrock-data/required_item_list.json";

	public static function makeItemTypeDictionary() : ItemTypeDictionary{
		$data = PMUtils::assumeNotFalse(file_get_contents(self::PATH), "Отсутствует требуемый файл ресурсов");
		$table = json_decode($data, true);
		if(!is_array($table)){
			throw new AssumptionFailedError("Недопустимый формат списка предметов");
		}

		$params = [];
		foreach($table as $name => $entry){
			if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
				throw new AssumptionFailedError("Недопустимый формат списка предметов");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
		}
		return new ItemTypeDictionary($params);
	}
}
