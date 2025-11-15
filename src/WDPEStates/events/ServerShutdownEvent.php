<?php

namespace WDPEStates\events;

use pocketmine\event\Event;

class ServerShutdownEvent extends Event
{
    public function __construct(
        private string $host,
        private int $port,
    ) {
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }
}
