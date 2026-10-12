<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
 * Each method advertises the result envelope, with the tool's outputs as the
 * structuredContent schema. jsonrpc validates every result against it, so a
 * result that broke the schema would turn into an internal error.
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
   * A tool with declared outputs advertises them as structuredContent.
   */
  public function testOutputSchemaDescribesOutputs(): void {
    $this->bridge('greeting', 'greeting_tool');
    $schema = $this->getOutputSchema('greeting');

    $this->assertSame(['content', 'isError'], $schema['required']);
    $structured = $schema['properties']['structuredContent'];
    $this->assertSame(['type', 'properties'], array_keys($structured));
    $this->assertSame('object', $structured['type']);
    $this->assertSame('string', $structured['properties']['message']['type']);
    $this->assertSame(
      'Message: The generated greeting message.',
      $structured['properties']['message']['description'],
    );
    $this->assertSame(ToolApi::envelopeSchema($structured), $schema);
  }

  /**
   * A tool without declared outputs leaves structuredContent undescribed.
   */
  public function testToolWithoutOutputsHasNoStructuredContentSchema(): void {
    $this->bridge('no_args', 'jsonrpc_tool_test:no_input');
    $schema = $this->getOutputSchema('no_args');

    $this->assertArrayNotHasKey('structuredContent', $schema['properties']);
    $this->assertSame(ToolApi::outputSchema(), $schema);
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
    $result = $response->getResult();
    $outputs = $this->assertToolSucceeded($result);
    $this->assertSame('Hello, Ada!', $outputs['message']);
    $this->assertValidatesAgainstOutputSchema('greeting', $result);
  }

  /**
   * An access denial carries no structuredContent for the schema to bind.
   */
  public function testAccessDeniedResultHasNoStructuredContent(): void {
    $this->bridge('denied', 'jsonrpc_tool_test:access_denied');
    $this->assertArrayHasKey('structuredContent', $this->getOutputSchema('denied')['properties']);

    $result = $this->execute('denied');

    $this->assertStringStartsWith('Tool plugin access denied', $this->assertToolFailed($result));
    $this->assertNull($this->echoedInputSchema($result));
    $this->assertValidatesAgainstOutputSchema('denied', $result);
  }

  /**
   * A correctable failure echoes the input schema outside structuredContent.
   */
  public function testInputFailureEchoesInputSchemaAsText(): void {
    $this->bridge('greeting', 'greeting_tool');
    $result = $this->execute('greeting');

    $this->assertToolFailed($result);
    $this->assertArrayHasKey('name', $this->echoedInputSchema($result)['properties']);
    $this->assertValidatesAgainstOutputSchema('greeting', $result);
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

    $results = $schema['properties']['structuredContent']['properties']['results'];
    $this->assertSame('array', $results['type']);
    $this->assertSame('string', $results['items']['type']);
    $this->assertStringContainsString('handle', $results['items']['description']);

    $node = Node::create(['type' => 'article', 'title' => 'First']);
    $node->save();
    $handle = $this->container->get('tool.handle_store')->store($node);

    $result = $this->execute('entity_list', ['entities' => [$handle]]);
    $outputs = $this->assertToolSucceeded($result);

    $this->assertStringStartsWith('handle:', $outputs['results'][0]);
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
