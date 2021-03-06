<?php

namespace Kelunik\Demo;

use Aerys\Request;
use Aerys\Response;
use Aerys\Websocket;
use Aerys\Websocket\Endpoint;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Chat implements Websocket {
    /** @var Endpoint */
    private $endpoint;
    private $connections;
    private $ips;

    /**
     * The hostname that will be allowed to connect.
     *
     * @var string
     */
    private $hostname;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(string $hostname, LoggerInterface $logger = null)
    {
        $this->hostname = $hostname;
        $this->logger = $logger ?? new NullLogger();
    }

    public function onStart(Websocket\Endpoint $endpoint) {
        // On server startup we get an instance of Aerys\Websocket\Endpoint, so we can send messages to clients.
        $this->endpoint = $endpoint;
        $this->connections = [];
        $this->ips = [];
    }

    public function onHandshake(Request $request, Response $response) {
        // During handshakes, you should always check the origin header, otherwise any site will
        // be able to connect to your endpoint. Websockets are not restricted by the same-origin-policy!
        $origin = $request->getHeader("origin");

        $expectedOrigin = "http://{$this->hostname}";

        if ($origin !== $expectedOrigin) {
            $response->setStatus(403);
            $response->end("<h1>origin not allowed</h1>");

            return null;
        }

        // returned values will be passed to onOpen, that way you can pass cookie values or the whole request object.
        return $request->getConnectionInfo()["client_addr"];
    }

    public function onOpen(int $clientId, $handshakeData) {
        // We keep one map for all connected clients.
        $this->connections[$clientId] = $handshakeData;
        // And another one for multiple clients with the same IP.
        $this->ips[$handshakeData][$clientId] = true;
    }

    public function onData(int $clientId, Websocket\Message $msg) {
        // yielding $msg buffers the complete payload into a single string. For very large payloads, you may want to
        // stream those instead of buffering them.
        $body = yield $msg;

        // We use the IP as name for this simple chat app.
        $ip = $this->connections[$clientId];

        // If someone mentions an IP, we send the message only to clients with that IP and the sender itself.
        if (preg_match("~@(\\d+\\.\\d+\\.\\d+\\.\\d+)\\b~", $body, $match)) {
            list($all, $receiver) = $match;

            $payload = $ip . " (private): " . substr($body, strlen($all));
            $clients = array_keys($this->ips[$receiver] ?? []);

            if (!empty($clients)) {
                $this->endpoint->send($clients, $payload);
            }

            $this->endpoint->send($clientId, $payload);
        } else {
            $payload = $ip . ": " . $body;
            $this->endpoint->send(null, $payload);
        }
    }

    public function onClose(int $clientId, int $code, string $reason) {
        // Always clean up data when clients disconnect, otherwise we'll leak memory.
        $ip = $this->connections[$clientId];

        unset($this->connections[$clientId]);
        unset($this->ips[$ip][$clientId]);

        if (empty($this->ips[$ip])) {
            unset($this->ips[$ip]);
        }
    }

    public function onStop() {
        // intentionally left blank
    }
}
