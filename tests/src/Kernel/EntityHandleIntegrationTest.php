<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Handle\ToolHandleStoreInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entity handle integration through JSON-RPC method execution.
 *
 * Exercises the EntitiesAsHandles invoker capability, ToolApi execution,
 * and schema normalization with entity inputs and outputs.
 *
 * Ported from mcp_server_tool_bridge's EntityHandleIntegrationTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class EntityHandleIntegrationTest extends KernelTestBase {

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
   * The handle store.
   */
  private ToolHandleStoreInterface $handleStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ])->save();

    // Handles live in the private tempstore, which is keyed by the user.
    $this->setUpCurrentUser([], ['access jsonrpc tool bridge']);
    $this->handleStore = $this->container->get('tool.handle_store');
  }

  /**
   * Tests single entity handle round-trip through ToolApi::execute().
   */
  public function testSingleEntityHandleRoundTrip(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Entity',
    ]);
    $node->save();

    $handle = $this->handleStore->store($node);

    $this->bridge('entity_round_trip', 'jsonrpc_tool_test:entity_round_trip');

    $result = $this->callResult('entity_round_trip', [
      'entity' => $handle,
      'suffix' => ' [via JSON-RPC]',
    ]);

    // The output should contain a handle string, and nothing else: the hint
    // text Tool API writes for models is not part of a JSON-RPC result.
    $this->assertSame(['processed_entity'], array_keys($result));
    $output_handle = $result['processed_entity'];
    $this->assertIsString($output_handle);
    $this->assertStringStartsWith('handle:', $output_handle);

    // Resolve the output handle — should have the modified title.
    $resolved = $this->handleStore->resolve($output_handle);
    $this->assertInstanceOf(Node::class, $resolved);
    $this->assertStringContainsString('[via JSON-RPC]', $resolved->getTitle());
  }

  /**
   * Tests entity list batch handle round-trip through ToolApi::execute().
   */
  public function testEntityListHandleRoundTrip(): void {
    $node1 = Node::create([
      'type' => 'article',
      'title' => 'First',
    ]);
    $node1->save();
    $node2 = Node::create([
      'type' => 'article',
      'title' => 'Second',
    ]);
    $node2->save();

    $handle1 = $this->handleStore->store($node1);
    $handle2 = $this->handleStore->store($node2);

    $this->bridge('entity_list', 'jsonrpc_tool_test:entity_list');

    $result = $this->callResult('entity_list', [
      'entities' => [$handle1, $handle2],
    ]);

    // Output should be an array of handle strings.
    $output_handles = $result['results'] ?? NULL;
    $this->assertIsArray($output_handles);
    $this->assertCount(2, $output_handles);
    foreach ($output_handles as $h) {
      $this->assertIsString($h);
      $this->assertStringStartsWith('handle:', $h);
    }

    // Each handle resolves to the entity it was issued for.
    $this->assertSame('First', $this->handleStore->resolve($output_handles[0])->label());
    $this->assertSame('Second', $this->handleStore->resolve($output_handles[1])->label());
  }

  /**
   * Tests parameter schemas describe entity inputs as handle strings.
   */
  public function testSchemaIncludesHandleDescriptions(): void {
    $this->bridge('entity_round_trip', 'jsonrpc_tool_test:entity_round_trip');

    $params = $this->handler()->getMethod('tool_api:entity_round_trip')?->getParams();
    $this->assertIsArray($params);
    $this->assertArrayHasKey('entity', $params);

    // Entity input should be described as a string handle. The label is
    // prefixed once by ContextDefinitionNormalizer (tool#3583010).
    $entity_schema = $params['entity']->getSchema();
    $this->assertSame('string', $entity_schema['type']);
    $this->assertSame(
      'Entity: A node entity to process. (pass a handle from a previous tool call)',
      $entity_schema['description'],
    );
  }

  /**
   * Tests the handle round-trip a client can drive from plain input.
   *
   * The handle is produced by one call and consumed by the next, each a
   * separate JSON-RPC request over HTTP, without seeding the handle store.
   */
  public function testHandleRoundTripFromPlainInput(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Loaded',
    ]);
    $node->save();

    $this->bridge('node_load', 'jsonrpc_tool_test:node_load');
    $this->bridge('entity_round_trip', 'jsonrpc_tool_test:entity_round_trip');

    $load = $this->callOverHttp('node_load', ['nid' => (int) $node->id()]);
    $handle = $load['result']['node'];
    $this->assertIsString($handle);
    $this->assertStringStartsWith('handle:', $handle);

    $round_trip = $this->callOverHttp('entity_round_trip', [
      'entity' => $handle,
      'suffix' => ' [round trip]',
    ]);
    $resolved = $this->handleStore->resolve($round_trip['result']['processed_entity']);
    $this->assertInstanceOf(Node::class, $resolved);
    $this->assertSame('Loaded [round trip]', $resolved->getTitle());
  }

  /**
   * Tests a handle issued to one user does not resolve for another.
   *
   * Handles live in the private tempstore, keyed by the user, so the
   * stateless JSON-RPC endpoint can use them across requests as long as the
   * same user authenticates.
   */
  public function testHandleIsScopedToTheUser(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Private',
    ]);
    $node->save();
    $this->bridge('entity_round_trip', 'jsonrpc_tool_test:entity_round_trip');
    $handle = $this->handleStore->store($node);

    $this->setCurrentUser($this->createUser(['access jsonrpc tool bridge']));
    $error = $this->callError('entity_round_trip', ['entity' => $handle], Error::INVALID_PARAMS);
    $this->assertStringContainsString('does not exist or has expired', (string) $error->getData());
  }

  /**
   * Tests that non-entity string inputs are not falsely resolved.
   */
  public function testNonEntityStringNotResolved(): void {
    $this->bridge('example', 'jsonrpc_tool_test:example');

    $result = $this->callResult('example', [
      'message' => 'handle:not-a-real-handle',
      'prefix' => 'Test',
      'count' => 1,
    ]);
    $this->assertStringContainsString(
      'handle:not-a-real-handle',
      $result['result'],
    );
  }

}
