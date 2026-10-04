(function (global, $) {
    'use strict';
    var job = null, running = false, inFlight = false, tableReady = false;
    var selectedResults = new Set();
    var labels = {unchecked: '未检测', normal: '正常', missing: '远端未找到', changed: '域名 ID 不一致',
        failed: '检测失败', unsupported: '暂不支持', deleted: '已清理', skipped: '已跳过'};
    var colors = {normal: 'success', missing: 'warning', changed: 'info', failed: 'danger', deleted: 'success'};

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character];
        });
    }
    function key() { return 'domain_existence_job_' + global.domainCheckUserId; }
    function remember(token) {
        try { global.localStorage.setItem(key(), token); } catch (e) { /* Storage may be disabled. */ }
    }
    function request(action, data) {
        return new Promise(function (resolve, reject) {
            $.ajax({type: 'POST', url: '/domain/check/' + action, data: data, dataType: 'json',
                success: function (response) {
                    if (response.code === 0) resolve(response.data);
                    else reject(new Error(response.msg || '请求失败'));
                },
                error: function () { reject(new Error('网络请求失败；任务进度已保留，可以继续任务')); }
            });
        });
    }
    function errorMessage(error) { layer.alert(escapeHtml(error.message || error), {icon: 2}); }
    function filters() {
        var data = {};
        $('#searchToolbar').find(':input[name]').each(function () { data[this.name] = $(this).val(); });
        return data;
    }
    function selected(table, missingOnly) {
        return $(table).bootstrapTable('getSelections').filter(function (row) {
            return !missingOnly || (row.exist_status || row.status) === 'missing';
        }).map(function (row) { return row.id; });
    }
    function render() {
        if (!job) return;
        var percent = Math.floor(job.processed * 100 / job.total);
        $('#domainCheckTitle').text(job.mode === 'delete' ? '复查并清理本地域名' : '域名存在性检测');
        $('#domainCheckProgress').css('width', percent + '%').text(percent + '%');
        $('#domainCheckMessage').text(job.message + '（' + job.processed + ' / ' + job.total + '）');
        $('#domainCheckCounts').text(Object.keys(job.counts).map(function (status) {
            return labels[status] + '：' + job.counts[status];
        }).join('，'));
        $('#domainCheckPause').toggle(running && !job.done);
        $('#domainCheckResume').toggle(!running && !job.done);
        $('#domainCheckCleanupSelected, #domainCheckCleanupAll').prop('disabled', running || !job.done || job.mode !== 'check');
    }
    function selectionCount() { $('#domainCheckSelectedCount').text('已选 ' + selectedResults.size + ' 个未找到域名'); }
    function initTable() {
        if (tableReady) return;
        tableReady = true;
        $('#domainCheckResults').bootstrapTable({
            method: 'post', contentType: 'application/x-www-form-urlencoded', sidePagination: 'server',
            pagination: true, pageSize: 50, pageList: [20, 50, 100], uniqueId: 'id', maintainMetaData: true,
            queryParamsType: 'limit',
            height: 420, showToggle: false, showFullscreen: false,
            toolbar: '#domainCheckResultToolbar', search: false, showColumns: false, showRefresh: false,
            queryParams: function (params) {
                return {token: job ? job.token : '', offset: params.offset, limit: params.limit,
                    result_status: $('#domainCheckResultStatus').val()};
            },
            columns: [
                {checkbox: true, formatter: function (value, row) { return {disabled: row.status !== 'missing', checked: selectedResults.has(row.id)}; }},
                {field: 'name', title: '域名', escape: true}, {field: 'aid', title: '账户'},
                {field: 'status', title: '结果', formatter: function (value, row) { return api.statusHtml(value, row.message); }},
                {field: 'remote_id', title: '远端 ID', escape: true}, {field: 'message', title: '详情', escape: true}
            ],
            responseHandler: function (response) {
                if (response.code !== 0) { errorMessage(new Error(response.msg)); return {total: 0, rows: []}; }
                return response;
            },
            onCheck: function (row) { if (row.status === 'missing') selectedResults.add(row.id); selectionCount(); },
            onUncheck: function (row) { selectedResults.delete(row.id); selectionCount(); },
            onCheckAll: function () {
                $('#domainCheckResults').bootstrapTable('getData').forEach(function (row) { if (row.status === 'missing') selectedResults.add(row.id); });
                selectionCount();
            },
            onUncheckAll: function () {
                $('#domainCheckResults').bootstrapTable('getData').forEach(function (row) { selectedResults.delete(row.id); });
                selectionCount();
            }
        });
    }
    function showJob(next) {
        job = next;
        remember(job.token);
        initTable();
        selectedResults.clear();
        selectionCount();
        $('#domainCheckResultStatus').val('');
        $('#domainCheckResults').bootstrapTable('uncheckAll');
        $('#domainCheckResults').bootstrapTable('refreshOptions', {url: '/domain/check/results', pageNumber: 1});
        $('#domainCheckModal').modal('show');
        render();
    }
    async function loop() {
        if (!job || job.done || inFlight) return;
        running = true;
        render();
        while (running && !job.done) {
            inFlight = true;
            try {
                var previous = job.processed;
                job = await request('step', {token: job.token, revision: job.revision, confirmed: 1});
                if (previous !== job.processed) $('#domainCheckResults').bootstrapTable('refresh');
                if (job.done) { running = false; searchRefresh(); }
                render();
            } catch (error) {
                running = false;
                render();
                errorMessage(error);
            } finally { inFlight = false; }
        }
        render();
    }
    function confirmDelete() {
        var taskText = Object.keys(job.tasks).map(function (label) { return label + ' ' + job.tasks[label] + ' 个'; }).join('，');
        var text = '将复查并尝试删除 ' + job.total + ' 个本地域名。关联数据：' + escapeHtml(taskText)
            + '。<br>仅清理复查后仍然远端未找到的域名。Cloudflare 等服务商中的域名和 DNS 记录不受影响。'
            + '<br>请确认这些域名已不需要在本系统中管理；当前凭据无访问权限也可能导致未找到。';
        layer.confirm(text, {title: '确认本地清理', icon: 0}, function (index) { layer.close(index); loop(); });
    }
    async function begin(params) {
        if (running || inFlight) { layer.msg('当前任务正在执行，请先暂停再开始新任务'); return; }
        var loading = layer.load(2);
        var next = null;
        inFlight = true;
        try {
            next = await request('start', params);
            showJob(next);
        } catch (error) { next = null; errorMessage(error); }
        finally { inFlight = false; layer.close(loading); }
        if (next) { if (job.mode === 'delete') confirmDelete(); else loop(); }
    }
    var api = {
        statusHtml: function (status, message) {
            return '<span class="label label-' + (colors[status] || 'default') + '" title="' + escapeHtml(message || '') + '">'
                + escapeHtml(labels[status] || labels.unchecked) + '</span>';
        },
        start: function (scope) {
            var data = scope === 'filtered' ? filters() : {ids: selected('#listTable', false)};
            if (scope === 'selected' && !data.ids.length) { layer.msg('请选择要检测的域名'); return; }
            begin(Object.assign(data, {mode: 'check', scope: scope}));
        },
        startSingle: function (id) { begin({mode: 'check', scope: 'selected', ids: [id]}); },
        chooseAccount: function () { $('#domainCheckAccountModal').modal('show'); },
        startAccount: function () {
            var aid = $('#domainCheckAccount').val();
            if (!aid) { layer.msg('请先添加域名账户'); return; }
            $('#domainCheckAccountModal').modal('hide');
            begin({mode: 'check', scope: 'account', aid: aid});
        },
        cleanupSingle: function (id) { begin({mode: 'delete', scope: 'selected', ids: [id]}); },
        cleanupSelected: function () {
            var ids = selected('#listTable', true);
            if (!ids.length) { layer.msg('请选择检测状态为“远端未找到”的域名'); return; }
            begin({mode: 'delete', scope: 'selected', ids: ids});
        },
        cleanupFiltered: function () { begin(Object.assign(filters(), {mode: 'delete', scope: 'filtered'})); },
        cleanupResults: function () {
            if (!job || !job.done || job.mode !== 'check') return;
            var ids = Array.from(selectedResults);
            if (!ids.length) { layer.msg('请勾选要清理的未找到域名'); return; }
            begin({mode: 'delete', scope: 'selected', ids: ids});
        },
        cleanupAllResults: function () {
            if (!job || !job.done || job.mode !== 'check') return;
            if (!job.counts.missing) { layer.msg('没有远端未找到的域名'); return; }
            begin({mode: 'delete', scope: 'results_missing', source_job: job.token});
        },
        pause: function () { running = false; render(); },
        resume: async function () {
            if (!job || inFlight) { layer.msg('请等待当前请求完成'); return; }
            try {
                job = await request('status', {token: job.token});
                render();
                if (job.mode === 'delete' && !job.approved) confirmDelete(); else loop();
            } catch (error) { errorMessage(error); }
        },
        openLast: async function () {
            if (inFlight) { $('#domainCheckModal').modal('show'); return; }
            if (job) { $('#domainCheckModal').modal('show'); render(); return; }
            var token;
            try { token = global.localStorage.getItem(key()); } catch (e) { /* Storage may be disabled. */ }
            if (!token) { layer.msg('没有可继续的检测任务'); return; }
            try { showJob(await request('status', {token: token})); } catch (error) { errorMessage(error); }
        },
        detail: function (id) {
            var row = $('#listTable').bootstrapTable('getRowByUniqueId', id);
            if (!row) return;
            layer.alert('域名：' + escapeHtml(row.name) + '<br>状态：' + escapeHtml(labels[row.exist_status] || labels.unchecked)
                + '<br>检测时间：' + escapeHtml(row.exist_checked_at || '-') + '<br>本地 ID：' + escapeHtml(row.thirdid || '-')
                + '<br>远端 ID：' + escapeHtml(row.exist_remote_id || '-') + '<br>详情：' + escapeHtml(row.exist_message || '尚未检测'),
                {title: '域名检测详情'});
        }
    };
    global.domainExistence = api;
    $(function () {
        if (global.userLevel !== '2') return;
        $('#domainCheckModal').on('hide.bs.modal', api.pause);
        $('#domainCheckResultStatus').on('change', function () {
            selectedResults.clear();
            selectionCount();
            $('#domainCheckResults').bootstrapTable('uncheckAll');
            $('#domainCheckResults').bootstrapTable('selectPage', 1);
            $('#domainCheckResults').bootstrapTable('refresh');
        });
    });
})(window, jQuery);
