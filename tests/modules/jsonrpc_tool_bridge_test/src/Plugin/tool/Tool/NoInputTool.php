<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge_test\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;

/**
 * Tool without input parameters, used to cover empty JSON Schema properties.
 *
 * MCP clients reject an input schema whose "properties" is a JSON array, and
 * an empty PHP array encodes to `[]`. This fixture exercises that edge case.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:no_input',
  label: new TranslatableMarkup('No Input Test Tool'),
  description: new TranslatableMarkup('A test tool that declares no input parameters'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class NoInputTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::success(
      $this->t('Successfully executed without input'),
      ['result' => 'ok']
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    // For testing purposes, allow access to all users.
    $access_result = AccessResult::allowed();
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

}
