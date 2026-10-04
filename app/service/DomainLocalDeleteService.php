<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

class DomainLocalDeleteService
{
    public static function preview(array $ids): array
    {
        $counts = [];
        foreach (['domain_alias' => '别名', 'dmtask' => '容灾任务', 'optimizeip' => '优选 IP 任务', 'sctask' => '定时解析任务'] as $table => $label) {
            $counts[$label] = Db::name($table)->whereIn('did', $ids)->count();
        }
        return $counts;
    }

    /** Caller owns the transaction and has locked the domain rows. No provider API is called. */
    public static function deleteRows(array $rows, int $uid): int
    {
        if (!$rows) return 0;
        $ids = array_column($rows, 'id');
        foreach (['domain_alias', 'dmtask', 'optimizeip', 'sctask'] as $table) {
            Db::name($table)->whereIn('did', $ids)->delete();
        }
        $count = Db::name('domain')->whereIn('id', $ids)->delete();
        foreach ($rows as $row) {
            Db::name('log')->insert(['uid' => $uid, 'domain' => $row['name'], 'action' => '删除本地域名',
                'data' => '清理本地域名及关联任务，未调用服务商删除接口', 'addtime' => date('Y-m-d H:i:s')]);
        }
        return $count;
    }

    public static function clearCaches(array $rows): void
    {
        foreach ($rows as $row) {
            Cache::delete('record_line_' . $row['id']);
            Cache::delete('min_ttl_' . $row['id']);
            Cache::delete('quicklogin_' . $row['name']);
        }
    }
}
