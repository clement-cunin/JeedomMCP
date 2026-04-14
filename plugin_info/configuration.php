<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

// Discover extensions (native and embedded) for all active plugins
ob_start();
$_all_plugins = plugin::listPlugin(true);
ob_end_clean();

$discovered_extensions = [];
foreach ($_all_plugins as $_p) {
    $_pid           = $_p->getId();
    if ($_pid === 'jeedomMCP') continue;
    $_native_file   = __DIR__ . "/../../{$_pid}/mcp/McpExtension.php";
    $_embedded_file = __DIR__ . "/../ext/{$_pid}/McpExtension.php";
    $_has_native    = file_exists($_native_file);
    $_has_embedded  = file_exists($_embedded_file);
    if (!$_has_native && !$_has_embedded) continue;
    $_file  = $_has_native ? $_native_file : $_embedded_file;
    $_type  = $_has_native ? 'native' : 'embedded';
    require_once $_file;
    $_class = $_pid . 'McpExtension';
    if (!class_exists($_class)) continue;
    $_tools = $_class::getTools();
    if (!is_array($_tools) || empty($_tools)) continue;
    $discovered_extensions[] = ['plugin_id' => $_pid, 'type' => $_type, 'tools' => $_tools];
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
    'admin_plugin_secrets' => ['read' => true, 'execution' => false, 'set_description' => false, 'create' => false, 'update' => true, 'delete' => false],
    'admin_logs'    => ['read' => true, 'execution' => false, 'set_description' => false, 'create' => false, 'update' => false, 'delete' => false],
    'admin_system'  => ['read' => true, 'execution' => false, 'set_description' => false, 'create' => false, 'update' => true,  'delete' => false],
];

// Default preset: "Read & Execute"
$acl_defaults = [
    'read' => '1', 'execution' => '1',
    'set_description' => '0', 'create' => '0', 'update' => '0', 'delete' => '0',
];

// Initialize acl_mode default
if (!in_array((string)config::byKey('acl_mode', 'jeedomMCP'), ['read_execute', 'read_execute_describe', 'full', 'full_admin', 'full_admin_secrets', 'custom'], true)) {
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
    'admin_plugin_secrets' => '{{Admin — Plugin Secrets}}',
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
        'read'   => ['plugins_list', 'plugin_market_list'],
        'create' => ['plugin_install'],
        'execution' => ['plugin_dependency_install', 'plugin_daemon_action'],
        'update' => ['plugin_set_active'],
        'delete' => ['plugin_uninstall'],
    ],
    'admin_plugin_secrets' => [
        'read'   => ['plugin_get_config'],
        'update' => ['plugin_set_config'],
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
                    <option value="full_admin_secrets">⚠️ {{Full access + Admin + Secret Management}}</option>
                    <option value="custom">{{Custom}}</option>
                </select>
            </div>
            <span class="help-block col-sm-4">{{In Custom mode, permissions are checked per operation using the table below.}}</span>
        </div>

        <div id="acl_secrets_warning" class="form-group" style="display:none">
            <div class="col-sm-offset-4 col-sm-8">
                <div class="alert alert-warning" style="margin-bottom:0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>{{Security warning}}</strong> — {{This mode grants access to sensitive plugin configuration including passwords, private keys, and API credentials. Only use this with fully trusted MCP clients over a secured connection.}}
                </div>
            </div>
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
                        <tr<?php echo (strncmp($domain, 'admin', 5) === 0) ? ' data-admin="1"' : ''; ?><?php echo ($domain === 'admin_plugin_secrets') ? ' data-secrets="1"' : ''; ?>>
                            <th style="vertical-align:top;padding-top:10px">
                                <?php echo $acl_domain_labels[$domain]; ?>
                                <?php if ($domain === 'admin_plugin_secrets'): ?>
                                <div><small class="text-warning" style="font-weight:normal">⚠️ {{Grants access to sensitive plugin configuration (passwords, certificates, API keys). Only enable for fully trusted clients.}}</small></div>
                                <?php endif; ?>
                            </th>
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
                    <?php foreach ($discovered_extensions as $ext):
                        $pid = $ext['plugin_id'];
                        $key = "acl_ext_{$pid}_execution";
                        $current = (string)config::byKey($key, 'jeedomMCP');
                        if (!in_array($current, ['0', '1'], true)) {
                            config::save($key, '0', 'jeedomMCP');
                        }
                    ?>
                        <tr data-ext="1">
                            <th style="vertical-align:top;padding-top:10px"><?php echo '{{Extensions}} — ' . htmlspecialchars($pid); ?></th>
                            <?php foreach (array_keys($acl_op_labels) as $op): ?>
                            <td class="text-center">
                                <?php if ($op === 'execution'): ?>
                                <input type="checkbox"
                                       class="configKey acl-checkbox"
                                       data-l1key="<?php echo $key; ?>"
                                       data-op="execution" />
                                <div><small class="text-muted">ext_<?php echo htmlspecialchars($pid); ?>_*</small></div>
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

        <legend><i class="fas fa-puzzle-piece"></i> {{Discovered extensions}}</legend>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <?php if (empty($discovered_extensions)): ?>
                <p class="text-muted">{{No extensions discovered. Extensions are loaded from plugins/{pluginId}/mcp/McpExtension.php (native) or plugins/jeedomMCP/ext/{pluginId}/McpExtension.php (embedded).}}</p>
                <?php else: ?>
                <table class="table table-bordered table-condensed" style="width:auto">
                    <thead>
                        <tr>
                            <th>{{Plugin}}</th>
                            <th>{{Type}}</th>
                            <th>{{Tools}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discovered_extensions as $ext):
                            $pid = htmlspecialchars($ext['plugin_id']);
                            $config_url = 'index.php?v=d&p=plugin&id=' . urlencode($ext['plugin_id']);
                        ?>
                        <tr>
                            <th style="vertical-align:top;padding-top:10px">
                                <?php echo $pid; ?>
                                <a href="<?php echo $config_url; ?>" title="{{Open plugin configuration}}" style="margin-left:6px">
                                    <i class="fas fa-cog"></i>
                                </a>
                            </th>
                            <td style="vertical-align:top;padding-top:10px">
                                <?php if ($ext['type'] === 'native'): ?>
                                <span class="label label-success">{{Native}}</span>
                                <?php else: ?>
                                <span class="label label-info">{{Embedded}}</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($ext['tools'] as $tool): ?>
                                <div><small class="text-muted" style="font-family:monospace">ext_<?php echo $pid; ?>_<?php echo htmlspecialchars($tool['name']); ?></small></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
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
        var _mode = $('#acl_mode').val();
        applyAclMode(_mode);
        $('#acl_secrets_warning').toggle(_mode === 'full_admin_secrets');
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
    full_admin:            ['read', 'execution', 'set_description', 'create', 'update', 'delete'],
    full_admin_secrets:    ['read', 'execution', 'set_description', 'create', 'update', 'delete']
};

function applyAclMode(mode) {
    var isCustom           = (mode === 'custom');
    var isFullAdminSecrets = (mode === 'full_admin_secrets');
    var isFullAdmin        = (mode === 'full_admin' || isFullAdminSecrets);
    var isFull             = (mode === 'full' || isFullAdmin);
    $('#acl_table_wrapper').css({
        'opacity':        isCustom ? '1'    : '0.5',
        'pointer-events': isCustom ? 'auto' : 'none'
    });
    if (!isCustom) {
        var ops = ACL_MODE_OPS[mode] || [];
        $('.acl-checkbox').each(function () {
            var $tr          = $(this).closest('tr');
            var isAdminRow   = $tr.data('admin') === 1;
            var isSecretsRow = $tr.data('secrets') === 1;
            var isExtRow     = $tr.data('ext') === 1;
            var checked;
            if (isExtRow) {
                checked = isFull;
            } else if (isSecretsRow) {
                checked = isFullAdminSecrets && ops.indexOf($(this).data('op')) !== -1;
            } else {
                checked = (!isAdminRow || isFullAdmin) && ops.indexOf($(this).data('op')) !== -1;
            }
            $(this).prop('checked', checked);
        });
    }
}

$('#acl_mode').on('change', function () {
    var mode = $(this).val();
    applyAclMode(mode);
    $('#acl_secrets_warning').toggle(mode === 'full_admin_secrets');
});
</script>
