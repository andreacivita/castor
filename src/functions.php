<?php

namespace Castor;

use Symfony\Component\Process\Process;

/**
 * @return array<mixed>
 */
function parallel(callable ...$callbacks): array
{
    $fibers = [];
    foreach ($callbacks as $callback) {
        $fiber = new \Fiber($callback);
        $fiber->start();

        $fibers[] = $fiber;
    }

    $isRunning = true;

    while ($isRunning) {
        $isRunning = false;

        foreach ($fibers as $fiber) {
            $isRunning = $isRunning || !$fiber->isTerminated();

            if (!$fiber->isTerminated() && $fiber->isSuspended()) {
                $fiber->resume();
            }
        }

        if (\Fiber::getCurrent()) {
            \Fiber::suspend();
            usleep(1_000);
        }
    }

    return array_map(fn ($fiber) => $fiber->getReturn(), $fibers);
}

/**
 * @param string|array<string>  $command
 * @param array<string, string> $environment
 * @param (callable(string, string, Process) :void)|null $callback
 */
function exec(
    string|array $command,
    ?string $workingDirectory = null,
    array $environment = [],
    bool $tty = false,
    bool $pty = true,
    float|null $timeout = 60,
    bool $quiet = false,
    callable $callback = null,
): Process {
    $context = ContextRegistry::$currentContext;

    if (null === $workingDirectory) {
        $workingDirectory = $context->currentDirectory;
    }

    $environment = array_merge($context->environment, $environment);

    if (\is_array($command)) {
        $process = new Process($command, $workingDirectory, $environment, null, $timeout);
    } else {
        $process = Process::fromShellCommandline($command, $workingDirectory, $environment, null, $timeout);
    }

    if ($tty) {
        $process->setTty(true);
        $process->setInput(\STDIN);
    } elseif ($pty) {
        $process->setPty(true);
        $process->setInput(\STDIN);
    }

    if (!$quiet && !$callback) {
        $callback = static function ($type, $bytes) {
            if (Process::OUT === $type) {
                fwrite(\STDOUT, $bytes);
            } else {
                fwrite(\STDERR, $bytes);
            }
        };
    }

    $process->start(function ($type, $bytes) use ($callback, $process) {
        if ($callback) {
            $callback($type, $bytes, $process);
        }
    });

    if (\Fiber::getCurrent()) {
        while ($process->isRunning()) {
            \Fiber::suspend();
            usleep(1_000);
        }
    }

    $process->wait();

    return $process;
}

function cd(string $path): void
{
    $context = ContextRegistry::$currentContext;

    // if path is absolute
    if (0 === strpos($path, '/')) {
        $context->currentDirectory = $path;
    } else {
        $context->currentDirectory = PathHelper::realpath($context->currentDirectory . '/' . $path);
    }
}

/** @param (callable(string, string) : (false|null)) $function */
function watch(string $path, callable $function): void
{
    $binary = 'watcher';

    if ('\\' === \DIRECTORY_SEPARATOR) {
        $binary = 'watcher.exe';
    }

    $command = [__DIR__ . '/../watcher/bin/' . $binary, $path];
    $buffer = '';

    exec($command, pty: false, timeout: null, callback: static function ($type, $bytes, $process) use ($function, &$buffer) {
        if (Process::OUT === $type) {
            $data = $buffer . $bytes;
            $lines = explode("\n", $data);

            while (!empty($lines)) {
                $line = trim($lines[0]);

                if ('' === $line) {
                    array_shift($lines);

                    continue;
                }

                try {
                    $eventLine = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $buffer = implode("\n", $lines);

                    break;
                }

                $result = $function($eventLine['name'], $eventLine['operation']);

                if (false === $result) {
                    $process->stop();
                }

                array_shift($lines);
            }
        } else {
            fwrite(\STDERR, "ERROR: {$type} : " . $bytes);
        }
    });
}
