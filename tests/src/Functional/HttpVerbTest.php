<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests which HTTP verbs may call a bridged tool, and caching of the result.
 *
 * The jsonrpc module serves every method over GET, and GET responses are
 * cacheable. Tools that modify data are refused over GET, and no bridged
 * result is ever cached, whichever verb carried it.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class HttpVerbTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'page_cache',
    'dynamic_page_cache',
    'jsonrpc',
    'jsonrpc_tool_bridge',
    'jsonrpc_tool_bridge_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A Read tool and a Write tool.
    JsonRpcToolConfig::create([
      'id' => 'example',
      'tool_id' => 'jsonrpc_tool_test:example',
      'status' => TRUE,
    ])->save();
    JsonRpcToolConfig::create([
      'id' => 'quoted_message',
      'tool_id' => 'jsonrpc_tool_test:quoted_message',
      'status' => TRUE,
    ])->save();
  }

  /**
   * A modifying tool is refused over GET and runs over POST.
   */
  public function testModifyingToolRequiresPost(): void {
    $account = $this->drupalCreateUser(['use jsonrpc services', 'access jsonrpc tool bridge']);
    $call = $this->call('quoted_message', ['title' => 'About us']);

    $body = $this->decode($this->send('GET', $call, [$account->getAccountName(), $account->passRaw]));
    $this->assertArrayNotHasKey('result', $body);
    $this->assertSame(-32600, $body['error']['code']);
    $this->assertSame('Tool modifies data and must be called with POST.', $body['error']['data']);

    $response = $this->send('POST', $call, [$account->getAccountName(), $account->passRaw]);
    $this->assertArrayHasKey('result', $this->decode($response));
    // The tool has no outputs, so its result is an empty object.
    $this->assertStringContainsString('"result":{}', (string) $response->getBody());
  }

  /**
   * A read-only tool runs over GET, and its result is never cached.
   */
  public function testReadOnlyToolOverGetIsNotCached(): void {
    $account = $this->drupalCreateUser(['use jsonrpc services', 'access jsonrpc tool bridge']);
    $auth = [$account->getAccountName(), $account->passRaw];

    foreach (['first', 'second'] as $message) {
      $response = $this->send('GET', $this->call('example', ['message' => 'same']), $auth);
      $body = $this->decode($response);
      $this->assertSame(['result' => 'Echo: same'], $body['result'], $message);
      $this->assertStringStartsWith('UNCACHEABLE', $response->getHeaderLine('X-Drupal-Dynamic-Cache'));
    }
  }

  /**
   * An anonymous GET is not stored in the internal page cache either.
   */
  public function testAnonymousGetIsNotPageCached(): void {
    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), [
      'use jsonrpc services',
      'access jsonrpc tool bridge',
    ]);
    $call = $this->call('example', ['message' => 'anonymous']);

    foreach (['first', 'second'] as $attempt) {
      $response = $this->send('GET', $call);
      $this->assertSame(['result' => 'Echo: anonymous'], $this->decode($response)['result'], $attempt);
      $this->assertNotSame('HIT', $response->getHeaderLine('X-Drupal-Cache'), $attempt);
    }
  }

  /**
   * Builds a JSON-RPC call of a bridged method.
   *
   * @return array<string, mixed>
   *   The call.
   */
  private function call(string $config_id, array $params): array {
    return [
      'jsonrpc' => '2.0',
      'id' => 1,
      'method' => 'tool_api:' . $config_id,
      'params' => $params,
    ];
  }

  /**
   * Sends a call to the JSON-RPC endpoint.
   *
   * @param string $verb
   *   GET or POST.
   * @param array<string, mixed> $call
   *   The JSON-RPC call.
   * @param array{string, string}|null $auth
   *   Basic auth credentials, or NULL for an anonymous request.
   */
  private function send(string $verb, array $call, ?array $auth = NULL): ResponseInterface {
    $options = [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::ALLOW_REDIRECTS => FALSE,
    ];
    if ($verb === 'GET') {
      $options[RequestOptions::QUERY] = ['query' => Json::encode($call)];
    }
    else {
      $options[RequestOptions::JSON] = $call;
    }
    if ($auth !== NULL) {
      $options[RequestOptions::AUTH] = $auth;
    }
    $url = $this->buildUrl(Url::fromRoute('jsonrpc.handler'));
    return $this->getHttpClient()->request($verb, $url, $options);
  }

  /**
   * Decodes a JSON-RPC response sent with HTTP status 200.
   *
   * @return array<string, mixed>
   *   The decoded body.
   */
  private function decode(ResponseInterface $response): array {
    $content = (string) $response->getBody();
    $this->assertSame(200, $response->getStatusCode(), $content);
    $body = Json::decode($content);
    $this->assertIsArray($body);
    return $body;
  }

}
