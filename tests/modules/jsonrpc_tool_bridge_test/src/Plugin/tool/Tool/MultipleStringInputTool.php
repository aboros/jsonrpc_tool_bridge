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
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Test tool with a scalar input that accepts multiple values.
 *
 * Mirrors the `fields` input of tool_belt's entity tools: a plain string
 * declared `multiple: TRUE`, so the bridge must advertise it as an array of
 * strings and pass every element through to the tool.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:multiple_string_input',
  label: new TranslatableMarkup('Multiple String Input Tool'),
  description: new TranslatableMarkup('Echoes every field name it receives.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'fields' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Fields'),
      description: new TranslatableMarkup('Field names to retrieve.'),
      multiple: TRUE,
      required: TRUE,
    ),
  ],
  output_definitions: [
    'fields' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Fields'),
      description: new TranslatableMarkup('The field names received.'),
      multiple: TRUE,
    ),
  ],
)]
final class MultipleStringInputTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $fields = array_values($values['fields']);
    return ExecutableResult::success(
      $this->t('Received @count field name(s)', ['@count' => count($fields)]),
      ['fields' => $fields],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access_result = AccessResult::allowed();
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

}
