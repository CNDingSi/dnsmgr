<?php

namespace app\service;

use RuntimeException;

/** Validates a complete provider listing before allowing any missing-domain decisions. */
class DomainExistenceScanner
{
    public const UNCHECKED = 'unchecked';
    public const NORMAL = 'normal';
    public const MISSING = 'missing';
    public const CHANGED = 'changed';
    public const FAILED = 'failed';
    public const UNSUPPORTED = 'unsupported';

    public static function labels(): array
    {
        return [self::UNCHECKED => '未检测', self::NORMAL => '正常', self::MISSING => '远端未找到',
            self::CHANGED => '域名 ID 不一致', self::FAILED => '检测失败', self::UNSUPPORTED => '暂不支持'];
    }

    public static function emptyListing(): array
    {
        return ['page' => 1, 'total' => null, 'rows' => [], 'complete' => false];
    }

    public static function appendPage(array $state, $response): array
    {
        if ($state['complete']) throw new RuntimeException('域名列表已获取完成');
        if (!is_array($response) || !isset($response['total'], $response['list']) || !is_array($response['list'])
            || !preg_match('/^\d+$/', (string)$response['total'])) {
            throw new RuntimeException('服务商返回的域名列表格式不完整');
        }
        $total = (int)$response['total'];
        if ($state['total'] !== null && $state['total'] !== $total) {
            throw new RuntimeException('分页期间远端域名数量发生变化，请重新检测');
        }
        $state['total'] = $total;
        foreach ($response['list'] as $row) {
            if (!is_array($row) || !isset($row['Domain'], $row['DomainId'])
                || !is_scalar($row['Domain']) || !is_scalar($row['DomainId'])) {
                throw new RuntimeException('服务商返回的域名信息不完整');
            }
            $name = self::normalizeName((string)$row['Domain']);
            $id = trim((string)$row['DomainId']);
            if ($name === '' || $id === '') throw new RuntimeException('服务商返回空域名或空域名 ID');
            // The same name may have multiple zones (e.g. Route 53 public/private zones).
            $key = hash('sha256', $id);
            if (isset($state['rows'][$key])) throw new RuntimeException('域名分页包含重复数据，无法确认列表完整性');
            $state['rows'][$key] = ['name' => $name, 'id' => $id];
        }
        $count = count($state['rows']);
        if ($count > $total || (empty($response['list']) && $count < $total)) {
            throw new RuntimeException('服务商域名列表缺页或总数不一致，请重新检测');
        }
        $state['complete'] = $count === $total;
        $state['page']++;
        if ($state['page'] > 10001 && !$state['complete']) throw new RuntimeException('域名分页超过检测上限');
        return $state;
    }

    public static function index(array $listing): array
    {
        if (!$listing['complete']) throw new RuntimeException('远端列表未完整获取，不能判断域名是否存在');
        $index = ['names' => [], 'ids' => []];
        foreach ($listing['rows'] as $row) {
            $index['names'][$row['name']][] = $row['id'];
            $index['ids'][$row['id']][] = $row['name'];
        }
        return $index;
    }

    public static function compare(array $domain, array $index): array
    {
        $name = self::normalizeName($domain['name']);
        $id = trim((string)($domain['thirdid'] ?? ''));
        $ids = $index['names'][$name] ?? [];
        if ($id !== '' && in_array($id, $ids, true)) {
            return ['status' => self::NORMAL, 'message' => '远端域名及 ID 匹配', 'remote_id' => $id];
        }
        if ($ids) {
            return ['status' => self::CHANGED, 'message' => '同名域名仍然存在，但远端 ID 与本地不一致，请核对账户和域名配置',
                'remote_id' => implode(', ', $ids)];
        }
        if ($id !== '' && isset($index['ids'][$id])) {
            return ['status' => self::CHANGED, 'message' => '远端 ID 存在，但域名名称与本地不一致，请核对域名配置', 'remote_id' => $id];
        }
        return ['status' => self::MISSING, 'message' => '当前凭据可见的完整域名列表中未找到；可能已删除、已转移或当前凭据无访问权限', 'remote_id' => ''];
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) $name = strtolower($ascii);
        }
        return $name;
    }

    public static function sameDomain(array $snapshot, array $current): bool
    {
        foreach (['id', 'aid', 'name', 'thirdid'] as $field) {
            if ((string)($snapshot[$field] ?? '') !== (string)($current[$field] ?? '')) return false;
        }
        return true;
    }
}
