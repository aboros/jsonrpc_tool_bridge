<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that both bridges return the same result for the same call.
 *
 * Runs each fixture tool with the same arguments through
 * mcp_server_tool_bridge and through this module, and compares isError, the
 * text of every content block and structuredContent, as JSON so that {} and
 * [] are told apart.
 *
 * Only runs where mcp_server_tool_bridge is installed, which is a development
 * dependency, not a requirement of this module.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class McpParityTest extends KernelTestBase {

  use ToolApiTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'mcp_server',
    'mcp_server_tool_bridge',
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
    if (!class_exists('Drupal\mcp_server_tool_bridge\Entity\McpToolConfig') || !class_exists('Mcp\Server\ClientGateway')) {
      $this->markTestSkipped('mcp_server_tool_bridge is not installed.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], ['access jsonrpc tool bridge']);
  }

  /**
   * The same call gets the same result from both bridges.
   *
   * @param string $tool
   *   The fixture tool name, after "jsonrpc_tool_test:".
   * @param array<string, mixed> $arguments
   *   The call arguments.
   */
  #[DataProvider('calls')]
  public function testSameResult(string $tool, array $arguments): void {
    $config_id = 'parity_' . $tool;
    $this->bridge($config_id, 'jsonrpc_tool_test:' . $tool);
    $mcp_config_class = 'Drupal\mcp_server_tool_bridge\Entity\McpToolConfig';
    $mcp_config_class::create([
      'id' => $config_id,
      'tool_id' => 'jsonrpc_tool_test:' . $tool,
      'status' => TRUE,
    ])->save();

    $manager = $this->container->get('plugin.manager.mcp_server.tool');
    $manager->clearCachedDefinitions();
    $mcp_result = $manager->createInstance('tool_api__' . $config_id)
      ->execute($arguments, $this->createMock('Mcp\Server\ClientGateway'));

    $jsonrpc_result = $this->execute($config_id, $arguments);

    $this->assertSame($mcp_result->isError, $jsonrpc_result['isError']);
    $this->assertSame(
      array_map(static fn ($block): string => $block->text, $mcp_result->content),
      array_column($jsonrpc_result['content'], 'text'),
    );
    $this->assertSame(
      json_encode($mcp_result->structuredContent, JSON_THROW_ON_ERROR),
      json_encode($jsonrpc_result['structuredContent'] ?? NULL, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Both bridges advertise the same input and output schemas.
   *
   * MCP advertises one input schema, JSON-RPC one schema per parameter. The
   * output schema is the structuredContent member of the JSON-RPC envelope.
   */
  #[DataProvider('schemaTools')]
  public function testSameSchemas(string $tool): void {
    $config_id = 'schema_' . $tool;
    $this->bridge($config_id, 'jsonrpc_tool_test:' . $tool);
    $mcp_config_class = 'Drupal\mcp_server_tool_bridge\Entity\McpToolConfig';
    $mcp_config_class::create([
      'id' => $config_id,
      'tool_id' => 'jsonrpc_tool_test:' . $tool,
      'status' => TRUE,
    ])->save();
    $manager = $this->container->get('plugin.manager.mcp_server.tool');
    $manager->clearCachedDefinitions();
    $mcp = $manager->getDefinition('tool_api__' . $config_id);
    $jsonrpc = $this->handler()->getMethod('tool_api:' . $config_id);
    $this->assertNotNull($jsonrpc);

    $input = $mcp->inputSchema;
    $params = $jsonrpc->getParams() ?? [];
    $this->assertSame(array_keys((array) $input['properties']), array_keys($params));
    foreach ($params as $name => $param) {
      $this->assertSame(json_encode($input['properties'][$name]), json_encode($param->getSchema()), $name);
      $this->assertSame(in_array($name, $input['required'] ?? [], TRUE), $param->isRequired(), $name);
    }
    $this->assertSame(
      json_encode($mcp->outputSchema),
      json_encode($jsonrpc->output['properties']['structuredContent'] ?? NULL),
    );
  }

  /**
   * Provides tools whose schemas hold no empty "properties".
   *
   * The JSON-RPC bridge leaves an empty "properties" out of its schemas, so
   * the discovery module cannot turn it into []. Empty maps are therefore
   * not compared.
   */
  public static function schemaTools(): array {
    $tools = [
      'example',
      'no_input',
      'regex_constraint',
      'multiple_string_input',
      'access_denied',
      'entity_round_trip',
      'entity_list',
      'quoted_message',
    ];
    return array_combine($tools, array_map(static fn (string $tool): array => [$tool], $tools));
  }

  /**
   * Provides calls that cover every outcome the bridges distinguish.
   */
  public static function calls(): array {
    return [
      'success' => ['example', ['message' => 'hello / world', 'prefix' => 'Echo ✓', 'count' => 2]],
      'success without outputs' => ['no_input', []],
      'empty maps' => ['empty_maps', []],
      'multiple input' => ['multiple_string_input', ['fields' => ['title', 'status']]],
      'missing required input' => ['regex_constraint', []],
      'constraint violation' => ['regex_constraint', ['username' => 'not valid!']],
      'access denied' => ['access_denied', []],
      'explicit failure' => ['explicit_failure', []],
      'throws on access' => ['throws_on_access', []],
      'error on access' => ['errors_on_access', ['kind' => 'type_error']],
      'runtime exception' => ['throwing', []],
      'malformed handle' => ['entity_round_trip', ['entity' => 'handle:not-a-uuid']],
      'plain text violation' => ['quoted_message', ['title' => "Team's page"]],
      'literal denial reason' => ['quoted_message', ['title' => 'About <us> &amp; "team"', 'locked' => TRUE]],
      'plain text result' => ['quoted_message', ['title' => 'About "us"']],
    ];
  }

}
