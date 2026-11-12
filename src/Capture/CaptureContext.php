<?php

namespace TelescopeMonitor\Capture;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Console\Kernel;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;
use WeakMap;

final class CaptureContext
{
    public ?array $job = null;

    public ?string $command = null;

    /** @var array<array{input: InputInterface, command: string}> */
    private array $commands = [];

    /** @var WeakMap<InputInterface, string>|null */
    private ?WeakMap $finishedCommands = null;

    public function startCommand(CommandStarting $event): void
    {
        $this->finishedCommands ??= new WeakMap;
        unset($this->finishedCommands[$event->input]);
        $this->commands[] = ['input' => $event->input, 'command' => $event->command];
        $this->command = $event->command;
    }

    public function finishCommand(CommandFinished $event): void
    {
        $frame = array_pop($this->commands);
        if ($frame === null || $frame['input'] !== $event->input) {
            // An unmatched event cannot prove which invocation is active.
            $this->commands = [];
            $this->command = null;
            return;
        }
        if ($event->exitCode !== 0) {
            $this->finishedCommands ??= new WeakMap;
            $this->finishedCommands[$event->input] = $frame['command'];
        }
        $this->command = $this->commands ? $this->commands[array_key_last($this->commands)]['command'] : null;
    }

    public function commandOrigin(Throwable $exception): ?string
    {
        if ($this->finishedCommands !== null && count($this->finishedCommands)) {
            // Laravel emits CommandFinished before Kernel reports a rethrown error.
            // Match the live reporting call and its exact input, never a last-command
            // name or a nonzero exit code alone. Do not retain backtrace arguments.
            $reportingKernel = null;
            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 64) as $frame) {
                if (($frame['object'] ?? null) instanceof Kernel) {
                    if ($frame['function'] === 'reportException' && ($frame['args'][0] ?? null) === $exception) {
                        $reportingKernel = $frame['object'];
                    } elseif ($frame['function'] === 'handle' && $reportingKernel === $frame['object']) {
                        $input = $frame['args'][0] ?? null;
                        return $input instanceof InputInterface ? ($this->finishedCommands[$input] ?? $this->command) : $this->command;
                    }
                }
            }
        }
        return $this->command;
    }

    public ?Throwable $failedJobException = null;

    public ?array $failedJobOrigin = null;

    public ?Throwable $probeException = null;

    public ?string $probeId = null;
}
