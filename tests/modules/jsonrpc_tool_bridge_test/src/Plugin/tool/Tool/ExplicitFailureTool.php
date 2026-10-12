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
 * Test tool whose execution always reports a Tool API failure.
 *
 * Used to exercise the ToolApi bridge's mapping of an ordinary (non-thrown)
 * Tool API failure result, as distinct from access denial or a caught
 * exception.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:explicit_failure',
  label: new TranslatableMarkup('Explicit Failure Test Tool'),
  description: new TranslatableMarkup('A test tool whose execution always reports Tool API failure'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class ExplicitFailureTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::failure($this->t('Tool intentionally failed for wire-contract testing'));
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
