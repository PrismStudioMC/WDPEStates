<?php

namespace WDPEStates\entries;

use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use ReflectionClass;

class ItemsGenerator
{
    /**
     * Generates the payload for item entries to be sent over a socket connection.
     *
     * @return string
     * @throws \JsonException
     */
    public static function getPayload(): string
    {
        $dictionary = TypeConverter::getInstance()->getItemTypeDictionary();
        $reflect = new ReflectionClass($dictionary);

        $intToStringIdMap = $reflect->getProperty("intToStringIdMap")->getValue($dictionary);
        $stringToIntMap = $reflect->getProperty("stringToIntMap")->getValue($dictionary);
        $itemTypes = [];

        /** @var ItemTypeEntry $entry */
        foreach ($reflect->getProperty("itemTypes")->getValue($dictionary) as $entry) {
            $itemTypes[] = [
                "stringId" => $entry->getStringId(),
                "numericId" => $entry->getNumericId(),
                "componentBased" => $entry->isComponentBased(),
                "version" => $entry->getVersion(),
                "componentNbt" => base64_encode($entry->getComponentNbt()->getEncodedNbt()),
            ];
        }

        return json_encode([
            "intToStringIdMap" => $intToStringIdMap,
            "stringToIntMap" => $stringToIntMap,
            "itemTypes" => $itemTypes
        ], JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    public static function parsePayload(string $data): int
    {
        $values = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        $intToStringIdMap = $values["intToStringIdMap"];
        $stringToIntMap = $values["stringToIntMap"];
        $itemTypes = [];

        foreach ($values["itemTypes"] as $data) {
            $itemTypes[] = new ItemTypeEntry(
                $data["stringId"],
                $data["numericId"],
                $data["componentBased"],
                $data["version"],
                new CacheableNbt((new NetworkNbtSerializer())->read(base64_decode($data["componentNbt"]))->getTag())
            );
        }

        $dictionary = TypeConverter::getInstance()->getItemTypeDictionary();
        $reflect = new ReflectionClass($dictionary);

        $reflect->getProperty("intToStringIdMap")->setValue($dictionary, $intToStringIdMap);
        $reflect->getProperty("stringToIntMap")->setValue($dictionary, $stringToIntMap);
        $reflect->getProperty("itemTypes")->setValue($dictionary, $itemTypes);

        return count($itemTypes);
    }
}
