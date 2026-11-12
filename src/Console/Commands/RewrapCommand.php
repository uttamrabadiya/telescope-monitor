<?php

namespace TelescopeMonitor\Console\Commands;

use Illuminate\Console\Command;
use TelescopeMonitor\Operations\KeyRewrap;
use TelescopeMonitor\Operations\MaintenanceFailure;

final class RewrapCommand extends Command
{
    protected $signature = 'telescope-monitor:rewrap {installation} {target} {--confirm=} {--limit=100} {--after=0}';

    protected $description = 'Re-encrypt a bounded page using the current host key, preserving bytes/digests/revisions and unreadable ciphertext.';

    public function handle(KeyRewrap $rewrap): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
            if ($limit === false || $after === false) {
                throw new MaintenanceFailure('invalid_rewrap_target_or_limit');
            }$result = $rewrap->run((string) $this->argument('installation'), (string) $this->option('confirm'), (string) $this->argument('target'), $limit, $after);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof MaintenanceFailure ? $e->getMessage() : 'key_rewrap_failed');

            return self::FAILURE;
        }
    }
}
