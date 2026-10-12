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
 * Test tool that throws an exception during execution.
 *
 * Used to exercise runtime failures through the bridge, which must not echo
 * the input schema: reshaping the arguments cannot fix them.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:throwing',
  label: new TranslatableMarkup('Throwing Test Tool'),
  description: new TranslatableMarkup('A test tool that throws an exception.'),
  operation: ToolOperation::Read,
)]
final class ThrowingTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    throw new \RuntimeException('This tool always throws an exception');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    return $return_as_object ? AccessResult::allowed() : TRUE;
  }

}
