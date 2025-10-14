<?php

namespace WDPEStates\socket;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\Thread;

class ServerSocketThread extends Thread
{
    private string $host;
    private int $port;
    private int $maxConnections;
    private int $maxMessageSize;

    /**
     * Constructor for the ServerSocketThread.
     *
     * @param SleeperHandlerEntry $sleeperEntry
     * @param ThreadSafeArray $in
     * @param ThreadSafeArray $out
     * @param ThreadSafeArray $state
     * @param string $host
     * @param int $port
     * @param int $maxConnections
     * @param int $maxMessageSize
     */
    public function __construct(
        public SleeperHandlerEntry $sleeperEntry,
        private ThreadSafeArray    $in,
        private ThreadSafeArray    $out,
        private ThreadSafeArray    $state,
        string                     $host,
        int                        $port,
        int                        $maxConnections,
        int                        $maxMessageSize
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->maxConnections = $maxConnections;
        $this->maxMessageSize = $maxMessageSize;
    }

    /**
     * The main execution loop of the server socket thread.
     *
     * @return void
     */
    protected function onRun(): void
    {
        $notifier = $this->sleeperEntry->createNotifier();

        $emit = function (string $level, string $message) use ($notifier): void {
            $this->out[] = json_encode([
                'type'  => 'log',
                'level' => $level,
                'msg'   => $message
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $notifier->wakeupSleeper();
        };

        $addr = "tcp://{$this->host}:{$this->port}";
        $server = @stream_socket_server($addr, $errno, $errstr);
        if ($server === false) {
            $emit('error', "Bind failed on {$addr}: {$errstr} (errno={$errno})");
            return;
        }

        stream_set_blocking($server, false);
        $emit('info', "Listening on {$this->host}:{$this->port}");

        /**
         * @var array<int,array{
         *   sock: resource,
         *   readBuf: string,
         *   writeBuf: string,
         *   peer: string
         * }> $clients
         */
        $clients = [];

        while (!$this->isKilled) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                if (count($clients) >= $this->maxConnections) {
                    @fwrite($client, json_encode([
                        'type'  => 'log',
                        'level' => 'error',
                        'msg'   => "Connection rejected: too many connections (current=" . count($clients) . ", max={$this->maxConnections})"
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    @fclose($client);
                    $emit('warning', "Connection rejected: too many connections (current=" . count($clients) . ", max={$this->maxConnections})");
                } else {
                    stream_set_blocking($client, false);
                    $id = (int)$client;
                    $peer = @stream_socket_get_name($client, true);
                    if ($peer === false) {
                        $peer = 'unknown';
                    }

                    $clients[$id] = [
                        'sock'     => $client,
                        'readBuf'  => '',
                        'writeBuf' => '',
                        'peer'     => $peer
                    ];
                    $this->state['connected_count'] = count($clients);
                    $emit('info', "Client connected: id={$id}, remote={$peer}");
                }
            }

            $read = [];
            $write = [];
            foreach ($clients as $c) {
                $read[] = $c['sock'];
                if ($c['writeBuf'] !== "") {
                    $write[] = $c['sock'];
                }
            }
            $except = null;

            if (!empty($read) || !empty($write)) {
                @stream_select($read, $write, $except, 0, 20000);
            } else {
                $this->wait();
            }

            foreach ($read as $rs) {
                $id = (int)$rs;
                $peer = $clients[$id]['peer'] ?? 'unknown';

                $hdr = $this->readExact($rs, 4);
                if ($hdr === null) {
                    $this->dropClient($clients, $id);
                    $emit('warning', "Client dropped (read error header): id={$id}, remote={$peer}");
                    continue;
                }
                $len = $this->unpackLen($hdr);
                if ($len < 0 || ($this->maxMessageSize > 0 && $len > $this->maxMessageSize)) {
                    $this->dropClient($clients, $id);
                    $emit('warning', "Client dropped (invalid length {$len}): id={$id}, remote={$peer}");
                    continue;
                }

                $data = $this->readExact($rs, $len);
                if ($data === null) {
                    $this->dropClient($clients, $id);
                    $emit('warning', "Client dropped (read error body): id={$id}, remote={$peer}");
                    continue;
                }

                $this->out[] = $data;
                $notifier->wakeupSleeper();

                if (!is_resource($rs) || @feof($rs)) {
                    $this->dropClient($clients, $id);
                    $emit('info', "Client disconnected: id={$id}, remote={$peer}");
                }
            }

            foreach ($write as $ws) {
                $id = (int)$ws;
                $wbuf = &$clients[$id]['writeBuf'];
                if ($wbuf === "") {
                    continue;
                }
                $n = @fwrite($ws, $wbuf);
                if ($n === false) {
                    $peer = $clients[$id]['peer'] ?? 'unknown';
                    $this->dropClient($clients, $id);
                    $emit('warning', "Client dropped (write error): id={$id}, remote={$peer}");
                    continue;
                }
                if ($n > 0) {
                    $wbuf = (string)substr($wbuf, $n);
                }
            }

            while (($msg = $this->in->shift()) !== null) {
                if (!is_string($msg) || $msg === "") {
                    continue;
                }
                $frame = $msg;
                foreach ($clients as $cid => $_) {
                    $clients[$cid]['writeBuf'] .= $frame;
                }
            }
        }

        foreach ($clients as $c) {
            @fclose($c['sock']);
        }
        if ($server !== null) {
            @fclose($server);
        }
        $emit('info', "Server stopped");
    }

    /**
     * Drop a client connection and clean up resources.
     *
     * @param array<int,array{sock:resource,readBuf:string,writeBuf:string,peer:string}> $clients
     */
    private function dropClient(array &$clients, int $id): void
    {
        if (!isset($clients[$id])) {
            return;
        }
        @fclose($clients[$id]['sock']);
        unset($clients[$id]);
        $this->state['connected_count'] = count($clients);
    }

    /**
     * Read an exact number of bytes from the socket.
     *
     * @param $sock
     * @param int $need
     * @return string|null
     */
    private function readExact($sock, int $need): ?string
    {
        $buf = '';
        $deadline = microtime(true) + 0.2;

        while (strlen($buf) < $need) {
            $chunk = @fread($sock, $need - strlen($buf));

            if ($chunk === false) {
                return null;
            }
            if ($chunk === '' || $chunk === null) {
                if (@feof($sock)) {
                    return null;
                }
                if (microtime(true) > $deadline) {
                    return null;
                }
                $this->wait();
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Pack a length into a 4-byte big-endian string.
     *
     * @param string $hdr
     * @return int
     */
    private function unpackLen(string $hdr): int // 4 octets big-endian
    {
        $arr = unpack('Nlen', $hdr);
        return (int)($arr['len'] ?? 0);
    }
}
