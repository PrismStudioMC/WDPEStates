# WDPEStates

A powerful PocketMine-MP plugin that enables real-time synchronization of game state data between multiple server instances through socket communication. This plugin is particularly useful for maintaining consistent block palettes, item types, and entity data across a distributed server network.

## Features

- **Real-time State Synchronization**: Keep block palettes, item types, and entity data synchronized across multiple server instances
- **Dual Mode Operation**: Supports both server and client modes for flexible network topologies
- **Thread-Safe Communication**: Uses separate threads for socket communication to prevent server lag
- **Automatic Reconnection**: Client instances automatically reconnect to server instances if connection is lost
- **Custom Block Support**: Full support for custom blocks and items through Customies integration
- **Event-Driven Architecture**: Extensible event system for custom payload handling

## Architecture

### Server Mode
- Acts as a central hub that receives and distributes state data
- Manages multiple client connections (configurable limit)
- Handles incoming player connections only when downstream clients are connected
- Automatically kicks players if no downstream connections are available

### Client Mode
- Connects to a server instance to receive state updates
- Automatically sends local state data when connection is established
- Handles incoming player connections only when upstream server is connected
- Automatically kicks players if upstream connection is lost

## Installation

1. Download the plugin from the releases page
2. Place the `WDPEStates.phar` file in your server's `plugins` folder
3. Configure the plugin by editing `resources/config.yml`
4. Restart your server

## Configuration

The plugin uses a YAML configuration file located at `resources/config.yml`:

```yaml
socket:
  role: "server" # "server" or "client"

  server: # server settings
    host: "127.0.0.1"
    port: 300
    max_connections: 5
    max_message_size: 1048576 # 1 MB

  client: # client settings
    host: "127.0.0.1"
    port: -1 # -1 for auto port assignment
    reconnect_interval: 5 # seconds
```

### Configuration Options

#### Server Mode
- `host`: IP address to bind the server socket to
- `port`: Port number for the server socket
- `max_connections`: Maximum number of client connections allowed
- `max_message_size`: Maximum size of messages in bytes (default: 1MB)

#### Client Mode
- `host`: IP address of the server to connect to
- `port`: Local port to bind to (-1 for automatic assignment)
- `reconnect_interval`: Time in seconds between reconnection attempts

## Usage

### Setting Up a Server-Client Network

1. **Configure the Server Instance**:
   - Set `role: "server"` in the config
   - Configure server host and port
   - Start the server instance

2. **Configure Client Instances**:
   - Set `role: "client"` in the config
   - Set the server host and port to match the server instance
   - Start the client instances

3. **State Synchronization**:
   - The server will automatically receive state data from clients
   - Clients will automatically send their state data when connected
   - All instances will maintain synchronized block palettes, item types, and entity data

### Supported State Data

The plugin synchronizes three types of game state data:

1. **Block Palette Entries**: Custom block definitions and states
2. **Item Type Entries**: Item type definitions and properties
3. **Entity Entries**: Entity type definitions and identifiers

## API Usage

### Sending Custom Payloads

You can send custom payloads through the socket connection:

```php
$loader = $this->getServer()->getPluginManager()->getPlugin("WDPEStates");
if ($loader instanceof \WDPEStates\Loader) {
    $loader->sendPayload([
        "type" => "custom_data",
        "data" => "your_custom_data_here"
    ]);
}
```

### Listening for Payloads

Listen for incoming payloads using the `SocketPayloadReceiveEvent`:

```php
use WDPEStates\events\SocketPayloadReceiveEvent;

public function onPayloadReceive(SocketPayloadReceiveEvent $event): void
{
    $payload = $event->getPayload();
    $type = $payload["type"] ?? null;
    
    if ($type === "custom_data") {
        // Handle custom payload
        $data = $payload["data"] ?? null;
        // Process your custom data
    }
}
```

### Checking Connection Status

```php
$loader = $this->getServer()->getPluginManager()->getPlugin("WDPEStates");
if ($loader instanceof \WDPEStates\Loader) {
    // Check if upstream connection exists (client mode)
    if ($loader->hasUpstreamConnection()) {
        // Connected to server
    }
    
    // Check if downstream connections exist (server mode)
    if ($loader->hasDownstreamConnection()) {
        // Has connected clients
    }
}
```

## Requirements

- PocketMine-MP 5.11.0 or higher
- PHP 8.0 or higher
- Customies plugin (for custom block support)

## Dependencies

- **Customies**: Required for custom block palette generation
- **pmmp/thread**: For thread-safe socket communication

## Network Topology Examples

### Single Server, Multiple Clients
```
[Server Instance] <- [Client Instance 1]
                  <- [Client Instance 2]
                  <- [Client Instance 3]
```

### Hub and Spoke Model
```
[Main Server] <- [Game Server 1]
              <- [Game Server 2]
              <- [Lobby Server]
```

## Troubleshooting

### Common Issues

1. **Connection Refused**: Ensure the server is running and the port is not blocked by firewall
2. **Players Kicked**: Check if the appropriate connections (upstream/downstream) are established
3. **State Not Syncing**: Verify that both server and client instances are running the same plugin version

### Debug Information

The plugin provides detailed logging through the socket logger. Check your server logs for messages prefixed with `[WDPEStates (SERVER)]` or `[WDPEStates (CLIENT)]`.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.