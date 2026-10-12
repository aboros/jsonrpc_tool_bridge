<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests how Tool API outcomes reach a client as JSON-RPC responses.
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
   * A successful call has the tool's outputs as its result, and nothing else.
   */
  public function testSuccessReturnsOutputs(): void {
    $body = $this->callTool('success', 'jsonrpc_tool_test:example', [
      'message' => 'hello / world',
      'prefix' => 'Echo ✓',
      'count' => 2,
    ]);

    $this->assertSame(['jsonrpc', 'id', 'result'], array_keys($body));
    $this->assertSame(['result' => 'Echo ✓: hello / world | Echo ✓: hello / world'], $body['result']);
  }

  /**
   * A success without outputs is an empty object, not an empty array.
   */
  public function testSuccessWithoutOutputsIsEmptyObject(): void {
    $this->callTool('no_outputs', 'jsonrpc_tool_test:quoted_message', ['title' => 'About us']);

    $this->assertStringContainsString('"result":{}', $this->lastResponseJson);
  }

  /**
   * Tool API access denial is an invalid request error.
   */
  public function testAccessDeniedIsInvalidRequest(): void {
    $body = $this->callTool('access_denied', 'jsonrpc_tool_test:access_denied', []);

    $this->assertSame('Tool plugin access denied: Denied by design.', $this->assertErrorResponse($body, Error::INVALID_REQUEST));
    $this->assertSame('Invalid Request', $body['error']['message']);
  }

  /**
   * An explicit Tool API failure keeps its category, Input by default.
   */
  public function testExplicitToolFailureIsInvalidParams(): void {
    $body = $this->callTool('explicit_failure', 'jsonrpc_tool_test:explicit_failure', []);

    $this->assertStringContainsString('Tool intentionally failed', $this->assertErrorResponse($body, Error::INVALID_PARAMS));
  }

  /**
   * An exception caught by the bridge is an internal error without detail.
   *
   * ThrowsOnAccessTool throws from checkAccess(), which ToolBase::access()
   * calls with no try/catch of its own, so it reaches ToolApi's catch block.
   */
  public function testCaughtExceptionIsInternalError(): void {
    $body = $this->callTool('throws_on_access', 'jsonrpc_tool_test:throws_on_access', []);

    $data = $this->assertErrorResponse($body, Error::INTERNAL_ERROR);
    $this->assertStringNotContainsString('Simulated failure', $data);
  }

  /**
   * PHP errors caught by the bridge are internal errors too.
   */
  #[DataProvider('errorKinds')]
  public function testCaughtErrorIsInternalError(string $kind): void {
    $body = $this->callTool('error_on_access', 'jsonrpc_tool_test:errors_on_access', ['kind' => $kind]);

    $this->assertErrorResponse($body, Error::INTERNAL_ERROR);
  }

  /**
   * Provides errors that are not instances of Exception.
   */
  public static function errorKinds(): array {
    return [
      'Error' => ['error'],
      'TypeError' => ['type_error'],
    ];
  }

  /**
   * Every element of a `multiple: TRUE` input reaches the tool.
   */
  public function testMultipleInputPassesEveryValue(): void {
    $body = $this->callTool('multi', 'jsonrpc_tool_test:multiple_string_input', [
      'fields' => ['title', 'status'],
    ]);

    $this->assertSame(['fields' => ['title', 'status']], $body['result']);
  }

  /**
   * A comma-joined string for a multiple input is rejected, not truncated.
   */
  public function testMultipleInputRejectsScalar(): void {
    $body = $this->callTool('multi_scalar', 'jsonrpc_tool_test:multiple_string_input', [
      'fields' => 'title,status',
    ]);

    $this->assertStringContainsString('fields', $this->assertErrorResponse($body, Error::INVALID_PARAMS));
  }

  /**
   * A malformed handle is invalid params.
   */
  public function testMalformedHandleIsInvalidParams(): void {
    $body = $this->callTool('malformed_handle', 'jsonrpc_tool_test:entity_round_trip', [
      'entity' => 'handle:not-a-uuid',
    ]);

    $this->assertStringContainsString('Tool plugin execution failed:', $this->assertErrorResponse($body, Error::INVALID_PARAMS));
  }

  /**
   * A runtime failure reported by the tool is an internal error with detail.
   */
  public function testWrappedRuntimeFailureIsInternalError(): void {
    $body = $this->callTool('wrapped_runtime', 'jsonrpc_tool_test:throwing', []);

    $this->assertStringContainsString('RuntimeException', $this->assertErrorResponse($body, Error::INTERNAL_ERROR));
  }

  /**
   * Parameter schema rejection is an invalid params error from jsonrpc.
   *
   * These calls never reach the bridge.
   */
  #[DataProvider('invalidArguments')]
  public function testSchemaRejectionIsInvalidParams(array $arguments): void {
    $body = $this->callTool('invalid_input', 'jsonrpc_tool_test:example', $arguments);

    $this->assertStringContainsString('message', $this->assertErrorResponse($body, Error::INVALID_PARAMS));
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
   */
  public function testNoInputToolAcceptsEmptyOrMissingParams(): void {
    $this->bridge('no_args', 'jsonrpc_tool_test:no_input');

    foreach ([[], NULL] as $params) {
      $body = $this->callOverHttp('no_args', $params);
      $this->assertArrayHasKey('result', $body, $this->lastResponseJson);
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
    $this->callTool('empty_maps', 'jsonrpc_tool_test:empty_maps', []);

    $this->assertStringContainsString('"result":{"settings":{},"entries":[{}],"document":{"settings":{}}}', $this->lastResponseJson);
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
   * A failed notification gets no response either.
   */
  public function testFailedNotificationGetsNoResponse(): void {
    $this->bridge('notified_denied', 'jsonrpc_tool_test:access_denied');

    $response = $this->postJsonRpc([
      'jsonrpc' => '2.0',
      'method' => 'tool_api:notified_denied',
    ]);

    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
  }

  /**
   * A batch can mix results and errors of different bridged methods.
   */
  public function testBatchOfBridgedMethods(): void {
    $this->bridge('batch_example', 'jsonrpc_tool_test:example');
    $this->bridge('batch_denied', 'jsonrpc_tool_test:access_denied');

    $this->postJsonRpc([
      ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tool_api:batch_example', 'params' => ['message' => 'one']],
      ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tool_api:batch_denied'],
    ]);
    $body = array_column(json_decode($this->lastResponseJson, TRUE, 512, JSON_THROW_ON_ERROR), NULL, 'id');

    $this->assertSame(['result' => 'Echo: one'], $body[1]['result']);
    $this->assertSame(Error::INVALID_REQUEST, $body[2]['error']['code']);
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
