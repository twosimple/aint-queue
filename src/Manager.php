<?php

/*
 * This file is part of the littlesqx/aint-queue.
 *
 * (c) littlesqx <littlesqx@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Littlesqx\AintQueue;

use Littlesqx\AintQueue\Driver\Redis\Queue;
use Littlesqx\AintQueue\Exception\RuntimeException;
use Littlesqx\AintQueue\Logger\DefaultLogger;
use Psr\Log\LoggerInterface;
use Swoole\Process;
use Swoole\Timer;

class Manager
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var QueueInterface
     */
    protected $queue;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var WorkerManager
     */
    protected $workerManager;

    public function __construct(QueueInterface $driver, array $options = [])
    {
        $this->queue = $driver;
        $this->options = $options;
        $this->logger = new DefaultLogger();
        $this->workerManager = new WorkerManager($this->queue, $this->logger, $options['worker'] ?? []);
    }

    /**
     * Setup pidFile.
     *
     * @throws RuntimeException
     */
    protected function setupPidFile(): void
    {
        $pidFile = $this->getPidFile();
        if ($this->isRunning()) {
            throw new RuntimeException("Listener for queue:{$this->queue->getChannel()} is running!");
        }
        @\file_put_contents($pidFile, \getmypid());
    }

    /**
     * Get master pid file path.
     *
     * @return string
     */
    public function getPidFile(): string
    {
        $root = $this->options['pid_path'] ?? '';

        return $root."/{$this->queue->getChannel()}-master.pid";
    }

    /**
     * Register signal handler.
     */
    protected function registerSignal(): void
    {
        // force exit
        Process::signal(SIGTERM, function () {
            $this->workerManager->stop();
            $this->exitMaster();
        });
        // custom signal - reload workers
        Process::signal(SIGUSR1, function () {
            $this->workerManager->reload();
        });
    }

    /**
     * Register timer-process.
     */
    protected function registerTimer(): void
    {
        // move expired job
        Timer::tick(1000, function () {
            $this->queue->migrateExpired();
        });

        Timer::tick(1000 * 60 * 5, function () {
            if ($this->memoryExceeded()) {
                $this->exitMaster();
            }
        });

        // check queue status
        $handlers = $this->options['job_snapshot']['handler'] ?? [];
        if (!empty($handlers)) {
            $interval = (int) $this->options['job_snapshot']['interval'] ?? 60 * 5;
            Timer::tick(1000 * $interval, function () {
                $this->checkQueueStatus();
            });
        }
    }

    /**
     * Set a logger.
     *
     * @param LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return WorkerManager
     */
    public function getWorkerManager(): WorkerManager
    {
        return $this->workerManager;
    }

    /**
     * Get current queue instance.
     *
     * @return QueueInterface|Queue
     */
    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    /**
     * Listen the queue, to distribute job.
     *
     * @throws \Throwable
     */
    public function listen(): void
    {
        $this->queue->retryReserved();

        $this->workerManager->start();

        $this->setupPidFile();

        $this->registerSignal();

        $this->registerTimer();

        \register_shutdown_function([$this, 'exitMaster']);
    }

    /**
     * Whether memory exceeded or not.
     *
     * @return bool
     */
    public function memoryExceeded(): bool
    {
        $usage = memory_get_usage(true) / 1024 / 1024;

        return $usage >= $this->getMemoryLimit();
    }

    /**
     * Get manager's memory limit.
     *
     * @return float
     */
    public function getMemoryLimit(): float
    {
        return (float) ($this->options['memory_limit'] ?? 1024);
    }

    /**
     * Get sleep time(s) after every pop.
     *
     * @return int
     */
    public function getSleepTime(): int
    {
        return (int) \max($this->options['sleep_seconds'] ?? 0, 0);
    }

    /**
     * Exit master process.
     */
    public function exitMaster(): void
    {
        Timer::clearAll();
        $this->workerManager->stop();
        @\unlink($this->getPidFile());
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    /**
     * Whether current channel's master is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        $pidFile = $this->getPidFile();
        if (\file_exists($pidFile)) {
            $pid = (int) \file_get_contents($pidFile);

            return Process::kill($pid, 0);
        }

        return false;
    }

    /**
     * Check current queue's running status.
     */
    protected function checkQueueStatus()
    {
        try {
            [$waiting, $reserved, $delayed, $done, $failed, $total] = $this->queue->status();
            $snapshot = compact('waiting', 'reserved', 'delayed', 'done', 'failed', 'total');
            $handlers = $this->options['job_snapshot']['handler'] ?? [];
            foreach ($handlers as $handler) {
                if (!\is_string($handler) || !\class_exists($handler)) {
                    $this->logger->warning('Invalid JobSnapshotHandler or class not exists.');
                    continue;
                }
                $handler = new $handler();
                if (!$handler instanceof JobSnapshotHandlerInterface) {
                    $this->logger->warning('JobSnapshotHandler must implement JobSnapshotHandlerInterface.');
                    continue;
                }
                $handler->handle($snapshot);
            }
        } catch (\Throwable $t) {
            $this->logger->error('Error when exec JobSnapshotHandler, '.$t->getMessage(), [
                'driver' => \get_class($this->queue),
                'channel' => $this->queue->getChannel(),
            ]);

            return;
        }
    }
}
