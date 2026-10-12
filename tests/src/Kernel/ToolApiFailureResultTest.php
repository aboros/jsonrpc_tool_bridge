<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\HandlerInterface;
use Drupal\jsonrpc\JsonRpcMethodDefinition;
use Drupal\jsonrpc\JsonRpcObject\ParameterBag;
use Drupal\jsonrpc\JsonRpcObject\Request as RpcRequest;
use Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method\ToolApi;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the failure results returned by ToolApi::execute().
 *
 * Invalid input must surface violation detail with the input schema echoed,
 * distinct from genuine access denials, so clients can self-correct their
 * arguments (Tool API #3582961 fails access() closed on invalid input, which
 * would otherwise mask validation failures as permission problems).
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
   * Tests a missing tool ID returns an error envelope without input schema.
   */
  public function testMissingToolIdIsAnError(): void {
    $definition = new JsonRpcMethodDefinition([
      'id' => 'tool_api:missing_tool_id',
      'class' => ToolApi::class,
      'provider' => 'jsonrpc_tool_bridge',
      'usage' => new TranslatableMarkup('A derivative without a backing config entity.'),
    ]);
    $plugin = ToolApi::create($this->container, [
      HandlerInterface::JSONRPC_REQUEST_KEY => new RpcRequest('2.0', 'tool_api:missing_tool_id', FALSE, 1),
    ], 'tool_api:missing_tool_id', $definition);

    $result = $plugin->execute(new ParameterBag([]))->getResult();

    $this->assertSame('ToolApi derivative is missing its tool_id definition.', $this->assertToolFailed($result));
    $this->assertNull($this->echoedInputSchema($result));
  }

  /**
   * Tests a missing required input fails with violations and the schema.
   */
  public function testValidationFailureReportsViolationsAndSchema(): void {
    $result = $this->executeToolApi('regex_constraint', []);

    $message = $this->assertToolFailed($result);
    $this->assertStringContainsString('Tool plugin execution failed:', $message);
    $this->assertStringContainsString('username', $message);
    $schema = $this->echoedInputSchema($result);
    $this->assertSame('object', $schema['type']);
    $this->assertArrayHasKey('username', $schema['properties']);
  }

  /**
   * Tests a constraint-violating value fails with violations, not a denial.
   */
  public function testInvalidValueIsNotReportedAsAccessDenial(): void {
    $result = $this->executeToolApi('regex_constraint', ['username' => 'not valid!']);

    $message = $this->assertToolFailed($result);
    $this->assertStringContainsString('Tool plugin execution failed:', $message);
    $this->assertStringNotContainsString('access denied', $message);
    $this->assertNotNull($this->echoedInputSchema($result));
  }

  /**
   * Tests a genuine access denial is reported as such, without the schema.
   *
   * The reason from AccessResult::forbidden() is surfaced so clients know
   * why access was denied (#3582989). The schema is omitted (#3582993): a
   * retry with different arguments cannot fix an access denial.
   */
  public function testAccessDenialReportsDenialWithoutSchema(): void {
    $result = $this->executeToolApi('access_denied', []);

    $this->assertSame('Tool plugin access denied: Denied by design.', $this->assertToolFailed($result));
    $this->assertNull($this->echoedInputSchema($result));
  }

  /**
   * Tests a runtime exception from the wrapped tool omits the schema.
   *
   * ThrowingTool's doExecute() throws a \RuntimeException. ToolBase catches
   * it and records a Runtime failure result, so this reaches the bridge
   * through the formatted result, not the bridge's own catch blocks.
   */
  public function testRuntimeExceptionOmitsInputSchema(): void {
    $result = $this->executeToolApi('throwing', []);

    $this->assertStringContainsString('RuntimeException', $this->assertToolFailed($result));
    $this->assertNull($this->echoedInputSchema($result));
  }

  /**
   * Tests a malformed entity handle keeps the input schema.
   *
   * Setting the input dispatches the input transform event, which throws a
   * handle exception for a handle-prefixed string with an invalid suffix.
   * That is an input problem the client can fix, so the schema is kept.
   *
   * @see \Drupal\tool\Handle\HandleExceptionInterface
   */
  public function testMalformedHandleKeepsInputSchema(): void {
    $result = $this->executeToolApi('entity_round_trip', [
      'entity' => 'handle:not-a-uuid',
    ]);

    $this->assertStringContainsString('Tool plugin execution failed:', $this->assertToolFailed($result));
    $this->assertSame('object', $this->echoedInputSchema($result)['type']);
  }

  /**
   * Tests successful executions carry the outputs and no input schema echo.
   */
  public function testSuccessCarriesOutputsAndNoInputSchema(): void {
    $result = $this->executeToolApi('example', ['message' => 'hi', 'prefix' => 'Echo', 'count' => 1]);

    $outputs = $this->assertToolSucceeded($result);
    $this->assertSame(['result' => 'Echo: hi'], $outputs);
    $this->assertCount(2, $result['content']);
    $this->assertSame($outputs, json_decode((string) $result['content'][1]['text'], TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests violation messages reach the client as plain text.
   *
   * The constraint puts the value in a placeholder, which escapes quotes and
   * wraps the value in an <em> tag (#3618726).
   */
  public function testViolationMessageIsPlainText(): void {
    $result = $this->executeToolApi('quoted_message', ['title' => "Team's page"]);

    $message = $this->assertToolFailed($result);
    $this->assertStringContainsString('Title "Team\'s page" must start with "About".', $message);
  }

  /**
   * Tests access denial reasons reach the client as the tool gave them.
   *
   * The reason is a plain string, so text that looks like a tag or an entity
   * is literal text and must not be stripped or decoded (#3618726).
   */
  public function testAccessDenialReasonKeepsLiteralText(): void {
    $result = $this->executeToolApi('quoted_message', [
      'title' => 'About <us> &amp; "team"',
      'locked' => TRUE,
    ]);

    $this->assertSame('Tool plugin access denied: About <us> &amp; "team" is locked.', $this->assertToolFailed($result));
  }

  /**
   * Tests result messages reach the client as plain text (#3618726).
   */
  public function testResultMessageIsPlainText(): void {
    $result = $this->executeToolApi('quoted_message', ['title' => 'About "us"']);

    $this->assertToolSucceeded($result);
    $this->assertSame('Saved About "us".', $result['content'][0]['text']);
  }

  /**
   * Executes a bridged tool through ToolApi with the given arguments.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID; also derives the backing tool ID.
   * @param array<string, mixed> $arguments
   *   The JSON-RPC parameters.
   *
   * @return array<string, mixed>
   *   The result envelope.
   */
  private function executeToolApi(string $config_id, array $arguments): array {
    $this->bridge($config_id, 'jsonrpc_tool_test:' . $config_id);
    return $this->execute($config_id, $arguments);
  }

}
