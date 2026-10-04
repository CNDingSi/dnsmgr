/* Run after PHP tests render DNSMGR_TEST_HTML; uses installed Chrome/Edge and Node's built-in CDP client. */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const htmlPath = process.env.DNSMGR_TEST_HTML || path.join(os.tmpdir(), 'dnsmgr-domain-test.html');
const browserPath = process.env.DNSMGR_TEST_BROWSER || [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'
].find(file => fs.existsSync(file));
if (!browserPath || !fs.existsSync(htmlPath)) throw new Error('Render DNSMGR_TEST_HTML with the PHP tests and install Chrome/Edge first');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'dnsmgr-browser-'));
let serial = 0, starts = [], stepCalls = [], resultRequests = [], jobs = new Map(), errors = [], browser;
const baseRows = Array.from({length: 125}, (_, i) => ({id: i + 1, aid: 1, name: `domain${i + 1}.com`,
    thirdid: `zone-${i + 1}`, typename: 'Cloudflare', icon: 'cloudflare.ico', recordcount: 0,
    addtime: '2026-10-05', checkstatus: 0, exist_status: i < 50 ? 'normal' : 'missing'}));
function report(job) {
    return {token: job.token, mode: job.mode, total: job.total, done: job.done, revision: job.revision,
        approved: job.approved, processed: job.results.length, counts: job.counts, tasks: {'容灾任务': 2},
        message: job.done ? '任务完成' : '正在处理'};
}
function json(response, value) { response.writeHead(200, {'content-type': 'application/json'}); response.end(JSON.stringify(value)); }
const server = http.createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/domain') { response.writeHead(200, {'content-type': 'text/html; charset=utf-8'}); response.end(fs.readFileSync(htmlPath)); return; }
        if (url.pathname.startsWith('/static/')) {
            const file = path.join(root, 'public', url.pathname);
            if (!fs.existsSync(file)) { response.writeHead(404); response.end(); return; }
            const type = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
            response.writeHead(200, {'content-type': type}); response.end(fs.readFileSync(file)); return;
        }
        let body = ''; for await (const chunk of request) body += chunk;
        const params = new URLSearchParams(body);
        if (url.pathname === '/domain/data') {
            const offset = Number(params.get('offset') || 0), limit = Number(params.get('limit') || 20);
            json(response, {total: baseRows.length, rows: baseRows.slice(offset, offset + limit)}); return;
        }
        const action = url.pathname.split('/').pop();
        if (action === 'start') {
            starts.push(Object.fromEntries(params));
            const mode = params.get('mode'), scope = params.get('scope');
            let targets = baseRows;
            if (scope === 'selected') targets = baseRows.filter(row => params.getAll('ids[]').map(Number).includes(row.id));
            if (mode === 'delete') targets = (scope === 'results_missing' ? jobs.get(params.get('source_job')).targets : targets).filter(row => row.exist_status === 'missing');
            const token = String(++serial).padStart(32, '0');
            const job = {token, mode, targets, total: targets.length, done: false, approved: mode === 'check', revision: 0,
                results: [], counts: {normal: 0, missing: 0, changed: 0, failed: 0, unsupported: 0, deleted: 0, skipped: 0}};
            jobs.set(token, job); json(response, {code: 0, data: report(job)}); return;
        }
        const job = jobs.get(params.get('token'));
        if (!job) { json(response, {code: -1, msg: '任务不存在', rows: [], total: 0}); return; }
        if (action === 'status') { json(response, {code: 0, data: report(job)}); return; }
        if (action === 'results') {
            const offset = Number(params.get('offset')), limit = Number(params.get('limit'));
            resultRequests.push({offset, limit});
            const rows = job.results.filter(row => !params.get('result_status') || row.status === params.get('result_status'));
            json(response, {code: 0, total: rows.length, rows: rows.slice(offset, offset + limit)}); return;
        }
        if (action === 'step') {
            stepCalls.push(job.mode);
            await new Promise(resolve => setTimeout(resolve, 180));
            if (Number(params.get('revision')) === job.revision && !job.done) {
                job.approved = true;
                for (const row of job.targets.slice(job.results.length, job.results.length + 50)) {
                    const status = job.mode === 'delete' ? 'deleted' : row.exist_status;
                    job.results.push({id: row.id, aid: row.aid, name: row.name, status, remote_id: row.thirdid,
                        message: '<img src=x onerror="window.__unsafe=true"> fixture result'});
                    job.counts[status]++;
                }
                job.revision++; job.done = job.results.length === job.total;
            }
            json(response, {code: 0, data: report(job)}); return;
        }
        response.writeHead(404); response.end();
    } catch (error) { errors.push(error.message); response.writeHead(500); response.end(error.message); }
});

class CDP {
    constructor(url) {
        this.next = 0; this.pending = new Map(); this.socket = new WebSocket(url);
        this.ready = new Promise(resolve => this.socket.addEventListener('open', resolve));
        this.socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.id && this.pending.has(message.id)) {
                const {resolve, reject} = this.pending.get(message.id); this.pending.delete(message.id);
                if (message.error) reject(new Error(message.error.message)); else resolve(message.result);
            }
            if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
        });
    }
    async send(method, params = {}) {
        await this.ready;
        const id = ++this.next;
        return new Promise((resolve, reject) => { this.pending.set(id, {resolve, reject}); this.socket.send(JSON.stringify({id, method, params})); });
    }
    async evaluate(expression) {
        const response = await this.send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
        return response.result.value;
    }
    async wait(expression) {
        for (let attempt = 0; attempt < 160; attempt++) {
            if (await this.evaluate(expression)) return;
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        throw new Error('Timed out: ' + expression);
    }
}

(async () => {
    let cdp;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const url = `http://127.0.0.1:${server.address().port}/domain`;
        browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--remote-debugging-port=0', '--user-data-dir=' + profile, '--window-size=1440,1100', 'about:blank'], {windowsHide: true, stdio: 'ignore'});
        const portFile = path.join(profile, 'DevToolsActivePort');
        for (let i = 0; !fs.existsSync(portFile) && i < 100; i++) await new Promise(resolve => setTimeout(resolve, 100));
        if (!fs.existsSync(portFile)) throw new Error('Chrome failed to start');
        const debugPort = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
        cdp = new CDP(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
        await cdp.send('Runtime.enable'); await cdp.send('Page.enable');
        await cdp.send('Page.navigate', {url});
        await cdp.wait("typeof domainExistence !== 'undefined' && $('#listTable').bootstrapTable('getData').length > 0");
        await cdp.evaluate("domainExistence.start('filtered')");
        await cdp.wait("$('#domainCheckPause').is(':visible')");
        await cdp.evaluate('domainExistence.startSingle(1); domainExistence.pause()');
        await new Promise(resolve => setTimeout(resolve, 300));
        assert.equal(starts.length, 1, 'Starting another task during an active request must be blocked');
        assert.equal([...jobs.values()][0].done, false, 'Pausing must stop the batch loop');
        await cdp.evaluate('layer.closeAll()');
        await cdp.send('Page.navigate', {url});
        await cdp.wait("typeof domainExistence !== 'undefined' && $('#listTable').bootstrapTable('getData').length > 0");
        await cdp.evaluate('domainExistence.openLast()');
        await cdp.wait("$('#domainCheckResume').is(':visible')");
        await cdp.evaluate('domainExistence.resume()');
        await cdp.wait("$('#domainCheckMessage').text().includes('125 / 125')");
        await cdp.wait("$('#domainCheckResults').bootstrapTable('getData').length === 50");
        await cdp.evaluate("void $('#domainCheckResults').bootstrapTable('selectPage', 2)");
        await cdp.wait("$('#domainCheckResults').bootstrapTable('getData')[0]?.id === 51");
        assert(resultRequests.some(request => request.offset === 50 && request.limit === 50), 'Result pages must send the correct server offset');
        assert.equal(await cdp.evaluate('Boolean(window.__unsafe)'), false, 'Provider result HTML must not execute');
        await cdp.evaluate("void $('#domainCheckResults').bootstrapTable('check', 0)");
        await cdp.evaluate("void $('#domainCheckResults').bootstrapTable('selectPage', 3)");
        await cdp.wait("$('#domainCheckResults').bootstrapTable('getData')[0]?.id === 101");
        await cdp.evaluate("void $('#domainCheckResults').bootstrapTable('check', 0)");
        assert.equal(await cdp.evaluate("$('#domainCheckSelectedCount').text()"), '已选 2 个未找到域名', 'Checkbox selection must persist across result pages');
        await cdp.evaluate('domainExistence.cleanupAllResults()');
        await cdp.wait("$('.layui-layer-dialog .layui-layer-btn0').length > 0");
        assert.equal(stepCalls.filter(mode => mode === 'delete').length, 0, 'Deletion must wait for confirmation');
        assert.equal(starts[1].scope, 'results_missing', 'All-missing cleanup must span saved results, not the current page');
        assert.equal([...jobs.values()][1].total, 75, 'Cleanup preview must include all missing results');
        await cdp.evaluate("void $('.layui-layer-dialog .layui-layer-btn0').last().click()");
        await cdp.wait("$('#domainCheckCounts').text().includes('已清理：75')");
        const screenshot = await cdp.send('Page.captureScreenshot', {format: 'png'});
        fs.writeFileSync(path.join(os.tmpdir(), 'dnsmgr-domain-check.png'), Buffer.from(screenshot.data, 'base64'));
        assert.deepEqual(errors, [], 'Page and API fixture must have no uncaught errors');
        console.log('PASS: Chrome UI checks (real template, pause/resume after reload, result pagination, escaped errors, confirmation and cross-page cleanup)');
    } finally {
        if (cdp) cdp.socket.close();
        if (browser) browser.kill();
        server.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
