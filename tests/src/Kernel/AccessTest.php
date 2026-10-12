<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two access layers of a bridged method.
 *
 * The method permission is checked by jsonrpc and denied with a JSON-RPC
 * error, as mcp_server denies a client without "access mcp server". The tool's
 * own access() is checked by ToolApi and denied with the same error code,
 * with the tool's reason as the data.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class AccessTest extends KernelTestBase {

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
    'serialization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Keep uid 1 out of the way so the acting users are not the superuser.
    $this->createUser([], 'superuser');
    $this->bridge('example', 'jsonrpc_tool_test:example');
    $this->bridge('denied', 'jsonrpc_tool_test:access_denied');
  }

  /**
   * Without the bridge permission, the call is an invalid request.
   */
  public function testMissingPermissionIsInvalidRequest(): void {
    $this->setCurrentUser($this->createUser(['use jsonrpc services']));

    $body = $this->callOverHttp('example', ['message' => 'hi']);

    $this->assertArrayNotHasKey('result', $body);
    $this->assertSame(-32600, $body['error']['code']);
    $this->assertStringContainsString('access jsonrpc tool bridge', $body['error']['data']);
    $this->assertArrayNotHasKey('tool_api:example', $this->handler()->availableMethods());
  }

  /**
   * With the bridge permission, a tool's own denial is an invalid request.
   */
  public function testToolAccessDenialIsAnErrorEnvelope(): void {
    $this->setCurrentUser($this->createUser(['use jsonrpc services', 'access jsonrpc tool bridge']));
    $this->assertArrayHasKey('tool_api:denied', $this->handler()->availableMethods());

    $body = $this->callOverHttp('denied', []);

    $this->assertSame('Tool plugin access denied: Denied by design.', $this->assertErrorResponse($body, Error::INVALID_REQUEST));
    $this->assertSuccessfulCall($this->callOverHttp('example', ['message' => 'hi']));
  }

  /**
   * Asserts a call returned a result.
   */
  private function assertSuccessfulCall(array $body): void {
    $this->assertArrayHasKey('result', $body, $this->lastResponseJson);
    $this->assertSame(['result' => 'Echo: hi'], $body['result']);
  }

}
