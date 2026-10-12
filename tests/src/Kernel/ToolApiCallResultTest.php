<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that Tool API outcomes are mapped onto the envelope's isError.
 *
 * Sends real JSON-RPC requests through jsonrpc's HTTP controller and inspects
 * the serialized response, so the checks cover jsonrpc's parameter
 * validation and its validation of the result against the method's output
 * schema, not only ToolApi::execute()'s return value.
 *
 * Ported from mcp_server_tool_bridge's ToolApiCallResultTest and
 * ToolApiWireTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class ToolApiCallResultTest extends KernelTestBase {

  use ToolApiTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonrpc',
    'jsonrpc_tool_bridge',
    'jsonrpc_tool_bridge_test',
    'tool',
    'user',
    'system',
    'node',
    'field',
    'text',
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], ['access jsonrpc tool bridge']);
  }

  /**
   * A successful Tool API call reports isError: false with its outputs.
   *
   * The outputs are the structuredContent, the message is the first text
   * block, and the outputs are mirrored as JSON in the second.
   */
  public function testSuccessIsNotAnError(): void {
    $body = $this->callTool('success', 'jsonrpc_tool_test:example', [
      'message' => 'hello / world',
      'prefix' => 'Echo ✓',
      'count' => 2,
    ]);

    $this->assertFalse($body['result']['isError']);
    $this->assertSame(['result' => 'Echo ✓: hello / world | Echo ✓: hello / world'], $body['result']['structuredContent']);
    $this->assertSame('Successfully processed message 2 time(s)', $this->resultMessage($body['result']));
    $this->assertContentMirrorsStructuredContent($body);
  }

  /**
   * Tool API access denial reports isError: true, not a silent success.
   */
  public function testAccessDeniedIsAnError(): void {
    $body = $this->callTool('access_denied', 'jsonrpc_tool_test:access_denied', []);

    $this->assertFailure($body, 'Tool plugin access denied: Denied by design.');
    $this->assertCount(1, $body['result']['content']);
  }

  /**
   * An explicit (non-thrown) Tool API failure reports isError: true.
   */
  public function testExplicitToolFailureIsAnError(): void {
    $body = $this->callTool('explicit_failure', 'jsonrpc_tool_test:explicit_failure', []);

    $this->assertStringContainsString('Tool intentionally failed', $this->assertFailure($body));
    $this->assertNotNull($this->echoedInputSchema($body['result']));
  }

  /**
   * An exception caught while executing the Tool API plugin is an error.
   *
   * ThrowsOnAccessTool throws from checkAccess(), which ToolBase::access()
   * calls with no try/catch of its own, so it reaches ToolApi's catch block.
   */
  public function testCaughtExceptionIsAnError(): void {
    $body = $this->callTool('throws_on_access', 'jsonrpc_tool_test:throws_on_access', []);

    $message = $this->assertFailure($body);
    $this->assertStringContainsString('Tool plugin execution failed: ', $message);
    $this->assertStringContainsString('Simulated failure while checking access', $message);
    $this->assertNull($this->echoedInputSchema($body['result']));
  }

  /**
   * PHP errors caught by the bridge also report a tool error.
   */
  #[DataProvider('errorKinds')]
  public function testCaughtErrorIsAnError(string $kind, string $message): void {
    $body = $this->callTool('error_on_access', 'jsonrpc_tool_test:errors_on_access', ['kind' => $kind]);

    $this->assertFailure($body, 'Tool plugin execution failed: ' . $message);
    $this->assertNull($this->echoedInputSchema($body['result']));
  }

  /**
   * Provides errors that are not instances of Exception.
   */
  public static function errorKinds(): array {
    return [
      'Error' => ['error', 'Simulated error while checking access'],
      'TypeError' => ['type_error', 'Simulated type error while checking access'],
    ];
  }

  /**
   * Every element of a `multiple: TRUE` input reaches the tool.
   */
  public function testMultipleInputPassesEveryValue(): void {
    $body = $this->callTool('multi', 'jsonrpc_tool_test:multiple_string_input', [
      'fields' => ['title', 'status'],
    ]);

    $this->assertFalse($body['result']['isError']);
    $this->assertSame(['fields' => ['title', 'status']], $body['result']['structuredContent']);
    $this->assertSame('Received 2 field name(s)', $this->resultMessage($body['result']));
  }

  /**
   * A comma-joined string for a multiple input is rejected, not truncated.
   */
  public function testMultipleInputRejectsScalar(): void {
    $body = $this->callTool('multi_scalar', 'jsonrpc_tool_test:multiple_string_input', [
      'fields' => 'title,status',
    ]);

    $this->assertArrayNotHasKey('result', $body);
    $this->assertSame(-32602, $body['error']['code']);
    // The jsonrpc module keeps the generic message and puts the detail in data.
    $this->assertStringContainsString('fields', $body['error']['data']);
  }

  /**
   * A correctable handle failure echoes its schema in a second text block.
   */
  public function testMalformedHandleIsAnErrorWithSchema(): void {
    $body = $this->callTool('malformed_handle', 'jsonrpc_tool_test:entity_round_trip', [
      'entity' => 'handle:not-a-uuid',
    ]);

    $this->assertStringContainsString('Tool plugin execution failed:', $this->assertFailure($body));
    $schema = $this->echoedInputSchema($body['result']);
    $this->assertSame('object', $schema['type']);
    $this->assertArrayHasKey('entity', $schema['properties']);
  }

  /**
   * A runtime failure caught inside Tool API is also a tool error.
   */
  public function testWrappedRuntimeFailureIsAnError(): void {
    $body = $this->callTool('wrapped_runtime', 'jsonrpc_tool_test:throwing', []);

    $this->assertStringContainsString('RuntimeException', $this->assertFailure($body));
    $this->assertNull($this->echoedInputSchema($body['result']));
  }

  /**
   * Parameter schema rejection remains a JSON-RPC invalid-params error.
   *
   * These calls never reach the bridge, so they must not be mistaken for a
   * failed Tool API execution wrapped in an envelope. The MCP SDK rejects
   * them the same way against the tool's inputSchema.
   */
  #[DataProvider('invalidArguments')]
  public function testSchemaRejectionIsProtocolError(array $arguments): void {
    $body = $this->callTool('invalid_input', 'jsonrpc_tool_test:example', $arguments);

    $this->assertArrayNotHasKey('result', $body);
    $this->assertSame(-32602, $body['error']['code']);
    // The jsonrpc module keeps the generic message and puts the detail in data.
    $this->assertStringContainsString('message', $body['error']['data']);
  }

  /**
   * Provides arguments rejected by the advertised parameter schemas.
   */
  public static function invalidArguments(): array {
    return [
      'missing required message' => [[]],
      'wrong message type' => [['message' => ['not a string']]],
    ];
  }

  /**
   * A tool without inputs accepts an empty params object or no params.
   *
   * MCP clients send "arguments": {} for such tools, so the bridge must not
   * reject it as "method does not accept parameters".
   */
  public function testNoInputToolAcceptsEmptyOrMissingParams(): void {
    $this->bridge('no_args', 'jsonrpc_tool_test:no_input');

    foreach ([[], NULL] as $params) {
      $body = $this->callOverHttp('no_args', $params);
      $this->assertArrayHasKey('result', $body, $this->lastResponseJson);
      $this->assertFalse($body['result']['isError']);
      $this->assertSame('Successfully executed without input', $this->resultMessage($body['result']));
    }
  }

  /**
   * Empty map outputs reach the client as objects.
   *
   * PHP writes an empty array as [], where the output schema says object.
   * Tool API sends an empty map as an object; nothing between ToolApi and the
   * JSON-RPC response may turn it back into an array.
   */
  public function testEmptyMapsOnTheWire(): void {
    $body = $this->callTool('empty_maps', 'jsonrpc_tool_test:empty_maps', []);

    $expected = '{"settings":{},"entries":[{}],"document":{"settings":{}}}';
    $this->assertStringContainsString('"structuredContent":' . $expected, $this->lastResponseJson);
    $this->assertFalse($body['result']['isError']);
    $this->assertCount(2, $body['result']['content']);
    $this->assertSame($expected, json_encode(json_decode($body['result']['content'][1]['text'], flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR));
  }

  /**
   * A notification still runs the tool, and gets no response.
   */
  public function testNotificationRunsTheTool(): void {
    $this->bridge('notified', 'jsonrpc_tool_test:example');

    $response = $this->postJsonRpc([
      'jsonrpc' => '2.0',
      'method' => 'tool_api:notified',
      'params' => ['message' => 'fire and forget'],
    ]);

    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
  }

  /**
   * A batch can mix bridged methods with different output schemas.
   */
  public function testBatchOfBridgedMethods(): void {
    $this->bridge('batch_example', 'jsonrpc_tool_test:example');
    $this->bridge('batch_denied', 'jsonrpc_tool_test:access_denied');

    $this->postJsonRpc([
      ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tool_api:batch_example', 'params' => ['message' => 'one']],
      ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tool_api:batch_denied'],
    ]);
    $body = array_column(json_decode($this->lastResponseJson, TRUE, 512, JSON_THROW_ON_ERROR), NULL, 'id');

    $this->assertFalse($body[1]['result']['isError']);
    $this->assertSame(['result' => 'Echo: one'], $body[1]['result']['structuredContent']);
    $this->assertTrue($body[2]['result']['isError']);
  }

  /**
   * Asserts a failed call: isError, no structuredContent, and the message.
   *
   * @return string
   *   The message from the first content block.
   */
  private function assertFailure(array $body, ?string $expected_message = NULL): string {
    $this->assertArrayHasKey('result', $body, $this->lastResponseJson);
    $message = $this->assertToolFailed($body['result']);
    if ($expected_message !== NULL) {
      $this->assertSame($expected_message, $message);
    }
    return $message;
  }

  /**
   * Asserts content[1].text is structuredContent, pretty-printed as JSON.
   *
   * The mirror block uses the encoding the MCP SDK uses for an array return
   * value: JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE.
   */
  private function assertContentMirrorsStructuredContent(array $body): void {
    $this->assertCount(2, $body['result']['content']);
    $this->assertSame('text', $body['result']['content'][1]['type']);
    $content_text = $body['result']['content'][1]['text'];
    $this->assertStringContainsString("\n", $content_text, 'content is expected to be pretty-printed JSON, not a single-line message.');
    $this->assertSame(
      $body['result']['structuredContent'],
      json_decode($content_text, TRUE, 512, JSON_THROW_ON_ERROR),
    );
    // Decode the original response without associative conversion so empty
    // objects stay objects rather than becoming empty arrays.
    $wire_response = json_decode($this->lastResponseJson, FALSE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(
      json_encode($wire_response->result->structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
      $content_text,
    );
  }

  /**
   * Creates a JsonRpcToolConfig, calls it over HTTP, and returns the body.
   *
   * @param string $id_suffix
   *   Suffix for the JsonRpcToolConfig id, so scenarios do not collide.
   * @param string $tool_id
   *   The Tool API plugin ID to bind.
   * @param array<string, mixed> $arguments
   *   The named parameters.
   *
   * @return array<string, mixed>
   *   The decoded JSON-RPC response body.
   */
  private function callTool(string $id_suffix, string $tool_id, array $arguments): array {
    $this->bridge('wire_test_' . $id_suffix, $tool_id);
    $body = $this->callOverHttp('wire_test_' . $id_suffix, $arguments);
    $this->assertSame(1, $body['id']);
    return $body;
  }

}
