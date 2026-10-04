<?php
/** Run: php tests/domain_existence.php (Composer dependencies, pdo_sqlite, mbstring and intl required). */

use app\service\DomainExistenceScanner as Scanner;
use app\service\DomainExistenceService as Service;
use think\facade\Cache;
use think\facade\Db;

$autoload = getenv('DNSMGR_TEST_VENDOR') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;
require dirname($autoload) . '/topthink/framework/src/helper.php';
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'app\\')) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) require $file;
    }
});
date_default_timezone_set('Asia/Shanghai');

// Replace only the provider boundary; database, ORM, cache and transaction behavior are real.
class TestDomainProviderFactory
{
    public static array $pages = [];
    public static array $calls = [];
    public static function getModel($aid)
    {
        return new class((int)$aid) {
            public function __construct(private int $aid) {}
            public function getDomainList($keyword, $page, $size)
            {
                TestDomainProviderFactory::$calls[] = [$this->aid, $keyword, $page, $size];
                $response = TestDomainProviderFactory::$pages[$this->aid][$page] ?? false;
                if ($response instanceof Throwable) throw $response;
                return $response;
            }
            public function getError() { return 'Provider request failed'; }
        };
    }
}
class_alias(TestDomainProviderFactory::class, 'app\\lib\\DnsHelper');

// SQLite accepts the column DDL; translate only MySQL's metadata query for upgrade tests.
class TestMigrationConnector extends think\db\connector\Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $matches)) {
            return array_map(fn($row) => ['Field' => $row['name']], parent::query('PRAGMA table_info("' . $matches[1] . '")'));
        }
        return parent::query($sql, $bind, $master);
    }
}

function checkPermission($type, $domain = null) { return (request()->user['level'] ?? 0) === 2; }
function real_ip($type = 0) { return '127.0.0.1'; }
function checkIfActive($actions) { return ''; }
function isNullOrEmpty($value) { return $value === null || $value === ''; }
function http_request($url, $body, $a, $b, $headers, $proxy, $method) {
    $GLOBALS['provider_http_calls'][] = [$url, $method];
    return array_shift($GLOBALS['provider_http_responses']);
}

$runtime = sys_get_temp_dir() . '/dnsmgr-existence-test-' . bin2hex(random_bytes(6));
mkdir($runtime, 0770, true);
$app = new think\App($runtime);
$app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => [
    'type' => 'sqlite', 'database' => $runtime . '/test.sqlite', 'prefix' => 'test_',
    'fields_strict' => true, 'fields_cache' => false, 'trigger_sql' => false,
]]], 'database');
$app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $runtime . '/cache/']]], 'cache');
$app->request->user = ['id' => 1, 'level' => 2, 'type' => 'user'];

Db::execute('CREATE TABLE test_account (id INTEGER PRIMARY KEY, type TEXT, config TEXT)');
Db::execute("CREATE TABLE test_domain (id INTEGER PRIMARY KEY, aid INTEGER, name TEXT, thirdid TEXT, remark TEXT, cid INTEGER DEFAULT 0,
    expiretime TEXT, exist_status TEXT DEFAULT 'unchecked', exist_checked_at TEXT, exist_message TEXT, exist_remote_id TEXT)");
foreach (['domain_alias', 'dmtask', 'optimizeip', 'sctask'] as $table) Db::execute('CREATE TABLE test_' . $table . ' (id INTEGER PRIMARY KEY, did INTEGER)');
Db::execute('CREATE TABLE test_log (id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, domain TEXT, action TEXT, data TEXT, addtime TEXT)');

$assertions = 0;
function expect($condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (Throwable $error) { expect(true, $message); return; }
    expect(false, $message);
}
function resetFixture(): void {
    foreach (['domain', 'account', 'domain_alias', 'dmtask', 'optimizeip', 'sctask', 'log'] as $table) Db::name($table)->where('id', '>', 0)->delete();
    Cache::clear();
    Cache::set('domain_existence_schema_ready', true);
    TestDomainProviderFactory::$pages = [];
    TestDomainProviderFactory::$calls = [];
    Db::name('account')->insert(['id' => 1, 'type' => 'cloudflare', 'config' => '{"apikey":"secret-test-key"}']);
}
function domain(int $id, string $name, string $remoteId, array $extra = []): void {
    Db::name('domain')->insert(array_merge(['id' => $id, 'aid' => 1, 'name' => $name, 'thirdid' => $remoteId], $extra));
}
function page(array $rows, ?int $total = null): array { return ['total' => $total ?? count($rows), 'list' => $rows]; }
function remote(string $name, string $id): array { return ['Domain' => $name, 'DomainId' => $id]; }
function finish(Service $service, array $job): array {
    for ($step = 0; !$job['done']; $step++) {
        if ($step > 30) throw new RuntimeException('Job failed to complete');
        $job = $service->step(1, $job['token'], $job['revision'], true);
    }
    return $job;
}

// Complete listings, missing pages, duplicates, changed totals and malformed payloads.
$listing = Scanner::appendPage(Scanner::emptyListing(), page([remote('EXAMPLE.COM.', 'a')], 2));
rejects(fn() => Scanner::index($listing), 'Incomplete listing must not permit missing decisions');
$complete = Scanner::appendPage($listing, page([remote('second.com', 'b')], 2));
expect($complete['complete'], 'All pages must complete');
expect(Scanner::compare(['name' => 'example.com', 'thirdid' => 'a'], Scanner::index($complete))['status'] === 'normal', 'Case and root dot must normalize');
expect(Scanner::compare(['name' => 'example.com', 'thirdid' => 'old'], Scanner::index($complete))['status'] === 'changed', 'Recreated zone must be changed, not missing');
expect(Scanner::compare(['name' => 'renamed.com', 'thirdid' => 'a'], Scanner::index($complete))['status'] === 'changed', 'Existing ID with changed name must not be deleted');
expect(Scanner::compare(['name' => 'gone.com', 'thirdid' => 'gone'], Scanner::index($complete))['status'] === 'missing', 'Absent domain must be missing');
rejects(fn() => Scanner::appendPage($listing, page([], 2)), 'Early empty page must fail');
rejects(fn() => Scanner::appendPage($listing, page([remote('example.com', 'a')], 2)), 'Duplicate page must fail');
rejects(fn() => Scanner::appendPage($listing, page([remote('another.com', 'a')], 2)), 'Duplicate zone IDs must fail even if names differ');
rejects(fn() => Scanner::appendPage($listing, page([], 3)), 'Changed total must fail');
foreach ([false, [], ['total' => -1, 'list' => []], ['total' => 1, 'list' => [[]]], page([remote('', 'x')]), page([remote('x.com', '')]), page([remote('x.com', 'x')], 0)] as $bad) {
    rejects(fn() => Scanner::appendPage(Scanner::emptyListing(), $bad), 'Malformed listing must fail');
}
$empty = Scanner::appendPage(Scanner::emptyListing(), page([]));
expect($empty['complete'], 'Successful empty account must be valid');
$duplicates = Scanner::appendPage(Scanner::emptyListing(), page([remote('example.com', 'private'), remote('example.com', 'public')]));
expect(Scanner::compare(['name' => 'example.com', 'thirdid' => 'public'], Scanner::index($duplicates))['status'] === 'normal', 'Duplicate names must use matching zone ID');
$idn = Scanner::appendPage(Scanner::emptyListing(), page([remote('xn--fsqu00a.xn--0zwm56d', 'idn')]));
expect(Scanner::compare(['name' => '例子.测试', 'thirdid' => 'idn'], Scanner::index($idn))['status'] === 'normal', 'IDN and Punycode must match');

$service = new Service();
resetFixture();
domain(1, 'normal.com', 'normal'); domain(2, 'gone.com', 'gone'); domain(3, 'changed.com', 'old');
TestDomainProviderFactory::$pages[1] = [1 => page([remote('normal.com', 'normal')], 2), 2 => page([remote('changed.com', 'new')], 2)];
$job = $service->start(1, ['scope' => 'account', 'aid' => 1]);
$first = $service->step(1, $job['token'], 0, false);
$retry = $service->step(1, $job['token'], 0, false);
expect($first === $retry && count(TestDomainProviderFactory::$calls) === 1, 'Retry must not advance or refetch a page');
expect(Db::name('domain')->where('id', 2)->value('exist_status') === 'unchecked', 'Partial listing must not mark missing');
$job = finish($service, $first);
expect($job['counts']['normal'] === 1 && $job['counts']['missing'] === 1 && $job['counts']['changed'] === 1, 'All detection classifications must persist');
expect(count(TestDomainProviderFactory::$calls) === 2, 'Each account page must only be fetched once');
rejects(fn() => $service->get(2, $job['token']), 'Other users must not read jobs');
rejects(fn() => $service->get(1, '../bad'), 'Invalid job tokens must fail');

// Failed pagination cannot create deletion candidates, even after a previous missing result.
resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']);
TestDomainProviderFactory::$pages[1] = [1 => page([remote('other.com', 'other')], 2), 2 => false];
$job = finish($service, $service->start(1, ['scope' => 'selected', 'ids' => [1]]));
expect($job['counts']['failed'] === 1 && Db::name('domain')->where('id', 1)->value('exist_status') === 'failed', 'API failure must overwrite stale missing status');
rejects(fn() => $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]), 'Failed domains must not be cleanup candidates');

// Cleanup rechecks, skips restored/recreated domains and removes related data transactionally.
resetFixture();
domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']);
domain(2, 'restored.com', 'restored', ['exist_status' => 'missing']);
domain(3, 'recreated.com', 'old', ['exist_status' => 'missing']);
domain(4, 'unchecked.com', 'unchecked');
foreach (['domain_alias', 'dmtask', 'optimizeip', 'sctask'] as $table) Db::name($table)->insertAll([['id' => 1, 'did' => 1], ['id' => 2, 'did' => 2]]);
foreach (['record_line_1', 'min_ttl_1', 'quicklogin_gone.com'] as $key) Cache::set($key, 'cached');
TestDomainProviderFactory::$pages[1] = [1 => page([remote('restored.com', 'restored'), remote('recreated.com', 'new')])];
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1, 2, 3, 4]]);
expect($job['total'] === 3 && $job['tasks']['容灾任务'] === 2, 'Preview must exclude unchecked domains and count linked tasks');
rejects(fn() => $service->step(1, $job['token'], 0, false), 'Cleanup requires explicit confirmation');
$job = finish($service, $job);
expect($job['counts']['deleted'] === 1 && $job['counts']['skipped'] === 2, 'Only still-missing domains may be cleaned');
expect(Db::name('domain')->count() === 3 && Db::name('domain')->where('id', 2)->value('exist_status') === 'normal', 'Restored domains must be preserved');
foreach (['domain_alias', 'dmtask', 'optimizeip', 'sctask'] as $table) expect(Db::name($table)->count() === 1, 'Cleanup must remove only selected linked data');
expect(Db::name('log')->count() === 1, 'Deletion must be audited');
expect(Cache::get('record_line_1') === null && Cache::get('quicklogin_gone.com') === null, 'Deleted domain caches must be invalidated');

resetFixture(); domain(1, 'gone.com', 'gone'); TestDomainProviderFactory::$pages[1] = [1 => page([])];
$checked = finish($service, $service->start(1, ['scope' => 'selected', 'ids' => [1]]));
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'results_missing', 'source_job' => $checked['token']]);
expect($job['total'] === 1, 'Cross-page cleanup must use saved results');
$pageDone = $service->step(1, $job['token'], 0, true);
Db::name('account')->where('id', 1)->update(['config' => '{"apikey":"new-credentials"}']);
$job = finish($service, $pageDone);
expect($job['counts']['skipped'] === 1 && Db::name('domain')->count() === 1, 'Credential changes must prevent stale cleanup');

resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']); TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]);
$job = $service->step(1, $job['token'], 0, true);
$saved = $service->get(1, $job['token']); $saved['groups'][0]['listed_at'] = time() - 121;
Cache::set('domain_existence_' . $job['token'], $saved);
TestDomainProviderFactory::$pages[1] = [1 => page([remote('gone.com', 'gone')])];
$job = finish($service, $job);
expect($job['counts']['skipped'] === 1 && count(TestDomainProviderFactory::$calls) === 2, 'Resuming old cleanup must fetch fresh remote data');

resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']); TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]);
$job = $service->step(1, $job['token'], 0, true);
Db::name('domain')->where('id', 1)->update(['thirdid' => 'new-id']);
$job = finish($service, $job);
expect($job['counts']['skipped'] === 1 && Db::name('domain')->count() === 1, 'Local zone configuration changes must prevent stale cleanup');

resetFixture();
for ($i = 1; $i <= 120; $i++) domain($i, 'domain' . $i . '.com', 'id-' . $i, ['cid' => $i <= 110 ? 7 : 8]);
TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = finish($service, $service->start(1, ['scope' => 'filtered', 'cid' => '7']));
expect($job['total'] === 110 && $job['processed'] === 110 && $job['counts']['missing'] === 110, 'Filtered jobs must span every local page and batch');
expect(Db::name('domain')->where('cid', 8)->where('exist_status', 'unchecked')->count() === 10, 'Unselected domains must be preserved');
$cleanup = finish($service, $service->start(1, ['mode' => 'delete', 'scope' => 'results_missing', 'source_job' => $job['token']]));
expect($cleanup['counts']['deleted'] === 110 && Db::name('domain')->count() === 10, 'Cross-page cleanup must handle all results');

resetFixture(); domain(1, 'gone.com', 'gone');
Db::name('account')->where('id', 1)->update(['type' => 'henet']);
$job = finish($service, $service->start(1, ['scope' => 'account', 'aid' => 1]));
expect($job['counts']['unsupported'] === 1 && TestDomainProviderFactory::$calls === [], 'Unreliable providers must not produce missing decisions');

resetFixture(); domain(1, 'gone.com', 'gone');
TestDomainProviderFactory::$pages[1] = [1 => new RuntimeException('Authorization failed for secret-test-key')];
$job = finish($service, $service->start(1, ['scope' => 'account', 'aid' => 1]));
expect(!str_contains(Db::name('domain')->where('id', 1)->value('exist_message'), 'secret-test-key'), 'Stored errors must redact credentials');

// Rollback must restore all deleted rows and related tasks if logging fails.
resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']);
Db::name('dmtask')->insert(['id' => 1, 'did' => 1]);
Db::execute("CREATE TRIGGER fail_deletion_log BEFORE INSERT ON test_log BEGIN SELECT RAISE(ABORT, 'log failure'); END");
TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]);
$job = $service->step(1, $job['token'], 0, true);
rejects(fn() => $service->step(1, $job['token'], $job['revision'], true), 'Deletion errors must surface');
expect(Db::name('domain')->count() === 1 && Db::name('dmtask')->count() === 1, 'Failed deletion must roll back domain and tasks');
expect($service->get(1, $job['token'])['revision'] === $job['revision'], 'Failed deletion must not advance progress');
Db::execute('DROP TRIGGER fail_deletion_log');

// Controller authorization uses the actual request user, never the client payload.
resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']);
TestDomainProviderFactory::$pages[1] = [1 => false];
$job = finish($service, $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]));
expect($job['counts']['skipped'] === 1 && Db::name('domain')->count() === 1, 'Failed cleanup revalidation must preserve domains');
expect(Db::name('domain')->where('id', 1)->value('exist_status') === 'failed', 'Failed cleanup revalidation must record failure');

resetFixture(); domain(1, 'gone.com', 'gone', ['exist_status' => 'missing']); TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = $service->start(1, ['mode' => 'delete', 'scope' => 'selected', 'ids' => [1]]);
$job = $service->step(1, $job['token'], 0, true);
Db::name('domain')->where('id', 1)->update(['exist_status' => 'normal']);
$job = finish($service, $job);
expect($job['counts']['skipped'] === 1 && Db::name('domain')->where('id', 1)->value('exist_status') === 'normal', 'New detection results must not be overwritten by older cleanup');

resetFixture();
Db::name('account')->insert(['id' => 2, 'type' => 'cloudflare', 'config' => '{"apikey":"second-account"}']);
domain(1, 'same.com', 'first'); domain(2, 'same.com', 'second', ['aid' => 2]);
TestDomainProviderFactory::$pages = [1 => [1 => page([])], 2 => [1 => page([remote('same.com', 'second')])]];
$job = finish($service, $service->start(1, ['scope' => 'filtered']));
expect($job['counts']['normal'] === 1 && $job['counts']['missing'] === 1, 'The same domain in different accounts must be checked independently');
expect(count(TestDomainProviderFactory::$calls) === 2, 'Each account must have its own listing');
$raw = $service->get(1, $job['token']); $raw['created'] = time() - 86401;
Cache::set('domain_existence_' . $job['token'], $raw);
rejects(fn() => $service->get(1, $job['token']), 'Old jobs must expire');

$app->request->user = ['id' => 2, 'level' => 1, 'type' => 'user'];
$controller = new app\controller\DomainCheck($app);
expect($controller->start()->getData()['code'] === -1, 'Restricted users cannot start jobs');
expect($controller->results()->getData()['code'] === -1, 'Restricted users cannot read results');
$app->request->user = ['id' => 1, 'level' => 2, 'type' => 'user'];

// Exercise the real Cloudflare adapter with fixture HTTP responses, including empty and failed accounts.
$GLOBALS['provider_http_calls'] = [];
$GLOBALS['provider_http_responses'] = [
    ['code' => 200, 'body' => json_encode(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']], 'result_info' => ['total_count' => 1]])],
    ['code' => 200, 'body' => json_encode(['success' => true, 'result' => [], 'result_info' => ['total_count' => 0]])],
    ['code' => 403, 'body' => json_encode(['success' => false, 'errors' => [['message' => 'Invalid token']]])],
];
$cf = new app\lib\dns\cloudflare(['email' => '', 'apikey' => 'fixture-token', 'auth' => 1, 'domain' => null, 'domainid' => null]);
expect(Scanner::appendPage(Scanner::emptyListing(), $cf->getDomainList(null, 1, 50))['complete'], 'Real CF adapter must match listing contract');
expect($cf->getDomainList()['total'] === 0, 'Real CF adapter must handle empty account');
expect($cf->getDomainList() === false && $cf->getError() === 'Invalid token', 'Real CF adapter must propagate permission errors');
foreach ($GLOBALS['provider_http_calls'] as [$url, $method]) expect($method === 'GET' && str_contains($url, '/zones?'), 'Detection must only read provider zones');

$GLOBALS['provider_http_responses'] = [
    ['code' => 200, 'body' => '[]'], ['code' => 200, 'body' => '{}'], ['code' => 200, 'body' => '[{"id":1}]'],
];
$dynv6 = new app\lib\dns\dynv6(['token' => 'fixture-token', 'domain' => null]);
expect($dynv6->getDomainList()['total'] === 0, 'Dynv6 must accept a valid empty array');
expect($dynv6->getDomainList() === false && $dynv6->getDomainList() === false, 'Malformed Dynv6 data must not become an empty account');
$GLOBALS['provider_http_responses'] = [['code' => 200, 'body' => '[]'], ['code' => 200, 'body' => 'true'], ['code' => 200, 'body' => '{}']];
$powerdns = new app\lib\dns\powerdns(['ip' => 'localhost', 'port' => 8081, 'apikey' => 'fixture-key', 'domain' => null, 'domainid' => null]);
expect($powerdns->getDomainList()['total'] === 0, 'PowerDNS must accept a valid empty account');
expect($powerdns->getDomainList() === false && $powerdns->getDomainList() === false, 'Malformed PowerDNS data must fail');

resetFixture(); domain(1, 'first.com', 'first'); domain(2, 'second.com', 'second');
TestDomainProviderFactory::$pages[1] = [1 => page([])];
$job = $service->start(1, ['scope' => 'filtered']);
$held = fopen(app()->getRuntimePath() . 'domain-check-locks/1.lock', 'c');
flock($held, LOCK_EX);
rejects(fn() => $service->step(1, $job['token'], 0, false), 'Concurrent requests must not execute the same job');
flock($held, LOCK_UN); fclose($held);
$job = finish($service, $job);
$app->request->withPost(['token' => $job['token'], 'offset' => 1, 'limit' => 1, 'result_status' => 'missing']);
$controller = new app\controller\DomainCheck($app);
$response = $controller->results()->getData();
expect($response['total'] === 2 && count($response['rows']) === 1 && $response['rows'][0]['id'] === 2, 'Results endpoint must paginate and filter on the server');

// Compile the real page and shared layout with the production template engine.
$template = new think\Template(['view_path' => dirname(__DIR__) . '/app/view/', 'cache_path' => $runtime . '/templates/']);
$vars = ['user' => ['id' => 1, 'level' => 2, 'username' => 'admin', 'regtime' => '2026-01-01'], 'skin' => 'skin-black-blue',
    'types' => ['cloudflare' => 'Cloudflare'], 'categorys' => [],
    'accounts' => [['id' => 1, 'name' => 'Cloudflare fixture', 'type' => 'Cloudflare', 'add' => 1]]];
ob_start(); $template->fetch('domain/domain', $vars); $html = ob_get_clean();
expect(str_contains($html, 'id="domainCheckModal"') && str_contains($html, '/static/js/domain-existence.js'), 'Production page must render detection UI');
expect(str_contains($html, 'value="missing"') && str_contains($html, 'Cloudflare fixture'), 'Account options and status filters must render');
if (getenv('DNSMGR_TEST_HTML')) file_put_contents(getenv('DNSMGR_TEST_HTML'), $html);
$vars['user']['level'] = 1; $app->request->user = ['id' => 1, 'level' => 1, 'type' => 'user'];
ob_start(); $template->fetch('domain/domain', $vars); $restrictedHtml = ob_get_clean();
expect(!str_contains($restrictedHtml, 'id="domainCheckModal"'), 'Restricted users must not see cleanup controls');

// Upgrade existing installations, including a stale ORM field cache, without losing data.
$app->config->set(['default' => 'migration', 'connections' => ['migration' => [
    'type' => '\\TestMigrationConnector', 'builder' => '\\think\\db\\builder\\Sqlite',
    'database' => $runtime . '/migration.sqlite', 'prefix' => 'upgrade_', 'fields_strict' => true, 'fields_cache' => true, 'trigger_sql' => false,
]]], 'database');
Db::execute('CREATE TABLE upgrade_domain (id INTEGER PRIMARY KEY, name TEXT)');
Db::name('domain')->insert(['id' => 1, 'name' => 'preserved.com']);
$connection = Db::name('domain')->getConnection();
$before = $connection->getTableInfo('upgrade_domain', 'fields');
expect(!in_array('exist_status', $before, true), 'Migration fixture must begin with old schema');
Cache::delete('domain_existence_schema_ready'); Service::ensureSchema();
$after = $connection->getTableInfo('upgrade_domain', 'fields');
expect(in_array('exist_status', $after, true) && in_array('exist_checked_at', $after, true)
    && in_array('exist_message', $after, true) && in_array('exist_remote_id', $after, true), 'Upgrade must create fields and refresh ORM metadata');
Db::name('domain')->where('id', 1)->update(['exist_status' => 'missing', 'exist_message' => 'fixture']);
Cache::delete('domain_existence_schema_ready'); Service::ensureSchema();
expect(Db::name('domain')->where('id', 1)->value('exist_status') === 'missing', 'Repeated upgrade must preserve existing data and results');

echo "PASS: $assertions assertions (SQLite ORM integration, pagination, revalidation, cleanup, permissions and Cloudflare adapter)\n";
