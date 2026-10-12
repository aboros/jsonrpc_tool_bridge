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
 * Test tool that puts its title input into every message it builds.
 *
 * The violation message and the result message place the title in a
 * placeholder, which HTML-escapes quotes. The access denial reason is a plain
 * string. Used to check the bridge sends each message to clients as plain
 * text without losing literal text.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:quoted_message',
  label: new TranslatableMarkup('Quoted Message Test Tool'),
  description: new TranslatableMarkup('A test tool whose messages quote its title input'),
  operation: ToolOperation::Write,
  input_definitions: [
    'title' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('A page title that must start with "About"'),
      required: TRUE,
      constraints: [
        'Regex' => [
          'pattern' => '/^About/',
          'message' => 'Title %value must start with "About".',
        ],
      ],
    ),
    'locked' => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Locked'),
      description: new TranslatableMarkup('Whether the page is locked against changes'),
      required: FALSE,
      default_value: FALSE,
    ),
  ],
)]
final class QuotedMessageTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::success($this->t('Saved @title.', ['@title' => $values['title']]));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access_result = $values['locked']
      ? AccessResult::forbidden($values['title'] . ' is locked.')
      : AccessResult::allowed();
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

}
