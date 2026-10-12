<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests JsonRpcToolConfig entity CRUD, schema and preSave validation.
 *
 * Ported from mcp_server_tool_bridge's McpToolConfigTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class JsonRpcToolConfigTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'jsonrpc',
    'jsonrpc_tool_bridge',
    'tool',
    'user',
    'system',
  ];

  /**
   * Tests entity behavior relevant to the bridge.
   */
  public function testConfigEntity(): void {
    $this->assertLabelReturnsId();
    $this->assertMethodNameAndToolPluginId();
    $this->assertCrud();
    $this->assertSchemaConstraints();
    // Raises an exception, so it has to run last.
    $this->assertToolIdRequired();
  }

  /**
   * Label delegates to the id field.
   */
  private function assertLabelReturnsId(): void {
    $config = JsonRpcToolConfig::create([
      'id' => 'label_check',
      'tool_id' => 'tool_api:module___tool',
      'status' => TRUE,
    ]);

    $this->assertSame('label_check', (string) $config->label());
    $this->assertSame('tool_api:module___tool', $config->getToolId());
  }

  /**
   * The method name is the derivative ID, and "___" spells ":".
   */
  private function assertMethodNameAndToolPluginId(): void {
    $config = JsonRpcToolConfig::create([
      'id' => 'my_tool',
      'tool_id' => 'module___tool',
      'status' => TRUE,
    ]);

    $this->assertSame('tool_api:my_tool', $config->getMethodName());
    $this->assertSame('module:tool', $config->getToolPluginId());
  }

  /**
   * Entities save, load, update and delete with every exported property.
   */
  private function assertCrud(): void {
    JsonRpcToolConfig::create([
      'id' => 'crud',
      'tool_id' => 'module:tool',
      'description' => 'Overridden.',
      'status' => TRUE,
    ])->save();

    $config = JsonRpcToolConfig::load('crud');
    $this->assertInstanceOf(JsonRpcToolConfig::class, $config);
    $this->assertSame('module:tool', $config->getToolId());
    $this->assertSame('Overridden.', $config->getDescription());
    $this->assertTrue($config->status());

    $config->disable()->save();
    $config = JsonRpcToolConfig::load('crud');
    $this->assertInstanceOf(JsonRpcToolConfig::class, $config);
    $this->assertFalse($config->status());

    $config->delete();
    $this->assertNull(JsonRpcToolConfig::load('crud'));
  }

  /**
   * The config schema constrains the machine name and requires a tool ID.
   */
  private function assertSchemaConstraints(): void {
    $this->assertSame([], $this->violations('valid_name', 'module:tool'));
    $this->assertSame([], $this->violations(str_repeat('a', 210), 'module:tool'));

    $this->assertSame(['id'], $this->violations('Invalid-Name', 'module:tool'));
    $this->assertSame(['id'], $this->violations(str_repeat('a', 211), 'module:tool'));
    $this->assertSame(['tool_id'], $this->violations('valid_name', ''));
  }

  /**
   * Validates config data against the schema and returns violating paths.
   *
   * @return list<string>
   *   The property paths of the violations.
   */
  private function violations(string $id, string $tool_id): array {
    $data = [
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => $id,
      'tool_id' => $tool_id,
      'description' => NULL,
    ];
    $typed = $this->container->get('config.typed')
      ->createFromNameAndData('jsonrpc_tool_bridge.jsonrpc_tool_config.' . $id, $data);
    $paths = [];
    foreach ($typed->validate() as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    return array_values(array_unique($paths));
  }

  /**
   * Missing tool_id is rejected so empty configs never reach storage.
   */
  private function assertToolIdRequired(): void {
    $config = JsonRpcToolConfig::create([
      'id' => 'invalid_tool',
      'status' => TRUE,
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The tool_id field is required.');
    $config->save();
  }

}
