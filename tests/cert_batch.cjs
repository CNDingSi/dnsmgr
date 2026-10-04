/** Run: node tests/cert_batch.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
for (const page of ['certorder.html', 'deploytask.html']) {
    const html = fs.readFileSync(path.join(root, 'app/view/cert', page), 'utf8');
    const inline = html.match(/<script>([\s\S]*?)<\/script>/);
    assert.ok(inline, page + ' must contain its action script');
    new vm.Script(inline[1], {filename: page});
}

const requests = [];
const alerts = [];
const progress = [];
let refreshes = 0;
const layer = {
    open: () => 1,
    close: () => {},
    alert: (message) => alerts.push(message)
};
function $(selector) {
    if (selector === '#listTable') return {bootstrapTable: () => refreshes++};
    if (selector === '#certBatchProgress') return {text: (value) => progress.push(value)};
    if (selector === '<div>') {
        let value = '';
        return {text: function (text) { value = text; return this; }, html: () => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')};
    }
    throw new Error('Unexpected selector ' + selector);
}
$.ajax = (options) => {
    const handlers = {};
    requests.push({options, handlers});
    return {
        done(callback) { handlers.done = callback; return this; },
        fail(callback) { handlers.fail = callback; return this; },
        always(callback) { handlers.always = callback; return this; }
    };
};
const context = {layer, $, console};
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/static/js/cert-batch.js'), 'utf8'), context);
context.runCertBatch([{id: 1}, {id: 2}, {id: 3}], {
    title: '批量验证', url: '/cert/order/process', skipped: 1,
    data: (row) => ({id: row.id, mode: 'verify'})
});
assert.equal(requests.length, 1, 'Only one request may run initially');
assert.equal(requests[0].options.data.mode, 'verify');
requests[0].handlers.done({code: 0});
requests[0].handlers.always();
assert.equal(requests.length, 2, 'Second request starts after first completes');
requests[1].handlers.done({code: -1, msg: '<error>'});
requests[1].handlers.always();
assert.equal(requests.length, 3, 'A failed item does not stop the batch');
requests[2].handlers.fail({}, 'timeout');
requests[2].handlers.always();
assert.equal(refreshes, 1, 'The list refreshes once after the batch');
assert.equal(progress.length, 3, 'Every selected item updates progress');
assert.match(alerts[0], /成功 1 个，失败 2 个，跳过 1 个/);
assert.match(alerts[0], /&lt;error&gt;/);
assert.match(alerts[0], /请求超时/);
console.log('Certificate batch UI checks passed');
