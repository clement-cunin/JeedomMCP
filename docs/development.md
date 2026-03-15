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

### 3. Implement the function

```php
function tool_my_tool(int $id): array {
    $obj = SomeClass::byId($id);
    if (!is_object($obj)) throw new Exception("Object {$id} not found");
    // ...
    return fmt_obj($obj);
}
```

### 4. Document in `docs/mcp-tools.md`

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

## Commit conventions

- `feat:` new tool or capability
- `fix:` bug fix
- `docs:` documentation only
- Tool changes and their `docs/mcp-tools.md` update must be in the **same commit**
