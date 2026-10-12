<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how jsonrpc_discovery describes bridged methods.
 *
 * Tool API emits empty schema members as \ArrayObject so they encode as {},
 * so every fixture shape goes through the discovery serializer here.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class DiscoveryTest extends KernelTestBase {

  use ToolApiTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonrpc',
    'jsonrpc_discovery',
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
   * Every bridged method serializes with its parameters and output schema.
   */
  public function testBridgedMethodsSerialize(): void {
    $tools = [
      'example' => 'jsonrpc_tool_test:example',
      'no_input' => 'jsonrpc_tool_test:no_input',
      'empty_maps' => 'jsonrpc_tool_test:empty_maps',
      'entity_round_trip' => 'jsonrpc_tool_test:entity_round_trip',
      'regex' => 'jsonrpc_tool_test:regex_constraint',
      'multiple' => 'jsonrpc_tool_test:multiple_string_input',
    ];
    foreach ($tools as $config_id => $tool_id) {
      $this->bridge($config_id, $tool_id);
    }

    $serializer = $this->container->get('serializer');
    foreach (array_keys($tools) as $config_id) {
      $method = $this->handler()->getMethod('tool_api:' . $config_id);
      $this->assertNotNull($method);
      $json = $serializer->serialize($method, 'json');
      $normalized = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);

      $this->assertSame('JsonRpcMethod', $normalized['type']);
      $this->assertSame('tool_api:' . $config_id, $normalized['id']);
      $this->assertArrayNotHasKey('deriver', $normalized['attributes']);
      $this->assertSame('object', $normalized['attributes']['output']['type']);
      $this->assertArrayHasKey('isError', $normalized['attributes']['output']['properties']);
    }

    $example = json_decode($serializer->serialize($this->handler()->getMethod('tool_api:example'), 'json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['message', 'prefix', 'count'], array_keys($example['attributes']['params']));
    $this->assertSame('string', $example['attributes']['params']['message']['schema']['type']);

    // Empty maps stay objects in the advertised output schema.
    $empty_maps = $serializer->serialize($this->handler()->getMethod('tool_api:empty_maps'), 'json');
    $this->assertStringNotContainsString('"properties":[]', $empty_maps);
  }

}
