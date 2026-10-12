<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc\JsonRpcMethodDefinition;
use Drupal\Tests\jsonrpc_tool_bridge\Traits\ToolApiTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ToolApi method registration via JsonRpcToolConfig entities.
 *
 * Ported from mcp_server_tool_bridge's ToolApiRegistrationTest.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class ToolApiRegistrationTest extends KernelTestBase {

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
   * Input values that satisfy every constraint on the regex fixture tool.
   */
  private const VALID_REGEX_INPUT = [
    'username' => 'john_doe',
    'code' => 'ABC-123',
    'path' => '/foo/bar',
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
   * Tests method registration and its life cycle.
   */
  public function testToolApiRegistration(): void {
    $this->assertMethodIsSupported();
    $this->assertDisablingRemovesMethod();
    $this->assertDeletingRemovesMethod();
    $this->assertRegexPatternsAreBareInSchema();
    $this->assertRegexConstraintsEnforcedAtRuntime();
  }

  /**
   * An enabled config is a supported and available method.
   */
  private function assertMethodIsSupported(): void {
    $config = $this->bridge('my_tool', 'jsonrpc_tool_test:example');

    $methods = $this->handler()->supportedMethods();
    $this->assertArrayHasKey('tool_api:my_tool', $methods);
    $this->assertArrayNotHasKey('tool_api', $methods, 'The base method is not callable on its own.');
    $this->assertInstanceOf(JsonRpcMethodDefinition::class, $methods['tool_api:my_tool']);
    $this->assertArrayHasKey($config->getMethodName(), $this->handler()->availableMethods());
  }

  /**
   * Disabling a config removes its method.
   */
  private function assertDisablingRemovesMethod(): void {
    $config = $this->bridge('toggled', 'jsonrpc_tool_test:example');
    $this->assertTrue($this->handler()->supportsMethod('tool_api:toggled'));

    $config->disable()->save();
    $this->assertFalse($this->handler()->supportsMethod('tool_api:toggled'));

    $config->enable()->save();
    $this->assertTrue($this->handler()->supportsMethod('tool_api:toggled'));
  }

  /**
   * Deleting a config removes its method.
   */
  private function assertDeletingRemovesMethod(): void {
    $config = $this->bridge('deleted', 'jsonrpc_tool_test:example');
    $this->assertTrue($this->handler()->supportsMethod('tool_api:deleted'));

    $config->delete();
    $this->assertFalse($this->handler()->supportsMethod('tool_api:deleted'));
  }

  /**
   * Regex constraints reach the parameter schemas as bare patterns.
   *
   * JSON Schema "pattern" is not a PCRE literal, and jsonrpc validates the
   * parameters against these schemas, so a delimited pattern would reject
   * valid input.
   */
  private function assertRegexPatternsAreBareInSchema(): void {
    $this->bridge('regex_tool', 'jsonrpc_tool_test:regex_constraint');
    $params = $this->handler()->getMethod('tool_api:regex_tool')->getParams();

    $expected = [
      // "/^[a-zA-Z0-9_]+$/" loses its slash delimiters.
      'username' => '^[a-zA-Z0-9_]+$',
      // "/^[A-Z]+-\d{3}$/i" loses its delimiters and its trailing modifier.
      'code' => '^[A-Z]+-\d{3}$',
      // "#^/[a-z]+(/[a-z]+)*$#" keeps the slashes inside its body intact.
      'path' => '^/[a-z]+(/[a-z]+)*$',
      // A pattern authored bare is skipped by the tool module's converter.
      'slug' => NULL,
    ];
    $actual = array_map(
      static fn (string $name): mixed => $params[$name]->getSchema()['pattern'] ?? NULL,
      array_combine(array_keys($expected), array_keys($expected)),
    );
    $this->assertSame($expected, $actual);
  }

  /**
   * Stripping the delimiters does not weaken Tool API validation.
   *
   * The deriver only rewrites the advertised schema; the original delimited
   * pattern, modifiers included, still governs what the tool accepts.
   */
  private function assertRegexConstraintsEnforcedAtRuntime(): void {
    $this->assertToolSucceeded($this->execute('regex_tool', self::VALID_REGEX_INPUT));
    foreach (['username' => 'invalid!@#', 'code' => 'abc-xyz', 'path' => 'no-leading-slash'] as $name => $value) {
      $message = $this->assertToolFailed($this->execute('regex_tool', [$name => $value] + self::VALID_REGEX_INPUT));
      $this->assertStringContainsString($name, $message);
    }
    // The "i" modifier is dropped from the schema but still honoured by the
    // tool, so the advertised pattern is stricter than the server-side one.
    $this->assertToolSucceeded($this->execute('regex_tool', ['code' => 'abc-123'] + self::VALID_REGEX_INPUT));
  }

}
