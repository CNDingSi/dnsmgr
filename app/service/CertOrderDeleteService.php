<?php

namespace app\service;

use Exception;
use think\facade\Db;

class CertOrderDeleteService
{
    /** Delete an order and, when explicitly requested, its deployment tasks. */
    public static function delete(int $id, bool $force = false): int
    {
        if ($id <= 0) throw new Exception('证书订单ID无效');

        $order = Db::name('cert_order')->where('id', $id)->find();
        if (!$order) throw new Exception('证书订单不存在');
        $tasks = Db::name('cert_deploy')->where('oid', $id)->select();
        if (!$force && count($tasks) > 0) {
            throw new Exception('该证书关联了自动部署任务，请使用强制删除');
        }
        if (self::isProcessing($order)) throw new Exception('证书订单正在处理中，请稍后再删除');
        foreach ($tasks as $task) {
            if (self::isProcessing($task)) throw new Exception('关联部署任务正在处理中，请稍后再删除');
        }

        // Build the client while its local configuration still exists. Remote
        // cancellation happens only after the local transaction succeeds.
        $cancelService = null;
        try {
            $cancelService = new CertOrderService($id);
        } catch (Exception $e) {
        }

        $taskCount = Db::transaction(function () use ($id, $force) {
            $order = Db::name('cert_order')->where('id', $id)->lock(true)->find();
            if (!$order) throw new Exception('证书订单不存在');
            if (self::isProcessing($order)) throw new Exception('证书订单正在处理中，请稍后再删除');

            $tasks = Db::name('cert_deploy')->where('oid', $id)->lock(true)->select();
            if (count($tasks) > 0 && !$force) {
                throw new Exception('该证书关联了自动部署任务，请使用强制删除');
            }
            foreach ($tasks as $task) {
                if (self::isProcessing($task)) throw new Exception('关联部署任务正在处理中，请稍后再删除');
            }

            $taskCount = count($tasks);
            if ($force && $taskCount > 0) Db::name('cert_deploy')->where('oid', $id)->delete();
            Db::name('cert_domain')->where('oid', $id)->delete();
            Db::name('cert_order')->where('id', $id)->delete();
            return $taskCount;
        });
        if ($cancelService !== null) {
            try {
                $cancelService->cancel();
            } catch (Exception $e) {
            }
        }
        return $taskCount;
    }

    private static function isProcessing(array $row): bool
    {
        return (int) ($row['islock'] ?? 0) === 1
            && !empty($row['locktime'])
            && time() - strtotime($row['locktime']) < 3600;
    }
}
