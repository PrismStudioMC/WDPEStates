<?php

namespace WDPEStates\events;

use pocketmine\event\Event;

class SocketPayloadReceiveEvent extends Event
{
    public function __construct(
        private readonly array $payload
    ) {
    }

    /**
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
