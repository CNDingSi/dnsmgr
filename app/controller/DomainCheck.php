<?php

namespace app\controller;

use app\BaseController;
use app\service\DomainExistenceService;
use Throwable;

class DomainCheck extends BaseController
{
    public function start()
    {
        return $this->run(fn($service, $uid) => $service->start($uid, input('post.')));
    }

    public function status()
    {
        return $this->run(fn($service, $uid) => $service->report($service->get($uid, input('post.token', ''))));
    }

    public function step()
    {
        return $this->run(fn($service, $uid) => $service->step($uid, input('post.token', ''),
            input('post.revision/d', -1), input('post.confirmed/d', 0) === 1));
    }

    public function results()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限', 'total' => 0, 'rows' => []]);
        try {
            $job = (new DomainExistenceService())->get((int)$this->request->user['id'], input('post.token', ''));
            $rows = $job['results'];
            $status = input('post.result_status', '', 'trim');
            if ($status !== '') $rows = array_values(array_filter($rows, fn($row) => $row['status'] === $status));
            return json(['code' => 0, 'total' => count($rows), 'rows' => array_slice($rows,
                max(0, input('post.offset/d', 0)), max(1, min(200, input('post.limit/d', 50))))]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    private function run(callable $callback)
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            return json(['code' => 0, 'data' => $callback(new DomainExistenceService(), (int)$this->request->user['id'])]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }
}
