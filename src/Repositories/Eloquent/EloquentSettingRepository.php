<?php

namespace TelescopeMonitor\Repositories\Eloquent;

use TelescopeMonitor\Models\Setting;
use TelescopeMonitor\Repositories\Contracts\SettingRepository;

final class EloquentSettingRepository implements SettingRepository
{
    public function find(int $installationId, string $group): ?Setting
    {
        return Setting::query()->where('installation_id', $installationId)->where('group_key', $group)->first();
    }

    public function write(int $installationId, string $group, array $values, string $actor): Setting
    {
        $setting = $this->find($installationId, $group) ?? new Setting([
            'installation_id' => $installationId,
            'group_key' => $group,
            'row_version' => 0,
        ]);
        $setting->values = $values;
        $setting->row_version++;
        $setting->updated_by_actor_key = $actor;
        $setting->save();

        return $setting;
    }
}
