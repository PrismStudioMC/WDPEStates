<?php

namespace WDPEStates\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Experiments;
use WDPEStates\entries\BlockPaletteGenerator;
use WDPEStates\entries\ItemsGenerator;
use WDPEStates\events\SocketPayloadReceiveEvent;
use WDPEStates\Loader;

class UpstreamEventListener implements Listener
{
    private Experiments $experiments;

    public function __construct(
        private Loader $loader
    ) {
        $this->experiments = new Experiments([
            // "data_driven_items" is required for custom blocks to render in-game. With this disabled, they will be
            // shown as the UPDATE texture block.
            "data_driven_items" => true,
            "wild_update" => true,
            "vanilla_experiments" => true,
            "upcoming_creator_features" => true,
            "spectator_mode" => true,
            "gametest" => true,
            "experimental_molang_features" => true,
            "data_driven_biomes" => true,
        ], true);
    }

    /**
     * @param PlayerPreLoginEvent $ev
     * @return void
     */
    public function handleLogin(PlayerPreLoginEvent $ev): void
    {
        if (!$this->loader->hasDownstreamConnection()) {
            $ev->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "§cThe server is currently restarting, please try again later.");
        }
    }

    /**
     * @param DataPacketSendEvent $event
     * @return void
     */
    public function onDataPacketSend(DataPacketSendEvent $event): void
    {
        foreach ($event->getPackets() as $packet) {
            if ($packet instanceof StartGamePacket) {
                $packet->levelSettings->experiments = $this->experiments;
                $packet->blockPalette = $this->loader->blockPaletteEntries;
            } elseif ($packet instanceof ResourcePackStackPacket) {
                $packet->experiments = $this->experiments;
            }
        }
    }

    /**
     * @param SocketPayloadReceiveEvent $ev
     * @return void
     * @throws \ReflectionException
     */
    public function onPayloadReceive(SocketPayloadReceiveEvent $ev): void
    {
        $payload = $ev->getPayload();
        $type = $payload["type"] ?? null;

        if ($type === null) {
            return; // Invalid payload
        }

        $data = $payload["data"] ?? null;
        if ($data === null) {
            return; // No data to process
        }

        switch ($type) {
            case "block_palette_entries":
                {
                    $this->loader->blockPaletteEntries = $entries = BlockPaletteGenerator::parsePayload(base64_decode($data));
                    $this->loader->socketLogger->info("Block palette entries updated. Total entries: " . count($entries));
                    break;
                }
            case "entity_entries":
                {
                    StaticPacketCache::getInstance()
                        ->getAvailableActorIdentifiers()
                        ->identifiers = new CacheableNbt((new NetworkNbtSerializer())->read(base64_decode($data))->getTag());
                    $this->loader->socketLogger->info("Entity entries updated.");
                    break;
                }
            case "item_entries":
                {
                    $this->loader->socketLogger->info("Item entries updated. Total entries: " . ItemsGenerator::parsePayload(base64_decode($data)));
                    break;
                }
        }
    }
}
