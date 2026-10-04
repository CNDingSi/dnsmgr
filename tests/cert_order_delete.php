<?php
/** Run: php tests/cert_order_delete.php (Composer dependencies and pdo_sqlite required). */

use app\service\CertOrderDeleteService;
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

// Only the external cancellation boundary is replaced; ORM transactions are real.
class TestCertOrderService
{
    public static array $cancelled = [];
    public static array $processed = [];
    public function __construct(private int $id) {}
    public function cancel(): void { self::$cancelled[] = $this->id; }
    public function reset(): void { Db::name('cert_order')->where('id', $this->id)->update(['status' => 0]); }
    public function process(bool $manual): int { self::$processed[] = [$this->id, $manual]; return 1; }
}
class_alias(TestCertOrderService::class, 'app\\service\\CertOrderService');
class TestCertDeployService
{
    public static array $processed = [];
    public static array $reset = [];
    public function __construct(private int $id) {}
    public function reset(): void { self::$reset[] = $this->id; Db::name('cert_deploy')->where('id', $this->id)->update(['status' => 0]); }
    public function process(bool $manual): void { self::$processed[] = [$this->id, $manual]; }
}
class_alias(TestCertDeployService::class, 'app\\service\\CertDeployService');
function checkPermission($level): bool { return true; }
function real_ip($type = 0): string { return '127.0.0.1'; }

$runtime = sys_get_temp_dir() . '/dnsmgr-cert-delete-' . bin2hex(random_bytes(6));
mkdir($runtime, 0770, true);
$app = new think\App($runtime);
$app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => [
    'type' => 'sqlite', 'database' => $runtime . '/test.sqlite', 'prefix' => 'test_',
    'fields_strict' => true, 'fields_cache' => false, 'trigger_sql' => false,
]]], 'database');
$app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $runtime . '/cache/']]], 'cache');
Db::execute('CREATE TABLE test_cert_order (id INTEGER PRIMARY KEY, status INTEGER DEFAULT 0, islock INTEGER DEFAULT 0, locktime TEXT)');
Db::execute('CREATE TABLE test_cert_domain (id INTEGER PRIMARY KEY, oid INTEGER)');
Db::execute('CREATE TABLE test_cert_deploy (id INTEGER PRIMARY KEY, oid INTEGER, status INTEGER DEFAULT 0, islock INTEGER DEFAULT 0, locktime TEXT)');

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (Exception $e) { return; }
    throw new RuntimeException($message);
}
function fixture(int $id, int $tasks = 0): void {
    Db::name('cert_order')->insert(['id' => $id]);
    Db::name('cert_domain')->insert(['id' => $id, 'oid' => $id]);
    for ($i = 1; $i <= $tasks; $i++) {
        Db::name('cert_deploy')->insert(['id' => $id * 10 + $i, 'oid' => $id]);
    }
}

fixture(1, 2);
rejects(fn() => CertOrderDeleteService::delete(1), 'Normal deletion must protect linked tasks');
expect(Db::name('cert_order')->where('id', 1)->count() === 1, 'Rejected deletion must preserve the order');
expect(TestCertOrderService::$cancelled === [], 'Rejected deletion must not cancel the order');
expect(CertOrderDeleteService::delete(1, true) === 2, 'Force deletion must count related tasks');
expect(Db::name('cert_order')->where('id', 1)->count() === 0, 'Force deletion must remove the order');
expect(Db::name('cert_domain')->where('oid', 1)->count() === 0, 'Force deletion must remove bound domains');
expect(Db::name('cert_deploy')->where('oid', 1)->count() === 0, 'Force deletion must remove related deployment tasks');
expect(TestCertOrderService::$cancelled === [1], 'Deletion must cancel the remote order once');

fixture(2);
expect(CertOrderDeleteService::delete(2) === 0, 'Normal deletion must work without deployment tasks');

fixture(3, 1);
Db::name('cert_deploy')->where('oid', 3)->update(['islock' => 1, 'locktime' => date('Y-m-d H:i:s')]);
rejects(fn() => CertOrderDeleteService::delete(3, true), 'Running deployment must block force deletion');
expect(!in_array(3, TestCertOrderService::$cancelled, true), 'Running task must not trigger remote cancellation');
expect(Db::name('cert_order')->where('id', 3)->count() === 1, 'Transaction must preserve order when task is running');
expect(Db::name('cert_domain')->where('oid', 3)->count() === 1, 'Transaction must preserve domains when task is running');
expect(Db::name('cert_deploy')->where('oid', 3)->count() === 1, 'Transaction must preserve task when running');

fixture(4);
Db::name('cert_order')->where('id', 4)->update(['islock' => 1, 'locktime' => date('Y-m-d H:i:s')]);
rejects(fn() => CertOrderDeleteService::delete(4, true), 'Running order must block deletion');
expect(!in_array(4, TestCertOrderService::$cancelled, true), 'Running order must not trigger remote cancellation');
expect(Db::name('cert_order')->where('id', 4)->count() === 1, 'Running order must remain');
rejects(fn() => CertOrderDeleteService::delete(999, true), 'Missing order must be rejected');

fixture(7, 1);
Db::execute("CREATE TRIGGER prevent_cert_domain_delete BEFORE DELETE ON test_cert_domain WHEN OLD.oid = 7 BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
rejects(fn() => CertOrderDeleteService::delete(7, true), 'Database deletion error must abort the transaction');
expect(!in_array(7, TestCertOrderService::$cancelled, true), 'Rolled-back deletion must not cancel the remote order');
expect(Db::name('cert_order')->where('id', 7)->count() === 1, 'Rollback must preserve the order');
expect(Db::name('cert_domain')->where('oid', 7)->count() === 1, 'Rollback must preserve the domain');
expect(Db::name('cert_deploy')->where('oid', 7)->count() === 1, 'Rollback must restore removed deployment tasks');

// Exercise controller status guards and deployment re-execution through the HTTP handlers.
$controller = new app\controller\Cert($app);
function requestPost(think\App $app, array $post): void { $app->request->withPost($post); }
fixture(5);
requestPost($app, ['id' => 5, 'mode' => 'verify']);
expect($controller->order_process()->getData()['code'] === -1, 'Batch verify must reject a pending submission');
expect(TestCertOrderService::$processed === [], 'Rejected batch action must not call the provider');
requestPost($app, ['id' => 5, 'mode' => 'submit']);
expect($controller->order_process()->getData()['code'] === 0, 'Batch submit must process a pending order');
Db::name('cert_order')->where('id', 5)->update(['status' => 1]);
requestPost($app, ['id' => 5, 'mode' => 'submit']);
expect($controller->order_process()->getData()['code'] === -1, 'Batch submit must reject an order advanced to verification');
requestPost($app, ['id' => 5, 'mode' => 'verify']);
expect($controller->order_process()->getData()['code'] === 0, 'Batch verify must process a pending verification');
expect(count(TestCertOrderService::$processed) === 2, 'Only valid status actions may invoke processing');

Db::name('cert_deploy')->insert(['id' => 61, 'oid' => 5, 'status' => 1]);
requestPost($app, ['id' => 61, 'mode' => 'batch']);
expect($controller->deploy_process()->getData()['code'] === 0, 'Batch execute must run completed tasks');
expect(TestCertDeployService::$reset === [61] && TestCertDeployService::$processed === [[61, true]], 'Completed task must reset and run once');
Db::name('cert_deploy')->where('id', 61)->update(['islock' => 1, 'locktime' => date('Y-m-d H:i:s')]);
requestPost($app, ['id' => 61, 'mode' => 'batch']);
expect($controller->deploy_process()->getData()['code'] === -1, 'Batch execute must reject a running task');
expect(count(TestCertDeployService::$processed) === 1, 'Running task must not be executed again');
Db::name('cert_deploy')->insert(['id' => 62, 'oid' => 5, 'status' => -1]);
requestPost($app, ['id' => 62, 'mode' => 'batch']);
expect($controller->deploy_process()->getData()['code'] === 0, 'Batch execute must retry a failed task');
expect(TestCertDeployService::$reset === [61] && count(TestCertDeployService::$processed) === 2, 'Failed task must run without a reset');

echo "Certificate order and batch checks passed\n";
