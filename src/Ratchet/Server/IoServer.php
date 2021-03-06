<?php
namespace Ratchet\Server;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ServerInterface;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server as Reactor;

/**
 * Creates an open-ended socket to listen on a port for incomming connections. 
 * Events are delegated through this to attached applications
 */
class IoServer {
    /**
     * @var React\EventLoop\LoopInterface
     */
    public $loop;

    /**
     * @var Ratchet\MessageComponentInterface
     */
    public $app;

    /**
     * Array of React event handlers
     * @var array
     */
    protected $handlers = array();

    /**
     * @param Ratchet\MessageComponentInterface The Ratchet application stack to host
     * @param React\Socket\ServerInterface The React socket server to run the Ratchet application off of
     * @param React\EventLoop\LoopInterface|null The React looper to run the Ratchet application off of
     */
    public function __construct(MessageComponentInterface $app, ServerInterface $socket, LoopInterface $loop = null) {
        gc_enable();
        set_time_limit(0);
        ob_implicit_flush();

        $this->loop = $loop;
        $this->app  = $app;

        $socket->on('connection', array($this, 'handleConnect'));

        $this->handlers['data']  = array($this, 'handleData');
        $this->handlers['end']   = array($this, 'handleEnd');
        $this->handlers['error'] = array($this, 'handleError');
    }

    /**
     * @param Ratchet\MessageComponentInterface The application that I/O will call when events are received
     * @param int The port to server sockets on
     * @param string The address to receive sockets on (0.0.0.0 means receive connections from any)
     * @return Ratchet\Server\IoServer
     */
    public static function factory(MessageComponentInterface $component, $port = 80, $address = '0.0.0.0') {
        $loop   = LoopFactory::create();
        $socket = new Reactor($loop);
        $socket->listen($port, $address);

        return new static($component, $socket, $loop);
    }

    /**
     * Run the application by entering the event loop
     * @throws RuntimeException If a loop was not previously specified
     */
    public function run() {
        if (null === $this->loop) {
            throw new \RuntimeException("A React Loop was not provided during instantiation");
        }

        $this->loop->run();
    }

    /**
     * Triggered when a new connection is received from React
     */
    public function handleConnect($conn) {
        $conn->decor = new IoConnection($conn);

        $conn->decor->resourceId    = (int)$conn->stream;
        $conn->decor->remoteAddress = $conn->getRemoteAddress();

        $this->app->onOpen($conn->decor);

        $conn->on('data', $this->handlers['data']);
        $conn->on('end', $this->handlers['end']);
        $conn->on('error', $this->handlers['error']);
    }

    /**
     * Data has been received from React
     * @param string
     * @param React\Socket\Connection
     */
    public function handleData($data, $conn) {
        try {
            $this->app->onMessage($conn->decor, $data);
        } catch (\Exception $e) {
            $this->handleError($e, $conn);
        }
    }

    /**
     * A connection has been closed by React
     * @param React\Socket\Connection
     */
    public function handleEnd($conn) {
        try {
            $this->app->onClose($conn->decor);
        } catch (\Exception $e) {
            $this->handleError($e, $conn);
        }

        unset($conn->decor);
    }

    /**
     * An error has occurred, let the listening application know
     * @param Exception
     * @param React\Socket\Connection
     */
    public function handleError(\Exception $e, $conn) {
        $this->app->onError($conn->decor, $e);
    }
}