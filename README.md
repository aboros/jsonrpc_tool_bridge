# JSON-RPC Tool Bridge

Exposes Drupal Tool API tools (provided by `drupal/tool`) as JSON-RPC 2.0
methods through `drupal/jsonrpc`. A `JsonRpcToolConfig` config entity maps a
Tool API tool to a method name, and a plugin deriver emits one `tool_api`
method derivative per enabled mapping.

It behaves like `drupal/mcp_server_tool_bridge`: the same mapping model, the
same execution flow, the same result shape and the same entity handle
handling. A client that already handles the MCP bridge's results can handle
these too. The differences are listed below.

## Requirements

- PHP 8.3
- Drupal 10.5 or 11
- `drupal/tool` 1.0.0-beta8 or later
- `drupal/jsonrpc` 3.x **with the derivative support patch** (see below)

### The jsonrpc patch

jsonrpc 3.x has no plugin derivers, takes every method's output schema from
its plugin class, and gives other modules no way to expire its method
definitions. The patch in `patches/jsonrpc-derivative-support.patch` adds all
three. It applies to jsonrpc 3.0.1 and to 3.x-dev. Until it is merged, copy it
into your project (here as `patches/jsonrpc-derivative-support.patch`) and
apply it with `cweagans/composer-patches`:

```json
{
  "require": {
    "cweagans/composer-patches": "^1.7",
    "drupal/jsonrpc": "^3.0",
    "drupal/jsonrpc_tool_bridge": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "cweagans/composer-patches": true
    }
  },
  "extra": {
    "patches": {
      "drupal/jsonrpc": {
        "Support derivers and per-definition output schemas": "patches/jsonrpc-derivative-support.patch"
      }
    }
  }
}
```

## Configuration

1. Enable the module.
2. Go to *Configuration » Web services » JSON-RPC tool configurations*
   (`/admin/config/services/jsonrpc/tools`) and add a configuration: a machine
   name, the Tool API tool ID (autocompleted), an optional description
   override, and whether it is enabled.
3. Grant permissions to the roles that may call tools.

### Permissions

| Permission | Purpose |
|---|---|
| `use jsonrpc services` (jsonrpc) | Reach the `/jsonrpc` endpoint at all. |
| `access jsonrpc tool bridge` | Call any `tool_api:*` method. Without it, a call is a JSON-RPC *Invalid Request* error. |
| `administer jsonrpc tool configurations` | Manage the mappings. Restricted. |

Each tool also runs its own `access()` check, exactly as over MCP. A denial
there is a result with `isError: true`, not a JSON-RPC error.

Which authentication providers the endpoint accepts (basic auth, cookie,
OAuth2, JWT) is configured on jsonrpc's own settings page.

## Methods

A configuration with machine name `my_tool` is the method `tool_api:my_tool`.
The `tool_api` method itself cannot be called.

The tool ID may spell the derivative separator `:` as `___`
(`module___tool` for `module:tool`), as in the MCP bridge.

### Parameters

Parameters are named, one per tool input, each with the JSON Schema Tool API
generates for that input (entities as handle strings, regex constraints
without PCRE delimiters, multiple values as arrays). jsonrpc validates them
before the tool runs; Tool API's own validation runs after.

A tool without inputs accepts `"params": {}` as well as no `params` member.

With `jsonrpc_discovery` enabled, `GET /jsonrpc/methods` lists every bridged
method with its usage, parameters and output schema.

### Results

Every outcome of running the tool is a JSON-RPC **result** with the shape of an
MCP `CallToolResult`:

```json
{
  "content": [
    {"type": "text", "text": "Successfully processed message 1 time(s)"},
    {"type": "text", "text": "{\n    \"result\": \"Echo: hi\"\n}"}
  ],
  "isError": false,
  "structuredContent": {"result": "Echo: hi"}
}
```

- `content[0]` is always the message, with hints appended (for example entity
  handle hints).
- On success, `structuredContent` holds the tool's outputs, and `content[1]`
  mirrors them as pretty-printed JSON. A tool without outputs sends only the
  message.
- On failure, `isError` is `true`, there is no `structuredContent`, and when a
  retry with different arguments could succeed (an input failure),
  `content[1]` echoes the input schema as `Input schema:\n{...}`. Access
  denials and runtime failures do not echo it.

Each method's output schema is this envelope, with the tool's outputs as the
`structuredContent` schema. jsonrpc validates every result against it.

JSON-RPC **errors** are only used for problems outside the tool, as MCP does:
an unknown method (-32601), parameters that do not match their schema or a
missing required parameter (-32602), and a missing `access jsonrpc tool
bridge` permission (-32600). jsonrpc keeps a generic `message` and puts the
detail in `error.data`.

### HTTP verbs and caching

jsonrpc accepts every method over POST and over GET (`?query=<json>`), and
caches GET responses. This module:

- refuses tools whose Tool API operation modifies data (`isModifying()`) when
  the endpoint is called over GET, with an `isError` result reading
  `Tool modifies data and must be called with POST.`;
- marks every bridged response uncacheable (`max-age: 0`, and the page cache
  kill switch for anonymous requests), whichever verb carried it.

Read-only tools may be called over GET.

## Entity handles

The bridge uses the invoker `jsonrpc` with the `EntitiesAsHandles` capability,
for both the advertised schemas and execution. Entity inputs take, and entity
outputs return, handle strings such as `handle:<uuid>`.

Tool API keeps handles in the private tempstore, keyed by the user (or, for
anonymous users, by their session). So:

- A handle returned by one call can be passed to a later call over the
  stateless `/jsonrpc` endpoint, as long as the same user authenticates, with
  any authentication provider.
- A handle issued to one user does not resolve for another.
- Handles are not tied to the invoker, so a handle issued over MCP is valid
  over JSON-RPC for the same user, and the other way round.
- Anonymous callers only keep handles across requests if they keep their
  session cookie.

## Differences from the MCP bridge

- **No progress or client gateway.** JSON-RPC has no channel back to the
  client, so tools implementing `ClientGatewayAwareInterface` get no gateway.
- **The GET rule** above. MCP is POST only.
- **No behavior hints.** MCP's `readOnly`, `destructive` and `idempotent`
  annotations have no place in a jsonrpc definition. Only `readOnly` has an
  effect, through the GET rule.
- **Unknown parameters are ignored.** jsonrpc only reads declared
  parameters, while the MCP input schema sets `additionalProperties: false`.
- **Error detail** is in `error.data`, not in `error.message`.
- **Empty `properties`** members are left out of the advertised schemas, so
  that `jsonrpc_discovery` cannot serialize them as `[]`. An empty
  `properties` constrains nothing, so the schemas mean the same.
- **Method names** are `tool_api:<id>` rather than `tool_api__<id>`, and are
  not limited to 64 characters.
- **A tool whose schema cannot be built** is left out and logged, rather than
  breaking every other JSON-RPC method on the site.

## Development

The tests include a parity test that runs the same calls through both bridges.
It is skipped unless `drupal/mcp_server_tool_bridge` is installed.

```bash
vendor/bin/phpunit -c phpunit.xml web/modules/contrib/jsonrpc_tool_bridge
```

The test fixture tools in `tests/modules/jsonrpc_tool_bridge_test` are copied
from `mcp_server_tool_bridge`'s `mcp_server_tool_test` module
(GPL-2.0-or-later).
