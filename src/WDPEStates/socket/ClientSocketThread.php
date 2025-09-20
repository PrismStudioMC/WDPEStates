<?php

declare(strict_types=1);

namespace WDPEStates\socket;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;

class ClientSocketThread extends Thread
{
    private string $remoteHost;
    private int $remotePort;
    private int $localPort;
    private int $reconnectInterval;

    /**
     * Constructor for the ClientSocketThread.
     *
     * @param SleeperHandlerEntry $sleeperEntry
     * @param ThreadSafeArray $in
     * @param ThreadSafeArray $out
     * @param ThreadSafeArray $state
     * @param string $remoteHost
     * @param int $remotePort
     * @param int $localPort
     * @param int $reconnectInterval
     */
    public function __construct(
        public SleeperHandlerEntry $sleeperEntry,
        private ThreadSafeArray    $in,
        private ThreadSafeArray    $out,
        private ThreadSafeArray    $state,
        string                     $remoteHost,
        int                        $remotePort,
        int                        $localPort = -1,
        int                        $reconnectInterval = 5,
    ) {
        $this->remoteHost = $remoteHost;
        $this->remotePort = $remotePort;
        $this->localPort = $localPort;
        $this->reconnectInterval = max(1, $reconnectInterval);
    }

    /**
     * The main execution loop of the client socket thread.
     *
     * @return void
     */
    protected function onRun(): void
    {
        $notifier = $this->sleeperEntry->createNotifier();

        $emit = function (string $level, string $message) use ($notifier): void {
            $this->out[] = json_encode([
                'type' => 'log',
                'level' => $level,
                'msg' => $message
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $notifier->wakeupSleeper();
        };

        $this->state['connected'] = false;
        while (!$this->isKilled) {
            $context = stream_context_create();
            if ($this->localPort !== -1) {
                stream_context_set_option($context, 'socket', 'bindto', "0.0.0.0:{$this->localPort}");
            }

            $addr = "tcp://{$this->remoteHost}:{$this->remotePort}";
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client($addr, $errno, $errstr, 3.0, STREAM_CLIENT_CONNECT, $context);
            if ($sock === false) {
                $this->state['connected'] = false;
                $emit('error', "Connection failed to {$addr}: {$errstr} (errno={$errno})");
                $this->sleepSeconds($this->reconnectInterval);
                continue;
            }

            stream_set_blocking($sock, false);
            $this->state['connected'] = true;
            $emit('info', "Connected to {$this->remoteHost}:{$this->remotePort}" . ($this->localPort !== -1 ? " (local={$this->localPort})" : ""));
            $this->out[] = json_encode([
                'type' => 'ready',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $notifier->wakeupSleeper();

            while (!$this->isKilled && is_resource($sock)) {
                $read = [$sock];
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 20000);

                while (true) {
                    $chunk = @fread($sock, 8192);

                    if ($chunk === false) {
                        $emit('warning', "Read error, dropping connection");
                        @fclose($sock);
                        $sock = null;
                        break;
                    }
                    if ($chunk === '' || $chunk === null) {
                        break;
                    }

                    $this->out[] = $chunk;
                    $notifier->wakeupSleeper();

                    if (strlen($chunk) < 8192) {
                        break;
                    }
                }

                while (is_string(($raw = $this->in->shift()))) {
                    $lenHdr = $this->packLen(strlen($raw));
                    if (!$this->writeAll($sock, $lenHdr) || !$this->writeAll($sock, $raw)) {
                        $emit('warning', "Write error, dropping connection");
                        @fclose($sock);
                        $sock = null;
                        break;
                    }
                }

                if (!is_resource($sock) || @feof($sock)) {
                    $emit('warning', "Remote server closed the connection");
                    if (is_resource($sock)) {
                        @fclose($sock);
                    }
                    $sock = null;
                    break;
                }
            }

            $this->state['connected'] = false;
            $emit('info', "Reconnecting in {$this->reconnectInterval}s…");
            $this->sleepSeconds($this->reconnectInterval);
        }

        $this->state['connected'] = false;
        $emit('info', "Client stopped");
    }

    /**
     * Sleep for the given number of seconds, but wake up if the thread is killed.
     *
     * @param int $sec
     * @return void
     */
    private function sleepSeconds(int $sec): void
    {
        $end = microtime(true) + $sec;
        while (microtime(true) < $end && !$this->isKilled) {
            usleep(100_000);
        }
    }

    /**
     * Write all data to the socket, handling partial writes.
     * @param $sock
     * @param string $data
     * @return bool
     */
    private function writeAll($sock, string $data): bool
    {
        $off = 0;
        $len = strlen($data);
        while ($off < $len) {
            $n = @fwrite($sock, substr($data, $off));
            if ($n === false) {
                return false;
            }
            if ($n === 0) {
                usleep(1000);
                continue;
            }
            $off += $n;
        }
        return true;
    }

    /**
     * Pack length as 4 octets big-endian
     *
     * @param int $n
     * @return string
     */
    private function packLen(int $n): string // 4 octets big-endian
    {
        return pack('N', $n);
    }
}
