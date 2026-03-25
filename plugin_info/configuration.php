<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}
$mcpUrl = network::getNetworkAccess('external', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';

// One-time admin token valid 5 minutes (avoids relying on session in api/ context)
$adminNonce = bin2hex(random_bytes(16));
config::save('admin_nonce', $adminNonce . '|' . (time() + 300), 'jeedomMCP');

// ACL matrix: domain => [op => has_tool]
$acl_matrix = [
    'devices'    => ['read' => true, 'execution' => true, 'set_description' => true, 'create' => false, 'update' => true, 'delete' => false],
    'rooms'      => ['read' => true, 'execution' => false, 'set_description' => true, 'create' => true, 'update' => true, 'delete' => true],
    'scenarios'  => ['read' => true, 'execution' => true, 'set_description' => true, 'create' => true, 'update' => true, 'delete' => true],
    'admin_plugins' => ['read' => true, 'execution' => true, 'set_description' => false, 'create' => true,  'update' => true, 'delete' => true],
    'admin_logs'    => ['read' => true, 'execution' => false, 'set_description' => false, 'create' => false, 'update' => false, 'delete' => false],
    'admin_system'  => ['read' => true, 'execution' => false, 'set_description' => false, 'create' => false, 'update' => true,  'delete' => false],
];

// Default preset: "Read & Execute"
$acl_defaults = [
    'read' => '1', 'execution' => '1',
    'set_description' => '0', 'create' => '0', 'update' => '0', 'delete' => '0',
];

// Initialize acl_mode default
if (!in_array((string)config::byKey('acl_mode', 'jeedomMCP'), ['read_execute', 'read_execute_describe', 'full', 'full_admin', 'custom'], true)) {
    config::save('acl_mode', 'read_execute', 'jeedomMCP');
}

// Initialize custom per-op defaults (used when mode is custom)
foreach ($acl_matrix as $domain => $ops) {
    foreach ($ops as $op => $has_tool) {
        if (!$has_tool) continue;
        $key = "acl_{$domain}_{$op}";
        $current = (string)config::byKey($key, 'jeedomMCP');
        if (!in_array($current, ['0', '1'], true)) {
            config::save($key, $acl_defaults[$op], 'jeedomMCP');
        }
    }
}

$acl_domain_labels = [
    'devices'    => '{{Devices}}',
    'rooms'      => '{{Rooms}}',
    'scenarios'  => '{{Scenarios}}',
    'admin_plugins' => '{{Admin — Plugins}}',
    'admin_logs'    => '{{Admin — Logs}}',
    'admin_system'  => '{{Admin — System}}',
];
$acl_op_labels = [
    'read'            => '{{View / List}}',
    'execution'       => '{{Run commands}}',
    'set_description' => '{{Edit description}}',
    'create'          => '{{Create}}',
    'update'          => '{{Modify}}',
    'delete'          => '{{Delete}}',
];

// Tool names displayed inside each cell
$acl_tools = [
    'devices' => [
        'read'            => ['devices_list', 'device_state', 'devices_states'],
        'execution'       => ['command_execute'],
        'set_description' => ['device_set_description'],
        'update'          => ['device_update'],
    ],
    'rooms' => [
        'read'            => ['rooms_list'],
        'set_description' => ['room_set_description'],
        'create'          => ['room_create'],
        'update'          => ['room_update'],
        'delete'          => ['room_delete'],
    ],
    'scenarios' => [
        'read'            => ['scenarios_list', 'scenario_get_actions'],
        'execution'       => ['scenario_run'],
        'set_description' => ['scenario_set_description'],
        'create'          => ['scenario_create'],
        'update'          => ['scenario_update', 'scenario_set_actions'],
        'delete'          => ['scenario_delete'],
    ],
    'admin_plugins' => [
        'read'   => ['plugins_list', 'plugin_get_config', 'plugin_market_list'],
        'create' => ['plugin_install'],
        'execution' => ['plugin_dependency_install', 'plugin_daemon_action'],
        'update' => ['plugin_set_active', 'plugin_set_config'],
        'delete' => ['plugin_uninstall'],
    ],
    'admin_logs' => [
        'read' => ['logs_list', 'log_read'],
    ],
    'admin_system' => [
        'read'   => ['updates_list'],
        'update' => ['update_apply'],
    ],
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
            <label class="col-sm-4 control-label">{{ACL mode}}</label>
            <div class="col-sm-4">
                <select id="acl_mode" class="configKey form-control" data-l1key="acl_mode">
                    <option value="read_execute">{{Read &amp; Execute}}</option>
                    <option value="read_execute_describe">{{Read, Execute &amp; Set description}}</option>
                    <option value="full">{{Full access}}</option>
                    <option value="full_admin">{{Full access + Admin}}</option>
                    <option value="custom">{{Custom}}</option>
                </select>
            </div>
            <span class="help-block col-sm-4">{{In Custom mode, permissions are checked per operation using the table below.}}</span>
        </div>

        <div class="form-group">
            <div id="acl_table_wrapper" class="col-sm-offset-4 col-sm-8">
                <table class="table table-bordered table-condensed" style="width:auto">
                    <thead>
                        <tr>
                            <th></th>
                            <?php foreach ($acl_op_labels as $op_label): ?>
                            <th class="text-center"><?php echo $op_label; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($acl_matrix as $domain => $ops): ?>
                        <tr<?php echo (strncmp($domain, 'admin', 5) === 0) ? ' data-admin="1"' : ''; ?>>
                            <th style="vertical-align:top;padding-top:10px"><?php echo $acl_domain_labels[$domain]; ?></th>
                            <?php foreach (array_keys($acl_op_labels) as $op): ?>
                            <td class="text-center">
                                <?php if (!empty($ops[$op])): ?>
                                <input type="checkbox"
                                       class="configKey acl-checkbox"
                                       data-l1key="acl_<?php echo $domain; ?>_<?php echo $op; ?>"
                                       data-op="<?php echo $op; ?>" />
                                <?php foreach ($acl_tools[$domain][$op] as $tool_name): ?>
                                <div><small class="text-muted"><?php echo $tool_name; ?></small></div>
                                <?php endforeach; ?>
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
var adminNonce = '<?php echo $adminNonce; ?>';

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
        // Config values are fully loaded — apply table state based on mode
        applyAclMode($('#acl_mode').val());
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
            url: 'plugins/jeedomMCP/api/mcp.php?action=generateApiKey',
            data: { nonce: adminNonce },
            dataType: 'json',
            success: function (data) {
                if (!data.success) {
                    $.fn.showAlert({ message: data.error || '{{Error}}', level: 'danger' });
                    return;
                }
                $('#inp_mcpApiKey').val(data.key);
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
// ACL mode
// ---------------------------------------------------------------------------

var ACL_MODE_OPS = {
    read_execute:          ['read', 'execution'],
    read_execute_describe: ['read', 'execution', 'set_description'],
    full:                  ['read', 'execution', 'set_description', 'create', 'update', 'delete'],
    full_admin:            ['read', 'execution', 'set_description', 'create', 'update', 'delete']
};

function applyAclMode(mode) {
    var isCustom    = (mode === 'custom');
    var isFullAdmin = (mode === 'full_admin');
    $('#acl_table_wrapper').css({
        'opacity':        isCustom ? '1'    : '0.5',
        'pointer-events': isCustom ? 'auto' : 'none'
    });
    if (!isCustom) {
        var ops = ACL_MODE_OPS[mode] || [];
        $('.acl-checkbox').each(function () {
            var isAdminRow = $(this).closest('tr').data('admin') === 1;
            $(this).prop('checked', (!isAdminRow || isFullAdmin) && ops.indexOf($(this).data('op')) !== -1);
        });
    }
}

$('#acl_mode').on('change', function () {
    applyAclMode($(this).val());
});
</script>
