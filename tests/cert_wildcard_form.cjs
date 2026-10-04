/** Run: node tests/cert_wildcard_form.cjs */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '../app/view/cert/order_form.html'), 'utf8');
assert.match(html, /v-model="addWildcard"/, 'Add form must expose the wildcard option');
let script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
script = script.replace("var action = '{$action}';", "var action = 'add';")
    .replace('var info = {$info|json_encode|raw};', 'var info = null;')
    .replace("action: '{$action}'", "action: 'add'");

let form;
let request;
let separateDomains;
const validator = {validate() {}, isValid() { return true; }};
function $() { return {data: () => validator}; }
$.ajax = (options) => { request = options.data; };
const layer = {
    load: () => 1,
    closeAll: () => {},
    confirm: (message, options, callback) => callback(),
};
vm.runInNewContext(script, {Vue: function (options) { form = options; }, $, layer});

const state = {
    action: 'add', addWildcard: true,
    set: {aid: '1', domains: [' example.com ', '*.already.com', 'second.com', '*.example.com']},
};
Object.defineProperty(state, 'preparedDomains', {get: () => form.computed.preparedDomains.call(state)});
state.orderPayload = form.methods.orderPayload;
state.submit = form.methods.submit;
state.doSubmitEach = (domains) => { separateDomains = domains; };

assert.deepEqual(Array.from(state.preparedDomains), ['*.example.com', '*.already.com', '*.second.com']);
state.submit();
assert.deepEqual(Array.from(request.domains), ['*.example.com', '*.already.com', '*.second.com'], 'Normal submit must post wildcard domains');
assert.equal(state.set.domains[0], ' example.com ', 'Preparing the payload must not modify the input');
form.methods.submitEach.call(state);
assert.deepEqual(Array.from(separateDomains), ['*.example.com', '*.already.com', '*.second.com'], 'Separate submit must use the same transformed domains');

state.addWildcard = false;
assert.deepEqual(Array.from(state.preparedDomains), ['example.com', '*.already.com', 'second.com', '*.example.com'], 'Unchecking must restore plain domains');
state.addWildcard = true;
state.action = 'edit';
assert.deepEqual(Array.from(state.preparedDomains), ['example.com', '*.already.com', 'second.com', '*.example.com'], 'Editing must not apply the add-only option');
state.action = 'add';
state.set.aid = '-1';
assert.deepEqual(Array.from(state.preparedDomains), ['example.com', '*.already.com', 'second.com', '*.example.com'], 'Manual certificate uploads must not apply the option');
state.set.aid = '1';
$.ajax = (options) => { request = options.data; options.success({code: 0}); };
form.methods.submitOne.call(state, state.preparedDomains[0]).then(() => {
    assert.deepEqual(Array.from(request.domains), ['*.example.com'], 'Individual request must post the transformed domain');
    console.log('Certificate wildcard form checks passed');
}).catch((error) => { console.error(error); process.exitCode = 1; });
