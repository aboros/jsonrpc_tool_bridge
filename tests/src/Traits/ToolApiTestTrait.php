<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Traits;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\jsonrpc\Controller\HttpController;
use Drupal\jsonrpc\HandlerInterface;
use Drupal\jsonrpc\JsonRpcObject\Error;
use Drupal\jsonrpc\JsonRpcObject\ParameterBag;
use Drupal\jsonrpc\JsonRpcObject\Request as RpcRequest;
use Drupal\jsonrpc\JsonRpcObject\Response as RpcResponse;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calls bridged tools and reads the JSON-RPC responses.
 *
 * A successful call has the tool's outputs as its result. A failed call is a
 * JSON-RPC error whose data is the failure message.
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
    return $response;
  }

  /**
   * Calls a bridged method, asserts it succeeded, and returns the result.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID.
   * @param array<string, mixed> $params
   *   The named parameters.
   *
   * @return array<string, mixed>
   *   The result, decoded the way a client decodes it into an array.
   */
  protected function callResult(string $config_id, array $params = []): array {
    $response = $this->callMethod($config_id, $params);
    $this->assertNull($response->getError(), (string) $response->getError()?->getData());
    $result = $response->getResult();
    // A result is always an object, even when the tool returned nothing.
    $this->assertTrue(is_array($result) || $result instanceof \stdClass);
    return json_decode(json_encode($result, JSON_THROW_ON_ERROR), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Calls a bridged method, asserts it failed, and returns the error.
   *
   * @param string $config_id
   *   The JsonRpcToolConfig ID.
   * @param array<string, mixed> $params
   *   The named parameters.
   * @param int|null $code
   *   The expected error code, or NULL to accept any.
   */
  protected function callError(string $config_id, array $params = [], ?int $code = NULL): Error {
    $error = $this->callMethod($config_id, $params)->getError();
    $this->assertInstanceOf(Error::class, $error, 'The call did not fail.');
    if ($code !== NULL) {
      $this->assertSame($code, $error->getCode(), (string) $error->getData());
    }
    return $error;
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
   * Asserts a decoded response is an error and returns its data.
   *
   * @param array<string, mixed> $body
   *   The decoded JSON-RPC response.
   * @param int $code
   *   The expected error code.
   *
   * @return string
   *   The error data.
   */
  protected function assertErrorResponse(array $body, int $code): string {
    $this->assertArrayNotHasKey('result', $body, $this->lastResponseJson);
    $this->assertArrayHasKey('error', $body, $this->lastResponseJson);
    $this->assertSame($code, $body['error']['code'], $this->lastResponseJson);
    $this->assertIsString($body['error']['data']);
    return $body['error']['data'];
  }

}
