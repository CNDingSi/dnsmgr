// Run long-running certificate actions one at a time so each request can finish
// within the normal HTTP timeout and each selected row gets its own result.
function runCertBatch(rows, options) {
    var done = 0;
    var succeeded = [];
    var failed = [];
    var progress = layer.open({
        type: 1,
        title: options.title,
        closeBtn: 0,
        shadeClose: false,
        area: ['360px', '125px'],
        content: '<div style="padding:20px" id="certBatchProgress"></div>'
    });

    function finish() {
        layer.close(progress);
        $('#listTable').bootstrapTable('refresh');
        var summary = '成功 ' + succeeded.length + ' 个，失败 ' + failed.length + ' 个' + (options.skipped ? '，跳过 ' + options.skipped + ' 个' : '') + '。';
        if (failed.length) {
            summary += '<div style="max-height:220px;overflow:auto;margin-top:10px;text-align:left">';
            failed.forEach(function(item) {
                summary += '<div>ID ' + item.id + '：' + $('<div>').text(item.message).html() + '</div>';
            });
            summary += '</div>';
        }
        layer.alert(summary, {title: options.title + '结果', icon: failed.length ? 0 : 1});
    }

    function next() {
        if (done >= rows.length) return finish();
        var row = rows[done];
        $('#certBatchProgress').text('正在处理第 ' + (done + 1) + ' / ' + rows.length + ' 个（ID ' + row.id + '）');
        $.ajax({
            type: 'POST',
            url: options.url,
            data: options.data(row),
            dataType: 'json'
        }).done(function(result) {
            if (result && result.code == 0) {
                succeeded.push(row.id);
            } else {
                failed.push({id: row.id, message: result && result.msg ? result.msg : '请求失败'});
            }
        }).fail(function(xhr, status) {
            failed.push({id: row.id, message: status === 'timeout' ? '请求超时，请刷新列表或查看日志确认结果' : '网络请求失败'});
        }).always(function() {
            done++;
            next();
        });
    }

    next();
}
