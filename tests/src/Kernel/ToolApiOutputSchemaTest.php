<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method\ToolApi;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the output schema bridged methods advertise and that results honor it.
 *
 * Each method's output schema is its tool's, describing the outputs keyed by
 * name. jsonrpc validates every result against it, so a result that broke
 * the schema would turn into an internal error.
 *
 * Ported from mcp_server_tool_bridge's ToolApiOutputSchemaTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class ToolApiOutputSchemaTest extends KernelTestBase {

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
    'tool_test',
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
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser([], ['access jsonrpc tool bridge', 'access content']);
  }

  /**
   * A tool with declared outputs advertises them as the output schema.
   */
  public function testOutputSchemaDescribesOutputs(): void {
    $this->bridge('greeting', 'greeting_tool');
    $schema = $this->getOutputSchema('greeting');

    $this->assertSame(['type', 'properties'], array_keys($schema));
    $this->assertSame('object', $schema['type']);
    $this->assertSame('string', $schema['properties']['message']['type']);
    $this->assertSame(
      'Message: The generated greeting message.',
      $schema['properties']['message']['description'],
    );
  }

  /**
   * A tool without declared outputs advertises any object.
   *
   * Such a tool may still pass undeclared values through, and returns an
   * empty object otherwise.
   */
  public function testToolWithoutOutputsAdvertisesAnyObject(): void {
    $this->bridge('no_args', 'jsonrpc_tool_test:no_input');

    $this->assertSame(['type' => 'object'], $this->getOutputSchema('no_args'));
    $this->assertSame(ToolApi::outputSchema(), $this->getOutputSchema('no_args'));
  }

  /**
   * A successful result validates against the advertised schema.
   *
   * The response carries the method's own schema, not the class-level one,
   * which is what jsonrpc validates the result against.
   */
  public function testSuccessResultValidates(): void {
    $this->bridge('greeting', 'greeting_tool');
    $response = $this->callMethod('greeting', ['name' => 'Ada']);

    $this->assertSame($this->getOutputSchema('greeting'), $response->getResultSchema());
    $result = $this->callResult('greeting', ['name' => 'Ada']);
    $this->assertSame('Hello, Ada!', $result['message']);
    $this->assertValidatesAgainstOutputSchema('greeting', $result);
  }

  /**
   * An access denial is an error, so there is no result for the schema.
   */
  public function testAccessDeniedHasNoResult(): void {
    $this->bridge('denied', 'jsonrpc_tool_test:access_denied');
    $this->assertArrayHasKey('secret', $this->getOutputSchema('denied')['properties']);

    $error = $this->callError('denied', [], Error::INVALID_REQUEST);

    $this->assertStringStartsWith('Tool plugin access denied', (string) $error->getData());
  }

  /**
   * An input failure is an invalid params error, not a result.
   */
  public function testInputFailureIsInvalidParams(): void {
    $this->bridge('greeting', 'greeting_tool');

    $this->callError('greeting', [], Error::INVALID_PARAMS);
  }

  /**
   * Multiple entity outputs are described as the handle strings they return.
   *
   * The deriver normalizes with the JSON-RPC invoker, so the entity handle
   * subscriber rewrites the entity output to a list of strings, matching
   * what getFormattedResult() produces at execution time.
   */
  public function testEntityListOutputIsDescribedAsHandles(): void {
    $this->bridge('entity_list', 'jsonrpc_tool_test:entity_list');
    $schema = $this->getOutputSchema('entity_list');

    $results = $schema['properties']['results'];
    $this->assertSame('array', $results['type']);
    $this->assertSame('string', $results['items']['type']);
    $this->assertStringContainsString('handle', $results['items']['description']);

    $node = Node::create(['type' => 'article', 'title' => 'First']);
    $node->save();
    $handle = $this->container->get('tool.handle_store')->store($node);

    $result = $this->callResult('entity_list', ['entities' => [$handle]]);

    $this->assertStringStartsWith('handle:', $result['results'][0]);
    $this->assertValidatesAgainstOutputSchema('entity_list', $result);
  }

  /**
   * Asserts a result validates against the method's output schema.
   *
   * Both sides go through JSON so the check sees what the wire carries, and
   * the validator is the one jsonrpc uses.
   */
  private function assertValidatesAgainstOutputSchema(string $config_id, array $result): void {
    $validator = new Validator();
    $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    $validator->validate($data, json_decode(json_encode($this->getOutputSchema($config_id), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR));
    $this->assertTrue($validator->isValid(), json_encode($validator->getErrors(), JSON_THROW_ON_ERROR));
  }

  /**
   * Reads the output schema advertised for a bridged tool.
   *
   * @return array<string, mixed>
   *   The output schema.
   */
  private function getOutputSchema(string $config_id): array {
    $method = $this->handler()->getMethod('tool_api:' . $config_id);
    $this->assertNotNull($method);
    $this->assertIsArray($method->output);
    return $method->output;
  }

}
