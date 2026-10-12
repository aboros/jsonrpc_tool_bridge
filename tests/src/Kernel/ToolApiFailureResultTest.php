<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\HandlerInterface;
use Drupal\jsonrpc\JsonRpcMethodDefinition;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\jsonrpc\JsonRpcObject\ParameterBag;
use Drupal\jsonrpc\JsonRpcObject\Request as RpcRequest;
use Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method\ToolApi;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the errors ToolApi::execute() returns for failed tool calls.
 *
 * The error code follows the Tool API failure category. Invalid input must be
 * reported as invalid params with the violation detail, distinct from genuine
 * access denials, so clients can fix their parameters (Tool API #3582961
 * fails access() closed on invalid input, which would otherwise mask
 * validation failures as permission problems).
 *
 * Ported from mcp_server_tool_bridge's ToolApiFailureResultTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class ToolApiFailureResultTest extends KernelTestBase {

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
   * Tests a derivative without a backing config entity is not found.
   */
  public function testMissingToolIdIsMethodNotFound(): void {
    $definition = new JsonRpcMethodDefinition([
      'id' => 'tool_api:missing_tool_id',
      'class' => ToolApi::class,
      'provider' => 'jsonrpc_tool_bridge',
      'usage' => new TranslatableMarkup('A derivative without a backing config entity.'),
    ]);
    $plugin = ToolApi::create($this->container, [
      HandlerInterface::JSONRPC_REQUEST_KEY => new RpcRequest('2.0', 'tool_api:missing_tool_id', FALSE, 1),
    ], 'tool_api:missing_tool_id', $definition);

    $error = $plugin->execute(new ParameterBag([]))->getError();

    $this->assertInstanceOf(Error::class, $error);
    $this->assertSame(Error::METHOD_NOT_FOUND, $error->getCode());
    $this->assertStringContainsString('tool_api:missing_tool_id', (string) $error->getData());
  }

  /**
   * Tests a missing required input is invalid params with the violations.
   */
  public function testValidationFailureReportsViolations(): void {
    $data = (string) $this->executeToolApi('regex_constraint', [], Error::INVALID_PARAMS)->getData();

    $this->assertStringContainsString('Tool plugin execution failed:', $data);
    $this->assertStringContainsString('username', $data);
  }

  /**
   * Tests a constraint-violating value is invalid params, not a denial.
   */
  public function testInvalidValueIsNotReportedAsAccessDenial(): void {
    $data = (string) $this->executeToolApi('regex_constraint', ['username' => 'not valid!'], Error::INVALID_PARAMS)->getData();

    $this->assertStringContainsString('Tool plugin execution failed:', $data);
    $this->assertStringNotContainsString('access denied', $data);
  }

  /**
   * Tests a genuine access denial is an invalid request with the reason.
   *
   * The jsonrpc module answers a missing method permission with the same
   * code. The reason from AccessResult::forbidden() is surfaced so clients
   * know why access was denied (#3582989).
   */
  public function testAccessDenialIsInvalidRequest(): void {
    $error = $this->executeToolApi('access_denied', [], Error::INVALID_REQUEST);

    $this->assertSame('Tool plugin access denied: Denied by design.', $error->getData());
  }

  /**
   * Tests a runtime failure reported by the wrapped tool is internal.
   *
   * ThrowingTool's doExecute() throws a \RuntimeException. ToolBase catches
   * it and records a Runtime failure result, so this reaches the bridge
   * through the formatted result, not the bridge's own catch blocks. The
   * message is the tool's own, so it is sent.
   */
  public function testRuntimeFailureIsInternalError(): void {
    $error = $this->executeToolApi('throwing', [], Error::INTERNAL_ERROR);

    $this->assertStringContainsString('RuntimeException', (string) $error->getData());
  }

  /**
   * Tests an exception caught by the bridge only shows its detail on request.
   *
   * ThrowsOnAccessTool throws from checkAccess(), which ToolBase::access()
   * calls with no try/catch of its own, so it reaches ToolApi's catch block.
   * As for any other exception in a JSON-RPC method, the message is only
   * sent when the site displays errors.
   */
  public function testCaughtExceptionHidesDetailUnlessErrorsAreShown(): void {
    $error = $this->executeToolApi('throws_on_access', [], Error::INTERNAL_ERROR);
    $this->assertSame(Error::$errorMeanings[Error::INTERNAL_ERROR], $error->getData());

    $this->config('system.logging')->set('error_level', ERROR_REPORTING_DISPLAY_ALL)->save();
    $error = $this->callError('throws_on_access', [], Error::INTERNAL_ERROR);
    $this->assertSame('Tool plugin execution failed: Simulated failure while checking access', $error->getData());
  }

  /**
   * Tests a malformed entity handle is invalid params.
   *
   * Setting the input dispatches the input transform event, which throws a
   * handle exception for a handle-prefixed string with an invalid suffix.
   * The client can fix it by sending a valid handle.
   *
   * @see \Drupal\tool\Handle\HandleExceptionInterface
   */
  public function testMalformedHandleIsInvalidParams(): void {
    $error = $this->executeToolApi('entity_round_trip', ['entity' => 'handle:not-a-uuid'], Error::INVALID_PARAMS);

    $this->assertStringContainsString('Tool plugin execution failed:', (string) $error->getData());
  }

  /**
   * Tests a success returns the outputs as the result.
   */
  public function testSuccessReturnsOutputs(): void {
    $this->bridge('example', 'jsonrpc_tool_test:example');

    $result = $this->callResult('example', [
      'message' => 'hi',
      'prefix' => 'Echo',
      'count' => 1,
    ]);
    $this->assertSame(['result' => 'Echo: hi'], $result);
  }

  /**
   * Tests a success without outputs returns an empty object.
   */
  public function testSuccessWithoutOutputsIsEmptyObject(): void {
    $this->bridge('quoted_message', 'jsonrpc_tool_test:quoted_message');

    $result = $this->callMethod('quoted_message', ['title' => 'About "us"'])->getResult();
    $this->assertInstanceOf(\stdClass::class, $result);
    $this->assertSame('{}', json_encode($result));
  }

  /**
   * Tests violation messages reach the client as plain text.
   *
   * The constraint puts the value in a placeholder, which escapes quotes and
   * wraps the value in an <em> tag (#3618726).
   */
  public function testViolationMessageIsPlainText(): void {
    $error = $this->executeToolApi('quoted_message', ['title' => "Team's page"], Error::INVALID_PARAMS);

    $this->assertStringContainsString('Title "Team\'s page" must start with "About".', (string) $error->getData());
  }

  /**
   * Tests access denial reasons reach the client as the tool gave them.
   *
   * The reason is a plain string, so text that looks like a tag or an entity
   * is literal text and must not be stripped or decoded (#3618726).
   */
  public function testAccessDenialReasonKeepsLiteralText(): void {
    $error = $this->executeToolApi('quoted_message', [
      'title' => 'About <us> &amp; "team"',
      'locked' => TRUE,
    ], Error::INVALID_REQUEST);

    $this->assertSame('Tool plugin access denied: About <us> &amp; "team" is locked.', $error->getData());
  }

  /**
   * Executes a bridged tool that is expected to fail.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID; also derives the backing tool ID.
   * @param array<string, mixed> $arguments
   *   The JSON-RPC parameters.
   * @param int $code
   *   The expected error code.
   */
  private function executeToolApi(string $config_id, array $arguments, int $code): Error {
    $this->bridge($config_id, 'jsonrpc_tool_test:' . $config_id);
    return $this->callError($config_id, $arguments, $code);
  }

}
