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
use Drupal\tool\TypedData\InputDefinition;

/**
 * Example tool for testing JSON-RPC integration.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:example',
  label: new TranslatableMarkup('Example Test Tool'),
  description: new TranslatableMarkup('A simple test tool that echoes a message with a prefix'),
  operation: ToolOperation::Read,
  input_definitions: [
    'message' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Message'),
      description: new TranslatableMarkup('The message to echo'),
      required: TRUE,
    ),
    'prefix' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Prefix'),
      description: new TranslatableMarkup('Optional prefix for the message'),
      required: FALSE,
    ),
    'count' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Repeat Count'),
      description: new TranslatableMarkup('Number of times to repeat the message'),
      required: FALSE,
    ),
  ],
)]
final class ExampleTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $message = $values['message'] ?? '';
    $prefix = $values['prefix'] ?? 'Echo';
    $count = $values['count'] ?? 1;

    $output = [];
    for ($i = 0; $i < $count; $i++) {
      $output[] = "{$prefix}: {$message}";
    }

    $result = implode(' | ', $output);

    return ExecutableResult::success(
      $this->t("Successfully processed message @count time(s)", ['@count' => $count]),
      ['result' => $result]
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
