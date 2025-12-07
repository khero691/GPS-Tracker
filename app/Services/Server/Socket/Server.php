<?php declare(strict_types=1);

namespace App\Services\Server\Socket;

use Closure;
use Exception;
use Socket;
use stdClass;
use Throwable;
use App\Services\Monitoring\Performance;
use App\Services\Server\ServerAbstract;

class Server extends ServerAbstract
{
    /**
     * @const int
     */
    protected const SOCKET_TIMEOUT = 600;

    /**
     * @var ?\Socket
     */
    protected ?Socket $socket;

    /**
     * @var int
     */
    protected int $socketType = 1;

    /**
     * @var int
     */
    protected int $socketProtocol = 0;

    /**
     * @var array
     */
    protected array $clients = [];

    /**
     * @param string $type
     *
     * @return self
     */
    public function socketType(string $type): self
    {
        $this->socketType = match ($type) {
            'stream' => SOCK_STREAM,
            default => throw new Exception(sprintf('Invalid Socket Type %s', $type)),
        };

        return $this;
    }

    /**
     * @param string $protocol
     *
     * @return self
     */
    public function socketProtocol(string $protocol): self
    {
        $this->socketProtocol = match ($protocol) {
            'ip' => 0,
            'tcp' => 6,
            'udp' => 17,
            default => throw new Exception(sprintf('Invalid Socket Protocol %s', $protocol)),
        };

        return $this;
    }

    /**
     * @param \Closure $handler
     *
     * @return void
     */
    public function accept(Closure $handler): void
    {
        // Запускаем таймер для измерения производительности
        Performance::startTimer('server.accept');
        
        $this->create();
        $this->reuse();
        $this->bind();
        $this->listen();

        set_time_limit(0);

        $this->gracefulShutdown();
        
        // Настраиваем периодическое логирование метрик
        register_shutdown_function(function () {
            Performance::logMetrics();
        });

        try {
            $this->read($handler);
        } catch (Throwable $e) {
            $this->error($e);
            $this->stop();
        }
    }

    /**
     * @param \Closure $handler
     *
     * @return void
     */
    protected function read(Closure $handler): void
    {
        do {
            // Увеличиваем время сна для снижения нагрузки на CPU
            usleep(10000); // 10ms вместо 1ms

            $this->clientFilter();

            $sockets = $this->clientSockets();
            
            // Если нет активных сокетов, увеличиваем время ожидания
            if (empty($sockets)) {
                usleep(50000); // 50ms
                continue;
            }

            if ($this->select($sockets) === 0) {
                continue;
            }

            if (in_array($this->socket, $sockets)) {
                $sockets = $this->clientAdd($sockets);
            }

            foreach ($sockets as $socket) {
                $this->clientRead($socket, $handler);
            }
        } while (true);
    }

    /**
     * @param array &$sockets
     *
     * @return int
     */
    protected function select(array &$sockets): int
    {
        $write = $except = null;

        array_push($sockets, $this->socket);

        // Добавляем таймаут для socket_select вместо null (бесконечного ожидания)
        // Это позволит периодически проверять состояние системы
        return intval(socket_select($sockets, $write, $except, 1, 0)); // Таймаут 1 секунда
    }

    /**
     * @param array $sockets
     *
     * @return array
     */
    protected function clientAdd(array $sockets): array
    {
        $this->clientAccept();

        unset($sockets[array_search($this->socket, $sockets)]);

        return array_values($sockets);
    }

    /**
     * @return void
     */
    protected function clientAccept(): void
    {
        $this->clients[] = (object)[
            'socket' => socket_accept($this->socket),
            'timestamp' => time(),
            'data' => null,
        ];
    }

    /**
     * @param \Socket $socket
     * @param \Closure $handler
     *
     * @return void
     */
    protected function clientRead(Socket $socket, Closure $handler): void
    {
        // Запускаем таймер для измерения производительности
        Performance::startTimer('server.clientRead');
        
        $client = $this->clientBySocket($socket);

        if ($client === null) {
            Performance::endTimer('server.clientRead');
            return;
        }

        $response = Client::new($client, $handler)->handle();

        if ($response === false) {
            $this->close($client->socket);
        }
        
        // Завершаем измерение и сохраняем метрику
        Performance::endTimer('server.clientRead');
    }

    /**
     * @return void
     */
    protected function create(): void
    {
        $this->socket = socket_create(AF_INET, $this->socketType, $this->socketProtocol);
    }

    /**
     * @return void
     */
    protected function reuse(): void
    {
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
    }

    /**
     * @return void
     */
    protected function bind(): void
    {
        socket_bind($this->socket, '0.0.0.0', $this->port);
    }

    /**
     * @return void
     */
    protected function listen(): void
    {
        socket_listen($this->socket);
    }

    /**
     * @param \Socket $socket
     *
     * @return ?\stdClass
     */
    protected function clientBySocket(Socket $socket): ?stdClass
    {
        foreach ($this->clients as $client) {
            if ($client->socket === $socket) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    protected function clientSockets(): array
    {
        return array_filter(array_column($this->clients, 'socket'));
    }

    /**
     * Очистка неактивных соединений
     * 
     * @return void
     */
    protected function clientFilter(): void
    {
        $currentTime = time();
        $timeout = static::SOCKET_TIMEOUT;
        $filtered = false;
        
        // Оптимизированная обработка клиентов
        foreach ($this->clients as &$client) {
            // Проверка на пустой сокет
            if (empty($client->socket)) {
                $client = null;
                $filtered = true;
                
                continue;
            }
            
            // Проверка таймаута
            if (($currentTime - $client->timestamp) >= $timeout) {
                // Логируем закрытие соединения по таймауту
                logger()->info('Connection timeout', [
                    'port' => $this->port,
                    'timeout' => $timeout,
                    'inactive_time' => $currentTime - $client->timestamp
                ]);
                
                $this->close($client->socket);
                $client = null;
                $filtered = true;
            }
        }
        
        // Фильтруем массив только если были изменения
        if ($filtered) {
            $this->clients = array_filter($this->clients);
        }
    }

    /**
     * @param ?\Socket &$socket
     *
     * @return void
     */
    protected function close(?Socket &$socket): void
    {
        if ($socket) {
            try {
                socket_close($socket);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }

        $socket = null;
    }

    /**
     * @return void
     */
    protected function gracefulShutdown(): void
    {
        pcntl_signal(SIGINT, [$this, 'gracefulShutdownHandler']);
        pcntl_signal(SIGTERM, [$this, 'gracefulShutdownHandler']);
    }

    /**
     * @return void
     */
    public function gracefulShutdownHandler(): void
    {
        $this->stop();
        exit;
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        foreach ($this->clientSockets() as $client) {
            $this->close($client);
        }

        if ($this->socket) {
            $this->close($this->socket);
        }

        $this->clients = [];
        $this->socket = null;
    }

    /**
     * @param \Throwable $e
     *
     * @return void
     */
    protected function error(Throwable $e): void
    {
        if ($this->errorIsReportable($e)) {
            // Добавляем более подробное логирование
            $context = [
                'port' => $this->port,
                'socket_type' => $this->socketType,
                'socket_protocol' => $this->socketProtocol,
                'clients_count' => count($this->clients),
                'exception' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ];
            
            report($e);
            
            // Добавляем запись в лог с контекстом
            logger()->error('Socket server error: ' . $e->getMessage(), $context);
        }
    }

    /**
     * @param \Throwable $e
     *
     * @return bool
     */
    protected function errorIsReportable(Throwable $e): bool
    {
        return (str_contains($e->getMessage(), ' closed ') === false)
            && (str_contains($e->getMessage(), ' unable to write to socket') === false)
            && (str_contains($e->getMessage(), ' reset by peer') === false);
    }
}
