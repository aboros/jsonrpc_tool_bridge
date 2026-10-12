<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method\ToolApi;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that both bridges report the same outcome for the same call.
 *
 * Runs each fixture tool with the same arguments through
 * mcp_server_tool_bridge and through this module. A success must return the
 * MCP structuredContent as the JSON-RPC result, compared as JSON so that {}
 * and [] are told apart. A failure must be a JSON-RPC error whose data is
 * the MCP message, with "Invalid params" exactly where MCP echoes the input
 * schema, since both follow the Tool API failure category.
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
   * The same call gets the same outcome from both bridges.
   *
   * @param string $tool
   *   The fixture tool name, after "jsonrpc_tool_test:".
   * @param array<string, mixed> $arguments
   *   The call arguments.
   * @param int|null $code
   *   The expected JSON-RPC error code, or NULL for a success.
   * @param bool $shows_detail
   *   Whether the error data carries the failure message. An exception the
   *   bridge catches itself only shows it when the site displays errors.
   */
  #[DataProvider('calls')]
  public function testSameOutcome(string $tool, array $arguments, ?int $code, bool $shows_detail): void {
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

    $response = $this->callMethod($config_id, $arguments);

    $this->assertSame($code !== NULL, $mcp_result->isError);
    if ($code === NULL) {
      $this->assertSame(
        json_encode($mcp_result->structuredContent ?? new \stdClass(), JSON_THROW_ON_ERROR),
        json_encode($response->getResult(), JSON_THROW_ON_ERROR),
      );
      return;
    }
    $error = $response->getError();
    $this->assertNotNull($error);
    $this->assertSame($code, $error->getCode());
    // MCP echoes the input schema for exactly the correctable failures.
    $this->assertSame($code === Error::INVALID_PARAMS, count($mcp_result->content) === 2);
    $this->assertSame(
      $shows_detail ? $mcp_result->content[0]->text : Error::$errorMeanings[Error::INTERNAL_ERROR],
      $error->getData(),
    );
  }

  /**
   * Both bridges advertise the same input and output schemas.
   *
   * MCP advertises one input schema, JSON-RPC one schema per parameter. The
   * MCP outputSchema is the JSON-RPC output schema, which is any object for
   * a tool without declared outputs.
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
      json_encode($mcp->outputSchema ?? ToolApi::outputSchema()),
      json_encode($jsonrpc->output),
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
   *
   * @return array<string, array{string, array<string, mixed>, int|null, bool}>
   *   The tool, the arguments, the expected error code and whether the error
   *   data shows the failure message.
   */
  public static function calls(): array {
    $params = Error::INVALID_PARAMS;
    $request = Error::INVALID_REQUEST;
    $internal = Error::INTERNAL_ERROR;
    return [
      'success' => ['example', ['message' => 'hello / world', 'prefix' => 'Echo ✓', 'count' => 2], NULL, TRUE],
      'success without declared outputs' => ['no_input', [], NULL, TRUE],
      'success without outputs' => ['quoted_message', ['title' => 'About "us"'], NULL, TRUE],
      'empty maps' => ['empty_maps', [], NULL, TRUE],
      'multiple input' => ['multiple_string_input', ['fields' => ['title', 'status']], NULL, TRUE],
      'missing required input' => ['regex_constraint', [], $params, TRUE],
      'constraint violation' => ['regex_constraint', ['username' => 'not valid!'], $params, TRUE],
      'access denied' => ['access_denied', [], $request, TRUE],
      'explicit failure' => ['explicit_failure', [], $params, TRUE],
      'throws on access' => ['throws_on_access', [], $internal, FALSE],
      'error on access' => ['errors_on_access', ['kind' => 'type_error'], $internal, FALSE],
      'runtime exception' => ['throwing', [], $internal, TRUE],
      'malformed handle' => ['entity_round_trip', ['entity' => 'handle:not-a-uuid'], $params, TRUE],
      'plain text violation' => ['quoted_message', ['title' => "Team's page"], $params, TRUE],
      'literal denial reason' => [
        'quoted_message',
        ['title' => 'About <us> &amp; "team"', 'locked' => TRUE],
        $request,
        TRUE,
      ],
    ];
  }

}
