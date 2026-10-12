<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcMethodDefinition;
use Drupal\jsonrpc\ParameterDefinitionInterface;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Drupal\jsonrpc_tool_bridge\JsonRpcToolBridgeInvoker;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that JsonRpcToolConfigDeriver emits one method per config entity.
 *
 * The definitions are never cleared by hand here: saving or deleting a config
 * entity must invalidate jsonrpc's definition cache on its own.
 *
 * Ported from mcp_server_tool_bridge's McpToolConfigDeriverTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class JsonRpcToolConfigDeriverTest extends KernelTestBase {

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
    $this->setUpCurrentUser([], ['access jsonrpc tool bridge']);
  }

  /**
   * Exercises derivative emission, status toggling, and cache invalidation.
   */
  public function testDerivatives(): void {
    $this->assertDerivativeEmittedForEnabledConfig();
    $this->assertDisabledConfigHasNoDerivative();
    $this->assertMissingToolHasNoDerivative();
    $this->assertBrokenToolIsSkipped();
    $this->assertDescriptionOverride();
    $this->assertTripleUnderscoreToolIdResolves();
    $this->assertNoArgumentToolAcceptsEmptyParams();
    $this->assertMultipleInputIsAdvertisedAsArray();
    $this->assertConfigDeletionRemovesDerivative();
  }

  /**
   * An enabled JsonRpcToolConfig yields a tool_api derivative definition.
   */
  private function assertDerivativeEmittedForEnabledConfig(): void {
    $config = $this->bridge('derive_me', 'jsonrpc_tool_test:example');

    $def = $this->method($config->getMethodName());
    $this->assertSame('tool_api:derive_me', $def->id());
    $this->assertSame('A simple test tool that echoes a message with a prefix', (string) $def->getUsage());
    $this->assertSame(['access jsonrpc tool bridge'], $def->access);

    $params = $def->getParams();
    $this->assertSame(['message', 'prefix', 'count'], array_keys($params));
    $this->assertFalse($def->areParamsPositional());
    $this->assertContainsOnlyInstancesOf(ParameterDefinitionInterface::class, $params);
    $this->assertTrue($params['message']->isRequired());
    $this->assertFalse($params['prefix']->isRequired());
    // Each parameter carries the property schema Tool API normalizes for
    // the JSON-RPC invoker, the same schema the input schema echo shows.
    $tool = $this->container->get('plugin.manager.tool')->createInstance('jsonrpc_tool_test:example', [], JsonRpcToolBridgeInvoker::create());
    $input_schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
    foreach ($params as $name => $param) {
      $this->assertSame($input_schema['properties'][$name], $param->getSchema());
    }
    $this->assertSame('string', $params['message']->getSchema()['type']);
    $this->assertSame($params['message']->getSchema()['description'], (string) $params['message']->getDescription());

    // The bridge keeps no runtime data on the definition: ToolApi looks the
    // tool up from the config entity.
    $this->assertObjectNotHasProperty('toolId', $def);
  }

  /**
   * A disabled JsonRpcToolConfig produces no derivative.
   */
  private function assertDisabledConfigHasNoDerivative(): void {
    $config = $this->bridge('dormant', 'jsonrpc_tool_test:example', FALSE);

    $this->assertFalse($this->handler()->supportsMethod($config->getMethodName()));
  }

  /**
   * A config whose Tool API tool does not exist produces no derivative.
   */
  private function assertMissingToolHasNoDerivative(): void {
    $config = $this->bridge('orphan', 'jsonrpc_tool_test:does_not_exist');

    $this->assertFalse($this->handler()->supportsMethod($config->getMethodName()));
  }

  /**
   * A tool whose schema cannot be built is skipped, not fatal.
   *
   * The entity tools need the node module, which this test does not enable,
   * so normalizing their schema throws. Every other method must survive.
   */
  private function assertBrokenToolIsSkipped(): void {
    $this->bridge('broken', 'jsonrpc_tool_test:entity_round_trip');

    $this->assertFalse($this->handler()->supportsMethod('tool_api:broken'));
    $this->assertTrue($this->handler()->supportsMethod('tool_api:derive_me'));
  }

  /**
   * The description override replaces the tool description as usage.
   */
  private function assertDescriptionOverride(): void {
    JsonRpcToolConfig::create([
      'id' => 'described',
      'tool_id' => 'jsonrpc_tool_test:example',
      'description' => 'Echo something back.',
      'status' => TRUE,
    ])->save();

    $this->assertSame('Echo something back.', (string) $this->method('tool_api:described')->getUsage());
  }

  /**
   * A tool ID that spells ":" as "___" is bound to the same tool.
   */
  private function assertTripleUnderscoreToolIdResolves(): void {
    $this->bridge('underscored', 'jsonrpc_tool_test___example');

    $this->assertSame(['message', 'prefix', 'count'], array_keys($this->method('tool_api:underscored')->getParams()));
    $this->assertSame(['result' => 'Echo: hi'], $this->callResult('underscored', ['message' => 'hi']));
  }

  /**
   * A tool without inputs has an empty parameter list, not NULL.
   *
   * The jsonrpc module reads NULL params as "accepts no parameters" and
   * then rejects an empty params object, which clients send for such tools.
   */
  private function assertNoArgumentToolAcceptsEmptyParams(): void {
    $this->bridge('no_args', 'jsonrpc_tool_test:no_input');

    $this->assertSame([], $this->method('tool_api:no_args')->getParams());
  }

  /**
   * A `multiple: TRUE` input is advertised as an array of its data type.
   *
   * Tool API upcasts a multiple input element by element, so a schema that
   * describes it as a scalar leads clients to send one comma-joined value
   * that the tool then treats as a single item (#3618718).
   */
  private function assertMultipleInputIsAdvertisedAsArray(): void {
    $this->bridge('multi', 'jsonrpc_tool_test:multiple_string_input');

    $fields = $this->method('tool_api:multi')->getParams()['fields'];
    $this->assertSame('array', $fields->getSchema()['type']);
    $this->assertSame('string', $fields->getSchema()['items']['type']);
    $this->assertTrue($fields->isRequired());
  }

  /**
   * Deleting a config entity causes its derivative to disappear.
   */
  private function assertConfigDeletionRemovesDerivative(): void {
    $config = JsonRpcToolConfig::load('derive_me');
    $this->assertInstanceOf(JsonRpcToolConfig::class, $config);
    $this->assertTrue($this->handler()->supportsMethod('tool_api:derive_me'));

    $config->delete();

    $this->assertFalse($this->handler()->supportsMethod('tool_api:derive_me'));
  }

  /**
   * Returns the definition of a supported method.
   */
  private function method(string $name): JsonRpcMethodDefinition {
    $method = $this->handler()->getMethod($name);
    $this->assertInstanceOf(JsonRpcMethodDefinition::class, $method, "Method $name is not supported.");
    return $method;
  }

}
