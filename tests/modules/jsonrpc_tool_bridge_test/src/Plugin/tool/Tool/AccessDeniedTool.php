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
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Test tool that always denies access.
 *
 * Used to exercise genuine access denials through the bridge, as opposed to
 * input validation failures that also surface through access() failing
 * closed. It declares an output so a denial can be checked against the
 * outputSchema the bridge advertises for it.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:access_denied',
  label: new TranslatableMarkup('Access Denied Test Tool'),
  description: new TranslatableMarkup('A test tool whose access check always denies.'),
  operation: ToolOperation::Read,
  output_definitions: [
    'secret' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Secret'),
      description: new TranslatableMarkup('A value nobody is allowed to read.'),
    ),
  ],
)]
final class AccessDeniedTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::success(
      new TranslatableMarkup('This should never execute.'),
      ['secret' => 'unreachable'],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $forbidden = AccessResult::forbidden('Denied by design.');
    return $return_as_object ? $forbidden : FALSE;
  }

}
