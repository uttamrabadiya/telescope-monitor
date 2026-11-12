<?php

namespace TelescopeMonitor\Telescope;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use TelescopeMonitor\Models\Installation;
use TelescopeMonitor\Repositories\Contracts\InstallationRepository;

final class StoredEntries
{
    public function signature(): string
    {
        $connection = DB::connection(config('telescope.storage.database.connection'));
        $identity = [];
        foreach (['driver', 'host', 'port', 'database', 'unix_socket', 'prefix'] as $key) {
            $identity[$key] = $connection->getConfig($key);
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function verifySource(): Installation
    {
        return app(InstallationRepository::class)->verifySource($this->signature());
    }

    private function query()
    {
        return DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries');
    }

    public function maximum(): int
    {
        return (int) $this->query()->max('sequence');
    }

    public function sequences(array $uuids): array
    {
        $all = [];
        foreach (array_chunk($uuids, 100) as $chunk) {
            $all += $this->query()->whereIn('uuid', $chunk)->pluck('sequence', 'uuid')->all();
        }

        return $all;
    }

    public function exceptions(int $after, int $limit, ?string $batch = null, ?string $since = null, ?int $ceiling = null): array
    {
        if ($since !== null) {
            $since = CarbonImmutable::parse($since, 'UTC')->setTimezone(config('exception-monitor.capture.source_timezone') ?? config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        }
        $rows = $this->query()->where('type', 'exception')->where('sequence', '>', $after)
            ->when($batch, fn ($q) => $q->where('batch_id', $batch))->when($since, fn ($q) => $q->where('created_at', '>=', $since))->when($ceiling !== null, fn ($q) => $q->where('sequence', '<=', $ceiling))
            ->orderBy('sequence')->limit($limit)->get(['sequence', 'uuid', 'batch_id', 'created_at', DB::raw('length(content) as content_bytes')]);

        return $this->hydrate($rows->all());
    }

    public function historicalPage(int $after, int $limit, int $through): array
    {
        $rows = $this->query()->where('type', 'exception')->where('sequence', '>', $after)->where('sequence', '<=', $through)
            ->orderBy('sequence')->limit($limit)->get(['sequence', 'uuid', 'batch_id', 'created_at', DB::raw('length(content) as content_bytes')]);

        return $this->hydrate($rows->all(), true);
    }

    public function hasHistoricalAfter(int $after, int $through): bool
    {
        return $this->query()->where('type', 'exception')->where('sequence', '>', $after)->where('sequence', '<=', $through)->exists();
    }

    public function context(string $batch): array
    {
        $rows = $this->query()->where('batch_id', $batch)->whereIn('type', ['request', 'job', 'command', 'log', 'query'])->orderByRaw("CASE WHEN type IN ('request','job','command') THEN 0 ELSE 1 END")->orderBy('sequence')->limit(100)->get(['sequence', 'uuid', 'type', DB::raw('length(content) as content_bytes')]);

        return $this->hydrate($rows->all());
    }

    public function exceptionByUuid(string $id): array
    {
        $rows = $this->query()->where('uuid', $id)->where('type', 'exception')->get(['sequence', 'uuid', 'batch_id', 'created_at', DB::raw('length(content) as content_bytes')]);

        return $this->hydrate($rows->all());
    }

    /**
     * Queries external storage that Telescope callbacks may change.
     *
     * @phpstan-impure
     */
    public function hasUuid(string $id): bool
    {
        return $this->query()->where('uuid', $id)->exists();
    }

    private function hydrate(array $rows, bool $allowUnmarked = false): array
    {
        if (! $rows) {
            return [];
        }
        $budget = max(131072, min(8388608, (int) config('exception-monitor.capture.max_source_bytes', 2097152)));
        $tags = [];
        foreach (array_chunk(array_map(fn ($r) => $r->uuid, $rows), 100) as $chunk) {
            foreach (DB::connection(config('telescope.storage.database.connection'))->table('telescope_entries_tags')->whereIn('entry_uuid', $chunk)->get() as $tag) {
                $tags[$tag->entry_uuid][] = $tag->tag;
            }
        }
        $used = 0;
        $ids = [];
        $accepted = [];
        $more = false;
        foreach ($rows as $row) {
            // Legacy unmarked exceptions cannot prove reportability and do not consume the content budget.
            $marked = $allowUnmarked || ($row->type ?? 'exception') !== 'exception' || count(array_filter($tags[$row->uuid] ?? [], fn ($tag) => (str_starts_with($tag, 'em2c|') || str_starts_with($tag, 'em3|')))) === 1;
            if ($marked && $row->content_bytes <= $budget && $used + $row->content_bytes > $budget) {
                $more = true;
                break;
            }
            $accepted[] = $row;
            if ($marked && $row->content_bytes <= $budget) {
                $ids[] = $row->uuid;
                $used += $row->content_bytes;
            }
        }
        $contents = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $contents += $this->query()->whereIn('uuid', $chunk)->pluck('content', 'uuid')->all();
        }

        $last = end($accepted)->uuid;

        return array_map(function ($row) use ($contents, $tags, $more, $last) {
            $content = isset($contents[$row->uuid]) ? json_decode($contents[$row->uuid], true) : null;

            return [
                'page_has_more' => $more && $row->uuid === $last,
                'uuid' => $row->uuid,
                'sequence' => (int) $row->sequence,
                'batch_uuid' => $row->batch_id ?? null,
                'created_at' => $row->created_at ?? null,
                'type' => $row->type ?? 'exception',
                'content' => is_array($content) ? $content : null,
                'tags' => $tags[$row->uuid] ?? [],
            ];
        }, $accepted);
    }
}
