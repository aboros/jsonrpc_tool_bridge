<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Traits;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\jsonrpc\Controller\HttpController;
use Drupal\jsonrpc\HandlerInterface;
use Drupal\jsonrpc\JsonRpcObject\ParameterBag;
use Drupal\jsonrpc\JsonRpcObject\Request as RpcRequest;
use Drupal\jsonrpc\JsonRpcObject\Response as RpcResponse;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calls bridged tools and reads the envelope ToolApi::execute() returns.
 *
 * The envelope has the shape of an MCP CallToolResult. The message is always
 * the first text block. A success carries its outputs as structuredContent
 * and mirrors them as JSON in a second block. A failure has no
 * structuredContent and echoes the input schema in a second block when a
 * corrected retry could succeed.
 */
trait ToolApiTestTrait {

  /**
   * The raw JSON body of the last HTTP response, from postJsonRpc().
   */
  protected string $lastResponseJson = '';

  /**
   * Exposes a Tool API tool through a JsonRpcToolConfig entity.
   *
   * @return \Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig
   *   The saved configuration.
   */
  protected function bridge(string $config_id, string $tool_id, bool $status = TRUE): JsonRpcToolConfig {
    $config = JsonRpcToolConfig::create([
      'id' => $config_id,
      'tool_id' => $tool_id,
      'status' => $status,
    ]);
    $config->save();
    return $config;
  }

  /**
   * Gets the JSON-RPC handler.
   */
  protected function handler(): HandlerInterface {
    $handler = $this->container->get('jsonrpc.handler');
    $this->assertInstanceOf(HandlerInterface::class, $handler);
    return $handler;
  }

  /**
   * Calls a bridged method through the handler and returns the response.
   *
   * This skips the HTTP layer, so the parameters reach ToolApi::execute()
   * without jsonrpc's schema validation, as a direct execute() call does in
   * the MCP bridge tests.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID.
   * @param array<string, mixed> $params
   *   The named parameters.
   */
  protected function callMethod(string $config_id, array $params = []): RpcResponse {
    $responses = $this->handler()->batch([
      new RpcRequest('2.0', 'tool_api:' . $config_id, FALSE, 1, new ParameterBag($params)),
    ]);
    $this->assertCount(1, $responses);
    $response = reset($responses);
    $this->assertInstanceOf(RpcResponse::class, $response);
    $this->assertNull($response->getError(), (string) $response->getError()?->getMessage());
    return $response;
  }

  /**
   * Calls a bridged method and returns the result envelope.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID.
   * @param array<string, mixed> $params
   *   The named parameters.
   *
   * @return array<string, mixed>
   *   The result envelope.
   */
  protected function execute(string $config_id, array $params = []): array {
    $result = $this->callMethod($config_id, $params)->getResult();
    $this->assertIsArray($result);
    return $result;
  }

  /**
   * Sends a JSON-RPC payload through the HTTP controller.
   *
   * @param array<mixed> $payload
   *   A call or a batch of calls.
   * @param string $http_method
   *   POST sends the payload as the body, GET as the "query" argument.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response. Its JSON body is kept in $lastResponseJson.
   */
  protected function postJsonRpc(array $payload, string $http_method = 'POST'): Response {
    $request = $http_method === 'GET'
      ? Request::create('/jsonrpc', 'GET', ['query' => Json::encode($payload)])
      : Request::create('/jsonrpc', $http_method, content: Json::encode($payload));
    // The controller is called without routing, so name the route the way
    // the router would.
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'jsonrpc.handler');
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $response = HttpController::create($this->container)->resolve($request);
    }
    finally {
      $stack->pop();
    }
    $this->assertInstanceOf(Response::class, $response);
    $this->lastResponseJson = (string) $response->getContent();
    return $response;
  }

  /**
   * Calls a bridged method over HTTP and returns the decoded body.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID.
   * @param array<string, mixed>|null $params
   *   The named parameters, or NULL to leave "params" out.
   * @param string $http_method
   *   The HTTP method.
   *
   * @return array<string, mixed>
   *   The decoded JSON-RPC response.
   */
  protected function callOverHttp(string $config_id, ?array $params, string $http_method = 'POST'): array {
    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tool_api:' . $config_id];
    if ($params !== NULL) {
      $payload['params'] = (object) $params;
    }
    $this->postJsonRpc($payload, $http_method);
    $body = json_decode($this->lastResponseJson, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($body);
    $this->assertSame('2.0', $body['jsonrpc']);
    return $body;
  }

  /**
   * Returns the message from the first text block.
   *
   * @param array<string, mixed> $result
   *   The result envelope.
   */
  protected function resultMessage(array $result): string {
    $this->assertNotEmpty($result['content'], 'The result has no content.');
    $first = $result['content'][0];
    $this->assertSame('text', $first['type']);
    return (string) $first['text'];
  }

  /**
   * Asserts the result is a success and returns its outputs.
   *
   * @param array<string, mixed> $result
   *   The result envelope.
   *
   * @return array<string, mixed>
   *   The structuredContent, or an empty array for a tool without outputs.
   */
  protected function assertToolSucceeded(array $result): array {
    $this->assertFalse($result['isError'], $this->resultMessage($result));
    return $result['structuredContent'] ?? [];
  }

  /**
   * Asserts the result is a failure and returns its message.
   *
   * @param array<string, mixed> $result
   *   The result envelope.
   */
  protected function assertToolFailed(array $result): string {
    $this->assertTrue($result['isError'], 'The result is not an error.');
    $this->assertArrayNotHasKey('structuredContent', $result, 'A failure must not carry structuredContent.');
    return $this->resultMessage($result);
  }

  /**
   * Returns the input schema echoed in the second text block, if any.
   *
   * @param array<string, mixed> $result
   *   The result envelope.
   *
   * @return array<string, mixed>|null
   *   The decoded JSON Schema, or NULL when the failure echoed none.
   */
  protected function echoedInputSchema(array $result): ?array {
    $block = $result['content'][1] ?? NULL;
    if ($block === NULL) {
      return NULL;
    }
    $this->assertSame('text', $block['type']);
    $text = (string) $block['text'];
    $this->assertStringStartsWith("Input schema:\n", $text);
    $schema = json_decode(substr($text, strlen("Input schema:\n")), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($schema);
    return $schema;
  }

}
