<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}
$mcpUrl = network::getNetworkAccess('external', 'proto:ip:port:comp') . '/plugins/JeedomMCP/api/mcp.php';

// ACL matrix: domain => [op => has_tool]
$acl_matrix = [
    'devices'   => ['read' => true, 'execution' => true, 'set_description' => true, 'create' => false, 'update' => false, 'delete' => false],
    'rooms'     => ['read' => true, 'execution' => false, 'set_description' => true, 'create' => true, 'update' => true, 'delete' => true],
    'scenarios' => ['read' => true, 'execution' => true, 'set_description' => true, 'create' => true, 'update' => true, 'delete' => true],
];

// Default preset: "Read & Execute"
$acl_defaults = [
    'read' => '1', 'execution' => '1',
    'set_description' => '0', 'create' => '0', 'update' => '0', 'delete' => '0',
];

// Initialize config values on first load (robust check: only skip if already '0' or '1')
foreach ($acl_matrix as $domain => $ops) {
    foreach ($ops as $op => $has_tool) {
        if (!$has_tool) continue;
        $key = "acl_{$domain}_{$op}";
        $current = (string)config::byKey($key, 'JeedomMCP');
        if (!in_array($current, ['0', '1'], true)) {
            config::save($key, $acl_defaults[$op], 'JeedomMCP');
        }
    }
}

$acl_domain_labels = [
    'devices'   => '{{Devices}}',
    'rooms'     => '{{Rooms}}',
    'scenarios' => '{{Scenarios}}',
];
$acl_op_labels = [
    'read'            => '{{Read}}',
    'execution'       => '{{Execute}}',
    'set_description' => '{{Set description}}',
    'create'          => '{{Create}}',
    'update'          => '{{Update}}',
    'delete'          => '{{Delete}}',
];
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

        <legend><i class="fas fa-shield-alt"></i> {{Tool permissions}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Preset}}</label>
            <div class="col-sm-4">
                <select id="acl_preset" class="form-control">
                    <option value="read_execute">{{Read &amp; Execute}}</option>
                    <option value="read_execute_describe">{{Read, Execute &amp; Set description}}</option>
                    <option value="full">{{Full access}}</option>
                    <option value="custom">{{Custom}}</option>
                </select>
            </div>
            <span class="help-block col-sm-4">{{Quick preset — applies to all domains at once.}}</span>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-1 col-sm-11">
                <table class="table table-bordered table-condensed" style="width:auto">
                    <thead>
                        <tr>
                            <th></th>
                            <?php foreach ($acl_op_labels as $op_label): ?>
                            <th class="text-center" style="min-width:90px"><?php echo $op_label; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($acl_matrix as $domain => $ops): ?>
                        <tr>
                            <th style="vertical-align:middle"><?php echo $acl_domain_labels[$domain]; ?></th>
                            <?php foreach ($ops as $op => $has_tool): ?>
                            <td class="text-center" style="vertical-align:middle;<?php echo $has_tool ? '' : 'background:#f5f5f5;'; ?>">
                                <?php if ($has_tool): ?>
                                <input type="checkbox"
                                       class="configKey acl-checkbox"
                                       data-l1key="acl_<?php echo $domain; ?>_<?php echo $op; ?>"
                                       data-op="<?php echo $op; ?>" />
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </fieldset>
</form>

<script>
var mcpUrl = '<?php echo $mcpUrl; ?>';

// ---------------------------------------------------------------------------
// MCP JSON preview
// ---------------------------------------------------------------------------

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

var mcpJsonPollInterval = setInterval(function () {
    if ($('#inp_mcpApiKey').val()) {
        updateMcpJsonPreview();
        clearInterval(mcpJsonPollInterval);
        // Config values are loaded — sync the preset selector
        detectAclPreset();
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

// ---------------------------------------------------------------------------
// ACL preset selector
// ---------------------------------------------------------------------------

var ACL_PRESETS = {
    read_execute:          ['read', 'execution'],
    read_execute_describe: ['read', 'execution', 'set_description'],
    full:                  ['read', 'execution', 'set_description', 'create', 'update', 'delete']
};

function applyAclPreset(presetKey) {
    var ops = ACL_PRESETS[presetKey];
    if (!ops) return;
    $('.acl-checkbox').each(function () {
        $(this).prop('checked', ops.indexOf($(this).data('op')) !== -1);
    });
}

function detectAclPreset() {
    for (var presetKey in ACL_PRESETS) {
        var ops = ACL_PRESETS[presetKey];
        var matches = true;
        $('.acl-checkbox').each(function () {
            if ($(this).prop('checked') !== (ops.indexOf($(this).data('op')) !== -1)) {
                matches = false;
                return false;
            }
        });
        if (matches) {
            $('#acl_preset').val(presetKey);
            return;
        }
    }
    $('#acl_preset').val('custom');
}

$('#acl_preset').on('change', function () {
    var val = $(this).val();
    if (val !== 'custom') {
        applyAclPreset(val);
    }
});

$('.acl-checkbox').on('change', function () {
    detectAclPreset();
});
</script>
