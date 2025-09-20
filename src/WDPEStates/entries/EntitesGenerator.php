<?php

namespace WDPEStates\entries;

use pocketmine\network\mcpe\cache\StaticPacketCache;

class EntitesGenerator
{
    /**
     * Generates the payload for entity entries to be sent over a socket connection.
     *
     * @return string
     */
    public static function getPayload(): string
    {
        return StaticPacketCache::getInstance()->getAvailableActorIdentifiers()->identifiers->getEncodedNbt();
    }
}
