<?php

namespace WDPEStates\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\scheduler\ClosureTask;
use WDPEStates\entries\BlockPaletteGenerator;
use WDPEStates\entries\EntitesGenerator;
use WDPEStates\entries\ItemsGenerator;
use WDPEStates\events\SocketPayloadReceiveEvent;
use WDPEStates\Loader;

class DownstreamEventListener implements Listener
{
    public function __construct(
        private Loader $loader
    ) {
    }

    /**
     * @param PlayerPreLoginEvent $ev
     * @return void
     */
    public function handleLogin(PlayerPreLoginEvent $ev): void
    {
        if (!$this->loader->hasUpstreamConnection()) {
            $ev->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_PLUGIN, "§cThe server is currently restarting, please try again later.");
        }
    }

    /**
     * @param SocketPayloadReceiveEvent $ev
     * @return void
     */
    public function onPayloadReceive(SocketPayloadReceiveEvent $ev): void
    {
        $payload = $ev->getPayload();
        $type = $payload["type"] ?? null;

        if ($type === null) {
            return; // Invalid payload
        }

        switch ($type) {
            case "ready": {
                $this->loader->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->loader->sendPayload(
                    ["type" => "block_palette_entries", "data" => base64_encode(BlockPaletteGenerator::getPayload())],
                    ["type" => "entity_entries", "data" => base64_encode(EntitesGenerator::getPayload())],
                    ["type" => "item_entries", "data" => base64_encode(ItemsGenerator::getPayload())]
                )), 20);
                break;
            }
        }
    }
}
