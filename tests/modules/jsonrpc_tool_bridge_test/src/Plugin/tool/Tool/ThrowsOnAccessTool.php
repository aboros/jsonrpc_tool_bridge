<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge_test\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;

/**
 * Test tool whose access check throws instead of denying.
 *
 * ToolBase::access() calls checkAccess() directly with no try/catch, so this
 * reaches ToolApi's own caught-exception branch: distinct from the ordinary
 * access-denial and explicit Tool API failure branches, which both return a
 * result rather than throw.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:throws_on_access',
  label: new TranslatableMarkup('Throws On Access Test Tool'),
  description: new TranslatableMarkup('A test tool whose access check throws instead of denying'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class ThrowsOnAccessTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    // Unreachable: checkAccess() always throws before this runs.
    return ExecutableResult::failure($this->t('This tool should never execute'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    throw new \RuntimeException('Simulated failure while checking access');
  }

}
