<?php

namespace WDPEStates\socket;

final class SocketSettings
{
    public string $role = "server"; // "server"|"client"

    public string $serverHost = "127.0.0.1";
    public int    $serverPort = 300;
    public int    $maxConnections = 5;
    public int    $maxMessageSize = 1048576; // 1MB

    public string $clientHost = "127.0.0.1";
    public int    $clientPort = -1; // local bind; -1 = auto
    public int    $reconnectInterval = 5; // seconds

    /** @param array<string,mixed> $cfg */
    public static function fromArray(array $cfg): self
    {
        $s = new self();
        $sock = $cfg['socket'] ?? [];

        $s->role = (string)($sock['role'] ?? $s->role);

        $srv = $sock['server'] ?? [];
        $s->serverHost = (string)($srv['host'] ?? $s->serverHost);
        $s->serverPort = (int)($srv['port'] ?? $s->serverPort);
        $s->maxConnections = max(1, (int)($srv['max_connections'] ?? $s->maxConnections));
        $s->maxMessageSize = max(1024, (int)($srv['max_message_size'] ?? $s->maxMessageSize));

        $cli = $sock['client'] ?? [];
        $s->clientHost = (string)($cli['host'] ?? $s->clientHost);
        $s->clientPort = (int)($cli['port'] ?? $s->clientPort);
        $s->reconnectInterval = max(1, (int)($cli['reconnect_interval'] ?? $s->reconnectInterval));

        return $s;
    }
}
