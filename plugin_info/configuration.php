<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}
$mcpUrl = network::getNetworkAccess('external', 'proto:ip:port:comp') . '/plugins/JeedomMCP/api/mcp.php';
?>

<form class="form-horizontal">
    <fieldset>

        <legend><i class="fas fa-key"></i> {{MCP API key}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{MCP API key}}</label>
            <div class="col-sm-4 input-group">
                <input type="text" class="configKey form-control" data-l1key="mcpApiKey" id="inp_mcpApiKey" readonly />
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default" id="bt_regenerateApiKey" title="{{Regenerate API key}}">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" class="btn btn-default" id="bt_copyApiKey" title="{{Copy to clipboard}}">
                        <i class="fas fa-copy"></i>
                    </button>
                </span>
            </div>
            <span class="help-block col-sm-4">{{Key to provide in your MCP client configuration (X-API-Key header). Regenerating invalidates existing connections.}}</span>
        </div>

        <legend><i class="fas fa-plug"></i> {{Client configuration}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{.mcp.json}}</label>
            <div class="col-sm-7">
                <div class="input-group" style="width:100%">
                    <pre id="mcp_json_preview" style="background:#f5f5f5;border:1px solid #ccc;border-radius:4px;padding:10px;margin:0;flex:1;font-size:12px;white-space:pre-wrap;word-break:break-all"></pre>
                    <span class="input-group-btn" style="vertical-align:top">
                        <button type="button" class="btn btn-default" id="bt_copyMcpJson" title="{{Copy to clipboard}}">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
            </div>
        </div>

    </fieldset>
</form>

<script>
var mcpUrl = '<?php echo $mcpUrl; ?>';

function buildMcpJson() {
    var apikey = $('#inp_mcpApiKey').val() || '';
    var config = {
        mcpServers: {
            jeedom: {
                type: 'http',
                url: mcpUrl,
                headers: { 'X-API-Key': apikey }
            }
        }
    };
    return JSON.stringify(config, null, 2);
}

function updateMcpJsonPreview() {
    $('#mcp_json_preview').text(buildMcpJson());
}

$('#inp_mcpApiKey').on('change', updateMcpJsonPreview);

// Jeedom loads config values asynchronously after DOM ready — poll until populated
var mcpJsonPollInterval = setInterval(function () {
    if ($('#inp_mcpApiKey').val()) {
        updateMcpJsonPreview();
        clearInterval(mcpJsonPollInterval);
    }
}, 100);

$('#bt_copyMcpJson').on('click', function () {
    navigator.clipboard.writeText(buildMcpJson()).then(function () {
        $.fn.showAlert({ message: '{{Configuration copied to clipboard.}}', level: 'success' });
    });
});

$('#bt_regenerateApiKey').on('click', function () {
    bootbox.confirm('{{Regenerate the API key? All existing MCP client connections will be invalidated.}}', function (result) {
        if (!result) return;
        $.ajax({
            type: 'POST',
            url: 'plugins/JeedomMCP/core/ajax/JeedomMCP.ajax.php',
            data: { action: 'generateApiKey' },
            dataType: 'json',
            success: function (data) {
                if (data.state !== 'ok') {
                    $.fn.showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                $('#inp_mcpApiKey').val(data.result);
                updateMcpJsonPreview();
                $.fn.showAlert({ message: '{{API key regenerated successfully.}}', level: 'success' });
            },
            error: function (jqXHR) {
                $.fn.showAlert({ message: jqXHR.responseText, level: 'danger' });
            }
        });
    });
});

$('#bt_copyApiKey').on('click', function () {
    var key = $('#inp_mcpApiKey').val();
    if (!key) return;
    navigator.clipboard.writeText(key).then(function () {
        $.fn.showAlert({ message: '{{API key copied to clipboard.}}', level: 'success' });
    });
});
</script>
