<?php

namespace WDPEStates;

use Logger;
use pmmp\thread\ThreadSafeArray;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\MainLogger;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Terminal;
use pocketmine\utils\Timezone;
use WDPEStates\events\SocketPayloadReceiveEvent;
use WDPEStates\listener\DownstreamEventListener;
use WDPEStates\listener\UpstreamEventListener;
use WDPEStates\socket\ClientSocketThread;
use WDPEStates\socket\ServerSocketThread;
use WDPEStates\socket\SocketSettings;

class Loader extends PluginBase
{
    use SingletonTrait;

    public Logger $socketLogger;
    private ServerSocketThread|ClientSocketThread $socketThread;
    private SocketSettings $socketSettings;
    public int $sleeperId;
    public ThreadSafeArray $in;
    public ThreadSafeArray $out;
    public ThreadSafeArray $state;

    public array $blockPaletteEntries = [];

    /**
     * Load the configuration and initialize socket settings.
     *
     * @return void
     */
    protected function onLoad(): void
    {
        self::setInstance($this);
        $this->saveDefaultConfig();
        $this->socketSettings = SocketSettings::fromArray($this->getConfig()->getAll());
    }

    /**
     * Start the socket thread when the plugin is enabled.
     *
     * @return void
     * @throws \DateInvalidTimeZoneException
     */
    protected function onEnable(): void
    {
        $entry = $this->getServer()->getTickSleeper()->addNotifier($this->handleThread(...));
        $this->sleeperId = $entry->getNotifierId();

        $this->in = new ThreadSafeArray();
        $this->out = new ThreadSafeArray();
        $this->state = new ThreadSafeArray();

        if ($this->socketSettings->role == "server") {
            $this->socketLogger = new MainLogger(null, Terminal::hasFormattingCodes(), "WDPEStates (SERVER)", new \DateTimeZone(Timezone::get()), false, null);
            $this->socketThread = new ServerSocketThread(
                $entry,
                $this->in,
                $this->out,
                $this->state,
                $this->socketSettings->serverHost,
                $this->socketSettings->serverPort,
                $this->socketSettings->maxConnections,
                $this->socketSettings->maxMessageSize
            );
            $this->getServer()->getPluginManager()->registerEvents(new UpstreamEventListener($this), $this);
        } else {
            $this->socketLogger = new MainLogger(null, Terminal::hasFormattingCodes(), "WDPEStates (CLIENT)", new \DateTimeZone(Timezone::get()), false, null);
            $this->socketThread = new ClientSocketThread(
                $entry,
                $this->in,
                $this->out,
                $this->state,
                $this->socketSettings->serverHost,
                $this->socketSettings->serverPort,
                $this->socketSettings->clientPort,
                $this->socketSettings->reconnectInterval,
            );
            $this->getServer()->getPluginManager()->registerEvents(new DownstreamEventListener($this), $this);
        }

        $this->socketThread->start();
    }

    /**
     * Gracefully stop the socket thread when the plugin is disabled.
     *
     * @return void
     */
    protected function onDisable(): void
    {
        if ($this->socketSettings->role == "client") {
            $this->sendPayload(['type' => 'shutdown', 'data' => [
                "host" => $this->getServer()->getIp(),
                "port" => $this->getServer()->getPort(),
            ]]);
        }

        $this->socketThread->quit(); // Gracefully stop the thread
    }

    /**
     * Get the socket settings.
     *
     * @return SocketSettings
     */
    public function getSocketSettings(): SocketSettings
    {
        return $this->socketSettings;
    }

    /**
     * Handle incoming messages from the socket thread.
     *
     * @return void
     */
    public function handleThread(): void
    {
        while (($raw = $this->out->shift()) !== null) {
            try {
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->getLogger()->error("Failed to decode payload: " . $e->getMessage());
                continue;
            }

            if (!is_array($payload)) {
                $this->getLogger()->error("Invalid payload format received.");
                continue;
            }

            $this->handlePayload($payload);
        }
    }

    /**
     * Check if there is an upstream connection (only valid in client mode)
     *
     * @return bool
     */
    public function hasUpstreamConnection(): bool
    {
        return (bool)$this->state['connected'] ?? false;
    }

    /**
     * Check if there is at least one downstream connection (only valid in server mode)
     *
     * @return bool
     */
    public function hasDownstreamConnection(): bool
    {
        return ($this->state['connected_count'] ?? 0) > 0;
    }

    /**
     * Handle incoming payloads from the socket thread.
     *
     * @param array $payload
     * @return void
     */
    private function handlePayload(array $payload): void
    {
        $type = $payload['type'] ?? null;
        if ($type === 'log') {
            $level = $payload['level'] ?? 'info';
            $message = $payload['msg'] ?? '';
            $ctx = $payload['ctx'] ?? [];

            $this->socketLogger->log($level, $message);
        } else {
            $ev = new SocketPayloadReceiveEvent($payload);
            $ev->call();
        }
    }

    /**
     * Send one or more payloads to the socket thread.
     *
     * @param array ...$payloads
     * @return void
     */
    public function sendPayload(array ...$payloads): void
    {
        if ($this->socketSettings->role !== "server" && !($this->state['connected'] ?? false)) {
            $this->getLogger()->warning("Downstream not connected; payload dropped");
            return;
        }

        foreach ($payloads as $payload) {
            try {
                $stringify = json_encode($payload, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->getLogger()->error("Failed to encode payload: " . $e->getMessage());
                continue;
            }

            $this->in[] = $stringify;
        }
    }
}
