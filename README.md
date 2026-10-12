# JSON-RPC Tool Bridge

Exposes Drupal Tool API tools (provided by `drupal/tool`) as JSON-RPC 2.0
methods through `drupal/jsonrpc`. A `JsonRpcToolConfig` config entity maps a
Tool API tool to a method name, and a plugin deriver emits one `tool_api`
method derivative per enabled mapping.

It is modelled on `drupal/mcp_server_tool_bridge`: the same mapping model,
the same execution flow and the same entity handle handling. Responses are
plain JSON-RPC: a successful call returns the tool's outputs as the result,
and a failed one is a JSON-RPC error. The differences are listed below.

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
there is the same *Invalid Request* error, with the tool's reason as the
data.

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

A successful call returns the tool's outputs, keyed by output name, as the
JSON-RPC `result`, and nothing else:

```json
{"jsonrpc": "2.0", "id": 1, "result": {"result": "Echo: hi"}}
```

A tool that returns no outputs has the result `{}`. The result message and
hints Tool API writes for models are not sent.

Each method's output schema is its tool's output schema (any object for a tool
without declared outputs), and jsonrpc validates every result against it.

### Errors

Every failure is a JSON-RPC **error**. The code follows the Tool API failure
category, using the codes jsonrpc itself uses; `message` is the generic text
for the code and `data` is the detail:

| Code | When | `data` |
|---|---|---|
| -32602 Invalid params | A parameter does not match its schema or a required one is missing (checked by jsonrpc); the tool's input validation fails; an entity handle is malformed, unknown or expired; the tool reports an input failure. | The violation or failure message. |
| -32600 Invalid request | The caller lacks `access jsonrpc tool bridge` (checked by jsonrpc); the tool's own `access()` denies; a modifying tool is called over GET. | The reason, e.g. `Tool plugin access denied: <reason>`. |
| -32603 Internal error | The tool reports a runtime failure; the bridge catches an unexpected exception. | The tool's failure message. For an exception the bridge catches, the message only when the site displays errors, as for any other JSON-RPC method. |
| -32601 Method not found | The method does not exist, or its configuration was removed or disabled. | The method name. |

Tool API's input schema is not echoed in errors: it is in the method's
parameters, available through `jsonrpc_discovery`.

The server does not reply to a notification, whether it succeeds or fails, but
the tool still runs.

### HTTP verbs and caching

jsonrpc accepts every method over POST and over GET (`?query=<json>`), and
caches GET responses. This module:

- refuses tools whose Tool API operation modifies data (`isModifying()`) when
  the endpoint is called over GET, with an *Invalid Request* error whose data
  reads `Tool modifies data and must be called with POST.`;
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

- **Native results and errors.** MCP returns a `CallToolResult` for every
  tool outcome: text content blocks with the message and hints, `isError`,
  and the outputs as `structuredContent`. This module returns the outputs
  alone as the result and every failure as a JSON-RPC error, without the
  message, the hints or the input schema echo.
- **No progress or client gateway.** JSON-RPC has no channel back to the
  client, so tools implementing `ClientGatewayAwareInterface` get no gateway.
- **The GET rule** above. MCP is POST only.
- **No behavior hints.** MCP's `readOnly`, `destructive` and `idempotent`
  annotations have no place in a jsonrpc definition. Only `readOnly` has an
  effect, through the GET rule.
- **Unknown parameters are ignored.** jsonrpc only reads declared
  parameters, while the MCP input schema sets `additionalProperties: false`.
- **Error detail** is in `error.data`, not in `error.message`, and the detail
  of an exception the bridge catches is hidden unless the site displays
  errors.
- **Empty `properties`** members are left out of the advertised schemas, so
  that `jsonrpc_discovery` cannot serialize them as `[]`. An empty
  `properties` constrains nothing, so the schemas mean the same.
- **Method names** are `tool_api:<id>` rather than `tool_api__<id>`, and are
  not limited to 64 characters.
- **A tool whose schema cannot be built** is left out and logged, rather than
  breaking every other JSON-RPC method on the site.

## Development

The tests include a parity test that runs the same calls through both bridges
and checks that a success returns the same outputs and a failure maps to the
expected error. It is skipped unless `drupal/mcp_server_tool_bridge` is
installed.

```bash
vendor/bin/phpunit -c phpunit.xml web/modules/contrib/jsonrpc_tool_bridge
```

The test fixture tools in `tests/modules/jsonrpc_tool_bridge_test` are copied
from `mcp_server_tool_bridge`'s `mcp_server_tool_test` module
(GPL-2.0-or-later).
