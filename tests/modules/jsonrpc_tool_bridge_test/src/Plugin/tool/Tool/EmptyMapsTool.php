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
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;

/**
 * Test tool whose map outputs are empty.
 *
 * An empty PHP array encodes as [] where the advertised schema says object.
 * The outputs cover a map at the top level, in a list and as a declared
 * property of a map, so the wire test can check each reaches the client as
 * {}. The maps are optional so the tool runs on every supported Tool API
 * release.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:empty_maps',
  label: new TranslatableMarkup('Empty Maps Test Tool'),
  description: new TranslatableMarkup('A test tool that returns empty maps.'),
  operation: ToolOperation::Read,
  output_definitions: [
    'settings' => new MapOutputDefinition(
      label: new TranslatableMarkup('Settings'),
      description: new TranslatableMarkup('A free-form map with nothing in it.'),
      required: FALSE,
    ),
    'entries' => new ListOutputDefinition(
      label: new TranslatableMarkup('Entries'),
      description: new TranslatableMarkup('A list of free-form maps.'),
      required: FALSE,
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Entry'),
        description: new TranslatableMarkup('A free-form map.'),
        required: FALSE,
      ),
    ),
    'document' => new MapOutputDefinition(
      label: new TranslatableMarkup('Document'),
      description: new TranslatableMarkup('A map whose declared property is an empty map.'),
      required: FALSE,
      property_definitions: [
        'settings' => new MapOutputDefinition(
          label: new TranslatableMarkup('Settings'),
          description: new TranslatableMarkup('A free-form map.'),
          required: FALSE,
        ),
      ],
    ),
  ],
)]
final class EmptyMapsTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::success(
      new TranslatableMarkup('Maps reported.'),
      [
        'settings' => [],
        'entries' => [[]],
        'document' => ['settings' => []],
      ],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    return $return_as_object ? AccessResult::allowed() : TRUE;
  }

}
