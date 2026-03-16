# Development guide

## Architecture

JeedomMCP is a single PHP file (`api/mcp.php`) served directly by Apache. It implements the [MCP streamable-http transport](https://modelcontextprotocol.io) over JSON-RPC 2.0.

```
AI client  →  HTTPS  →  Apache  →  api/mcp.php  →  Jeedom PHP classes
```

Key files:

| File | Role |
|------|------|
| `api/mcp.php` | MCP server — tool registry, dispatcher, implementations |
| `core/class/JeedomMCP.class.php` | Plugin class — API key generation and lifecycle hooks |
| `core/ajax/JeedomMCP.ajax.php` | Admin AJAX — key regeneration from the UI |
| `plugin_info/configuration.php` | Plugin configuration page |

---

## Adding a new tool

Every tool requires four changes, all in the same commit.

### 1. Declare the schema in `mcp_get_tools()`

```php
[
    'name'        => 'my_tool',
    'description' => 'Short action-oriented description.',
    'inputSchema' => [
        'type'       => 'object',
        'properties' => [
            'my_id' => ['type' => 'integer', 'description' => 'ID from some_list.'],
        ],
        'required' => ['my_id'],
    ],
]
```

Use `enum` for parameters with a fixed set of values:

```php
'items' => ['type' => 'string', 'enum' => ['value_a', 'value_b', 'value_c']]
```

### 2. Dispatch in `mcp_call_tool()`

```php
case 'my_tool': return tool_result(tool_my_tool((int)($args['my_id'] ?? 0)));
```

Cast parameters at the dispatch level, not inside the implementation.

### 3. Add `acl_check()` at the top of the function

```php
function tool_my_tool(int $id): array {
    acl_check('domain', 'operation');  // e.g. acl_check('rooms', 'create')
    $obj = SomeClass::byId($id);
    if (!is_object($obj)) throw new Exception("Object {$id} not found");
    // ...
    return fmt_obj($obj);
}
```

Pick the domain (`devices`, `rooms`, `scenarios`) and the operation (`read`, `execution`, `set_description`, `create`, `update`, `delete`) that best describes the tool. `acl_check` throws if the operation is blocked — the dispatcher catches it and returns a tool error.

### 4. Register the tool in `acl_list`

Add an entry to `$tool_map` in `tool_acl_list()` so the new tool appears in the authorized tools list:

```php
'my_tool' => ['domain', 'operation'],
```

### 5. Document in `docs/mcp-tools.md`

Add a section following the existing format: description, parameters table, return example.

### 6. Document in `docs/mcp-tools.md`

Add a section following the existing format: description, parameters table, return example.

---

## Return value conventions

| Case | Expected return |
|------|----------------|
| Read (list or get) | Full object(s) via `fmt_*` helper |
| Write (create, update) | Full updated object via `fmt_*` — never just `{success: true}` |
| Delete | `['success' => true, 'x_id' => $id]` |
| Error | `throw new Exception("Descriptive message")` — caught by dispatcher |
| ID not found | `throw new Exception("X {$id} not found")` — never return `[]` |

Use shared `fmt_*` helpers so all tools that return the same entity produce identical output.

### Pagination — mandatory for all `*_list` tools

Every tool that returns a collection **must** be paginated. Standard signature:

```php
function tool_foo_list(int $limit = 50, int $offset = 0): array {
    $all = [];
    foreach (FooClass::all() as $item) {
        // optional filters here
        $all[] = fmt_foo($item);
    }
    $total = count($all);
    $items = $limit > 0 ? array_slice($all, $offset, $limit) : array_slice($all, $offset);
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}
```

Schema parameters to add to every list tool:

```php
'limit'  => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
'offset' => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
```

Dispatch:

```php
case 'foo_list': return tool_result(tool_foo_list(intval($args['limit'] ?? 50), intval($args['offset'] ?? 0)));
```

Rules:
- `limit = 0` means no limit (return all items from `offset`)
- Always return `total` (count before pagination) so the client can detect whether more pages exist
- Apply filters **before** `array_slice`, so `total` reflects the filtered count
- Document with the paginated JSON shape in `docs/mcp-tools.md`

---

## PHP 7.3 compatibility

The server runs PHP 7.3. Avoid:

```php
// ❌ PHP 7.4+ — arrow functions
array_filter($arr, fn($v) => $v == 1)

// ❌ PHP 8.0+ — mixed type hint
function foo(mixed $x): void {}

// ✅ PHP 7.3 equivalents
array_filter($arr, function($v) { return $v == 1; })
function foo($x): void {}
```

---

## Jeedom class gotchas

### `getCategory()` in Apache context

Objects loaded via `eqLogic::all()` (PDO `fetchAll`) do not expose individual category keys correctly through `getCategory('key')` in the Apache HTTP context. Always call without a key and filter in PHP:

```php
// ❌ Returns 0 for all devices in HTTP context
$eq->getCategory('light', 0)

// ✅ Works in all contexts
function active_categories($raw): array {
    if (!is_array($raw)) return [];
    return array_keys(array_filter($raw, function($v) { return $v == 1; }));
}
active_categories($eq->getCategory())
```

### `jeeObject` fields

`jeeObject` has no `setDescription()` method. Description, surface and orientation are stored in the `configuration` JSON:

| Field | Config key |
|-------|-----------|
| Description | `description` |
| Surface (m²) | `info::space` |
| Orientation | `info::orientation` |
| Icon | `display` JSON, key `icon` (HTML: `<i class="..."></i>`) |

```php
$obj->getConfiguration('description')
$obj->setConfiguration('info::space', '25')
$obj->getDisplay('icon')         // returns '<i class="icon maison-wc"></i>'
$obj->setDisplay('icon', '<i class="fas fa-home"></i>')
```

### `scenario::save` requires `name`

Always include the existing name when saving a partial update:

```php
$s = scenario::byId($id);
if (!isset($args['name'])) $args['name'] = $s->getName();
```

---

## ACL system

### How it works

Every tool implementation calls `acl_check(domain, operation)` as its first line. The check reads `acl_mode` from config and either approves or throws:

| Mode | Authorized operations |
|------|-----------------------|
| `read_execute` (default) | `read`, `execution` |
| `read_execute_describe` | `read`, `execution`, `set_description` |
| `full` | all operations |
| `custom` | per-key config — `acl_{domain}_{operation}`, default `'0'` (blocked) |

```php
function acl_check(string $domain, string $operation): void { ... }
```

### Default behavior for new tools

- **`acl_mode` default**: `read_execute` — new write tools are blocked until the admin explicitly changes the mode.
- **Custom mode default**: individual keys default to `'0'` (blocked) if never set.

This means a newly deployed tool is only accessible if its operation (`read` or `execution`) is covered by the current mode.

### Domains and operations

| Domain | Operations with tools |
|--------|-----------------------|
| `devices` | `read`, `execution`, `set_description` |
| `rooms` | `read`, `set_description`, `create`, `update`, `delete` |
| `scenarios` | `read`, `execution`, `set_description`, `create`, `update`, `delete` |

### `acl_list` tool

`acl_list` is a special tool with no `acl_check` — always accessible. It returns the current mode and the flat list of authorized tool names. When adding a new tool, register it in `$tool_map` inside `tool_acl_list()`.

---

## Commit conventions

- `feat:` new tool or capability
- `fix:` bug fix
- `docs:` documentation only
- Tool changes, their `docs/mcp-tools.md` update, and `acl_list` registration must be in the **same commit**
- **Every commit that changes user-visible behaviour must update `CHANGELOG.md`** — add a bullet under `## [Unreleased]`
