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
use Drupal\tool\TypedData\InputDefinition;

/**
 * Throws PHP errors from access, outside ToolBase's execution catch block.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:errors_on_access',
  label: new TranslatableMarkup('Errors On Access Test Tool'),
  description: new TranslatableMarkup('A test tool whose access check throws a PHP error'),
  operation: ToolOperation::Read,
  input_definitions: [
    'kind' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Error kind'),
      description: new TranslatableMarkup('The PHP error to throw while checking access'),
      required: TRUE,
    ),
  ],
)]
final class ErrorsOnAccessTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::failure($this->t('This tool should never execute'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    throw match ($values['kind']) {
      'type_error' => new \TypeError('Simulated type error while checking access'),
      default => new \Error('Simulated error while checking access'),
    };
  }

}
