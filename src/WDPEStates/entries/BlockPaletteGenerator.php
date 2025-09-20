<?php

namespace WDPEStates\entries;

use customiesdevs\customies\block\CustomiesBlockFactory;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;

class BlockPaletteGenerator
{
    /**
     * @return string
     * @throws \JsonException
     */
    public static function getPayload(): string
    {
        $array = array_map(function ($entry) {
            return [
                "name" => $entry->getName(),
                "states" => base64_encode($entry->getStates()->getEncodedNbt())
            ];
        }, CustomiesBlockFactory::getInstance()->getBlockPaletteEntries()); // if you don't use customies adapt it to your block palette source

        return json_encode($array, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $data
     * @return array
     * @throws \JsonException
     */
    public static function parsePayload(string $data): array
    {
        $values = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        $entries = [];
        foreach ($values as $value) {
            $entries[] = new BlockPaletteEntry($value["name"], new CacheableNbt((new NetworkNbtSerializer())->read(base64_decode($value["states"]))->getTag()));
        }

        return $entries;
    }
}
