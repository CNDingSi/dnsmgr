<?php

namespace app\service;

use app\lib\DnsHelper;
use RuntimeException;
use Throwable;
use think\facade\Cache;
use think\facade\Db;

/** Browser-driven, resumable jobs: at most one provider page or 50 local rows per step. */
class DomainExistenceService
{
    private const TTL = 3600;
    private const PAGE_SIZE = 50;
    private const BATCH_SIZE = 50;
    private const SUPPORTED = ['cloudflare', 'aliyun', 'aliyunesa', 'dnspod', 'huawei', 'huoshan', 'jdcloud',
        'dnsla', 'aws', 'namesilo', 'spaceship', 'west', 'bt', 'tencenteo', 'qingcloud', 'dnsmgr',
        'technitium', 'powerdns', 'baidu', 'dynv6', 'goedge'];

    public static function query(array $filters)
    {
        self::ensureSchema();
        $query = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id');
        if (!empty($filters['id'])) $query->where('A.id', (int)$filters['id']);
        elseif (!empty($filters['kw'])) $query->whereLike('A.name|A.remark', '%' . trim($filters['kw']) . '%');
        if (!empty($filters['aid'])) $query->where('A.aid', (int)$filters['aid']);
        if (!empty($filters['type'])) $query->whereLike('B.type', $filters['type']);
        if (isset($filters['cid']) && $filters['cid'] !== '') $query->where('A.cid', (int)$filters['cid']);
        if (!empty($filters['exist_status'])) $query->where('A.exist_status', $filters['exist_status']);
        if (($filters['status'] ?? '') === '2') $query->where('A.expiretime', '<=', date('Y-m-d H:i:s'));
        elseif (($filters['status'] ?? '') === '1') {
            $query->where('A.expiretime', '<=', date('Y-m-d H:i:s', time() + 86400 * 30))
                ->where('A.expiretime', '>', date('Y-m-d H:i:s'));
        }
        return $query;
    }

    public static function ensureSchema(): void
    {
        if (Cache::get('domain_existence_schema_ready')) return;
        $domainQuery = Db::name('domain');
        $tableName = $domainQuery->getTable();
        $table = '`' . str_replace('`', '``', $tableName) . '`';
        $fields = array_column(Db::query('SHOW COLUMNS FROM ' . $table), 'Field');
        $definitions = ['exist_status' => "varchar(20) NOT NULL DEFAULT 'unchecked'", 'exist_checked_at' => 'datetime DEFAULT NULL',
            'exist_message' => 'varchar(500) DEFAULT NULL', 'exist_remote_id' => 'varchar(255) DEFAULT NULL'];
        foreach ($definitions as $name => $definition) {
            if (in_array($name, $fields, true)) continue;
            try {
                Db::execute('ALTER TABLE ' . $table . ' ADD COLUMN `' . $name . '` ' . $definition);
            } catch (Throwable $e) {
                // Another request may have completed the same migration concurrently.
                $current = array_column(Db::query('SHOW COLUMNS FROM ' . $table), 'Field');
                if (!in_array($name, $current, true)) throw new RuntimeException('域名检测数据库升级失败，请检查数据库 ALTER 权限', 0, $e);
            }
        }
        // ORM strict-field checks may still hold schema metadata from before the upgrade.
        $domainQuery->getConnection()->getSchemaInfo($tableName, true);
        Cache::set('domain_existence_schema_ready', true, 3600);
    }

    public static function ids($values): array
    {
        if (!is_array($values)) throw new RuntimeException('请选择要操作的域名');
        $ids = [];
        foreach ($values as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9]\d*$/', (string)$value)) throw new RuntimeException('域名 ID 无效');
            $ids[] = (int)$value;
        }
        return array_values(array_unique($ids));
    }

    public static function accountHash(array $account): string
    {
        return hash('sha256', ($account['type'] ?? '') . "\0" . ($account['config'] ?? ''));
    }

    public function start(int $uid, array $params): array
    {
        $mode = $params['mode'] ?? 'check';
        if (!in_array($mode, ['check', 'delete'], true)) throw new RuntimeException('操作类型无效');
        $scope = $params['scope'] ?? 'selected';
        $query = self::query($scope === 'filtered' ? $params : []);
        if ($scope === 'selected') {
            $ids = self::ids($params['ids'] ?? []);
            if (!$ids) throw new RuntimeException('请选择要操作的域名');
            $query->whereIn('A.id', $ids);
        } elseif ($scope === 'account') {
            if (empty($params['aid']) || (int)$params['aid'] <= 0) throw new RuntimeException('请选择域名账户');
            $query->where('A.aid', (int)$params['aid']);
        } elseif ($scope === 'results_missing' && $mode === 'delete') {
            $source = $this->get($uid, (string)($params['source_job'] ?? ''));
            if (!$source['done'] || $source['mode'] !== 'check') throw new RuntimeException('请先完成检测');
            $ids = array_column(array_filter($source['results'], fn($row) => $row['status'] === DomainExistenceScanner::MISSING), 'id');
            if (!$ids) throw new RuntimeException('该检测结果中没有可清理的域名');
            $query->whereIn('A.id', $ids);
        } elseif ($scope !== 'filtered') {
            throw new RuntimeException('检测范围无效');
        }
        // Cleanup can only target domains previously reported missing, never arbitrary local IDs.
        if ($mode === 'delete') $query->where('A.exist_status', DomainExistenceScanner::MISSING);
        $targets = $query->field('A.id,A.aid,A.name,A.thirdid')->order('A.aid')->order('A.id')->select()->toArray();
        if (!$targets) throw new RuntimeException($mode === 'delete' ? '没有可清理的远端未找到域名，请先检测' : '没有符合条件的域名');
        if (count($targets) > 20000) throw new RuntimeException('单次任务最多处理 20000 个域名，请缩小筛选范围');
        $accounts = Db::name('account')->whereIn('id', array_unique(array_column($targets, 'aid')))->select()->toArray();
        $groups = [];
        foreach ($accounts as $account) {
            $groups[(int)$account['id']] = ['aid' => (int)$account['id'], 'hash' => self::accountHash($account),
                'targets' => [], 'listing' => DomainExistenceScanner::emptyListing(), 'index' => null, 'offset' => 0,
                'error' => '', 'error_status' => DomainExistenceScanner::FAILED, 'listed_at' => 0];
        }
        foreach ($targets as $target) {
            if (!isset($groups[(int)$target['aid']])) throw new RuntimeException('账户已变化，请重新开始');
            $groups[(int)$target['aid']]['targets'][] = $target;
        }
        $job = ['token' => bin2hex(random_bytes(16)), 'uid' => $uid, 'mode' => $mode, 'total' => count($targets),
            'groups' => array_values($groups), 'group' => 0, 'done' => false, 'revision' => 0,
            'approved' => $mode === 'check', 'created' => time(), 'results' => [],
            'counts' => ['normal' => 0, 'missing' => 0, 'changed' => 0, 'failed' => 0, 'unsupported' => 0, 'deleted' => 0, 'skipped' => 0],
            'tasks' => $mode === 'delete' ? DomainLocalDeleteService::preview(array_column($targets, 'id')) : [],
            'message' => $mode === 'delete' ? '等待确认清理本地记录' : '准备获取远端域名列表'];
        $this->save($job);
        return $this->report($job);
    }

    public function get(int $uid, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new RuntimeException('检测任务无效');
        $job = Cache::get('domain_existence_' . $token);
        if (!$job || $job['uid'] !== $uid || time() - $job['created'] > 86400) throw new RuntimeException('检测任务已过期或无权限，请重新开始');
        return $job;
    }

    public function report(array $job): array
    {
        return array_intersect_key($job, array_flip(['token', 'mode', 'total', 'done', 'revision', 'approved', 'counts', 'tasks', 'message']))
            + ['processed' => count($job['results'])];
    }

    public function step(int $uid, string $token, int $revision, bool $confirmed): array
    {
        // A user-level OS lock makes retries idempotent with both file and Redis cache drivers.
        $dir = app()->getRuntimePath() . 'domain-check-locks';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('无法创建检测锁目录');
        $lock = fopen($dir . DIRECTORY_SEPARATOR . $uid . '.lock', 'c');
        if (!$lock) throw new RuntimeException('无法创建检测任务锁');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('检测请求正在执行，请稍后继续');
        }
        try {
            $job = $this->get($uid, $token);
            if ($job['done'] || $revision !== $job['revision']) return $this->report($job);
            if (!$job['approved']) {
                if (!$confirmed) throw new RuntimeException('请确认本地清理操作');
                $job['approved'] = true;
            }
            $group = &$job['groups'][$job['group']];
            if ($job['mode'] === 'delete' && $group['index'] !== null && time() - $group['listed_at'] > 120) {
                $group['index'] = null;
                $group['listing'] = DomainExistenceScanner::emptyListing();
            }
            $account = Db::name('account')->where('id', $group['aid'])->find();
            if (!$account || self::accountHash($account) !== $group['hash']) {
                $group['error'] = '账户配置已变化，请重新检测';
            } elseif (!in_array($account['type'], self::SUPPORTED, true)) {
                $group['error'] = '该服务商暂不支持可靠的完整列表检测';
                $group['error_status'] = DomainExistenceScanner::UNSUPPORTED;
            }
            if ($group['error'] === '' && $group['index'] === null) {
                try {
                    $model = DnsHelper::getModel($group['aid']);
                    if (!$model) throw new RuntimeException('DNS 模块不存在');
                    // Missing response fields must become failures rather than empty-account decisions.
                    set_error_handler(static function ($severity, $message, $file, $line) {
                        if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
                        return false;
                    });
                    try {
                        $response = $model->getDomainList(null, $group['listing']['page'], self::PAGE_SIZE);
                        if ($response === false) throw new RuntimeException($model->getError() ?: '获取远端域名列表失败');
                        $group['listing'] = DomainExistenceScanner::appendPage($group['listing'], $response);
                    } finally {
                        restore_error_handler();
                    }
                    $job['message'] = '账户 ' . $group['aid'] . '：已获取 ' . count($group['listing']['rows']) . ' / ' . $group['listing']['total'] . ' 个远端域名';
                    if ($group['listing']['complete']) {
                        $group['index'] = DomainExistenceScanner::index($group['listing']);
                        $group['listing'] = null;
                        $group['listed_at'] = time();
                    }
                } catch (Throwable $e) {
                    $group['error'] = self::safeError($e->getMessage(), $account);
                }
                // Persist the page before any domain writes, allowing retries after interrupted requests.
                $job['revision']++;
                $this->save($job);
                return $this->report($job);
            }
            $batch = array_slice($group['targets'], $group['offset'], self::BATCH_SIZE);
            $deleted = [];
            $results = [];
            Db::startTrans();
            try {
                // Lock credentials and local targets only after provider requests have finished.
                $currentAccount = Db::name('account')->where('id', $group['aid'])->lock(true)->find();
                $accountChanged = !$currentAccount || self::accountHash($currentAccount) !== $group['hash'];
                foreach ($batch as $snapshot) {
                    $current = Db::name('domain')->where('id', $snapshot['id'])->lock(true)->find();
                    $result = ['id' => $snapshot['id'], 'aid' => $snapshot['aid'], 'name' => $snapshot['name'],
                        'status' => 'skipped', 'message' => '本地域名已删除或配置已变化，请重新检测', 'remote_id' => ''];
                    if ($job['mode'] === 'delete' && $current && $current['exist_status'] !== DomainExistenceScanner::MISSING) {
                        $result['message'] = '检测状态已变化，已跳过清理，请重新检测';
                    } elseif ($current && DomainExistenceScanner::sameDomain($snapshot, $current) && !$accountChanged) {
                        $decision = $group['error'] !== ''
                            ? ['status' => $group['error_status'], 'message' => $group['error'], 'remote_id' => '']
                            : DomainExistenceScanner::compare($current, $group['index']);
                        Db::name('domain')->where('id', $current['id'])->update([
                            'exist_status' => $decision['status'], 'exist_checked_at' => date('Y-m-d H:i:s'),
                            'exist_message' => mb_substr($decision['message'], 0, 500),
                            'exist_remote_id' => mb_substr($decision['remote_id'], 0, 255),
                        ]);
                        $result = array_merge($result, $decision);
                        if ($job['mode'] === 'delete') {
                            if ($decision['status'] === DomainExistenceScanner::MISSING) {
                                $deleted[] = $current;
                                $result['status'] = 'deleted';
                                $result['message'] = '复查后仍然远端未找到，已删除本地记录及关联任务';
                            } else {
                                $result['status'] = 'skipped';
                                $result['message'] = '跳过清理：' . (DomainExistenceScanner::labels()[$decision['status']] ?? '') . '；' . $decision['message'];
                            }
                        }
                    } elseif ($accountChanged) {
                        $result['message'] = '账户配置已变化，已跳过，请重新检测';
                    }
                    $results[] = $result;
                }
                DomainLocalDeleteService::deleteRows($deleted, $uid);
                Db::commit();
            } catch (Throwable $e) {
                Db::rollback();
                throw $e;
            }
            // Commit job progress before cache invalidation, so an invalidation error cannot repeat a deletion.
            foreach ($results as $result) {
                $job['results'][] = $result;
                $job['counts'][$result['status']]++;
            }
            $group['offset'] += count($batch);
            $job['message'] = '账户 ' . $group['aid'] . '：已处理 ' . $group['offset'] . ' / ' . count($group['targets']) . ' 个本地域名';
            if ($group['offset'] >= count($group['targets'])) {
                $group['targets'] = [];
                $group['index'] = null;
                $group['listing'] = null;
                $job['group']++;
                if ($job['group'] >= count($job['groups'])) {
                    $job['done'] = true;
                    $job['message'] = $job['mode'] === 'delete' ? '本地清理完成' : '域名检测完成';
                }
            }
            $job['revision']++;
            $this->save($job);
            DomainLocalDeleteService::clearCaches($deleted);
            return $this->report($job);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function save(array $job): void
    {
        if (!Cache::set('domain_existence_' . $job['token'], $job, self::TTL)) throw new RuntimeException('无法保存检测进度，请检查缓存配置');
    }

    private static function safeError(string $message, array $account): string
    {
        $config = json_decode($account['config'] ?? '', true);
        if (is_array($config)) {
            array_walk_recursive($config, static function ($value, $key) use (&$message) {
                if (is_scalar($value) && strlen((string)$value) >= 6 && preg_match('/key|secret|token|password/i', (string)$key)) {
                    $message = str_replace([(string)$value, rawurlencode((string)$value)], '[已隐藏]', $message);
                }
            });
        }
        return mb_substr($message, 0, 500);
    }
}
