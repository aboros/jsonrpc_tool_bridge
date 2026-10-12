<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonrpc_tool_bridge\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\jsonrpc_tool_bridge\Form\JsonRpcToolConfigForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests machine-name validation on the JsonRpcToolConfig form.
 *
 * Ported from mcp_server_tool_bridge's McpToolConfigFormTest. JSON-RPC puts
 * no cap on method names, so the MCP wire-name length checks have no
 * counterpart.
 *
 * @group jsonrpc_tool_bridge
 */
#[Group('jsonrpc_tool_bridge')]
#[RunTestsInSeparateProcesses]
final class JsonRpcToolConfigFormTest extends KernelTestBase {

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
   * The form under test.
   */
  private JsonRpcToolConfigForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->form = new JsonRpcToolConfigForm(
      $this->container->get('entity_type.manager'),
    );
    $this->form->setStringTranslation($this->container->get('string_translation'));
  }

  /**
   * Exercises the character class the config schema enforces.
   */
  public function testValidateMachineName(): void {
    $this->assertSame([], $this->validate('my_tool_1'));
    // Longer than the MCP bridge allows, which is fine for JSON-RPC.
    $this->assertSame([], $this->validate(str_repeat('a', 64)));

    foreach (['MyTool', 'my-tool', 'my tool', 'my.tool', 'my:tool'] as $candidate) {
      $this->assertNotEmpty(
        $this->validate($candidate),
        sprintf('Expected "%s" to be rejected.', $candidate),
      );
    }
  }

  /**
   * Runs the element validator and returns the errors it raised.
   *
   * @param string $value
   *   The candidate machine name.
   *
   * @return array
   *   Form errors keyed by element name.
   */
  private function validate(string $value): array {
    $element = [
      '#value' => $value,
      '#parents' => ['id'],
    ];
    $form_state = new FormState();
    $this->form->validateMachineName($element, $form_state);

    return $form_state->getErrors();
  }

}
