<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge_test\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\EntityInputDefinition;

/**
 * Test tool that accepts and returns a list of entities.
 *
 * Used for testing batch entity handle transforms through JSON-RPC method
 * execution.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:entity_list',
  label: new TranslatableMarkup('Entity List Tool'),
  description: new TranslatableMarkup('Accepts multiple entities and returns them.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'entities' => new EntityInputDefinition(
      data_type: 'node',
      label: new TranslatableMarkup('Entities'),
      description: new TranslatableMarkup('A list of node entities.'),
      multiple: TRUE,
    ),
  ],
  output_definitions: [
    'results' => new EntityContextDefinition(
      data_type: 'entity:node',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('The processed entities.'),
      multiple: TRUE,
    ),
  ],
)]
final class EntityListTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $entities = $values['entities'] ?? [];
    $results = [];

    foreach ($entities as $entity) {
      if ($entity instanceof ContentEntityInterface) {
        $results[] = $entity;
      }
    }

    return ExecutableResult::success(
      new TranslatableMarkup('Processed @count entities.', [
        '@count' => count($results),
      ]),
      ['results' => $results],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(
    array $values,
    AccountInterface $account,
    bool $return_as_object = FALSE,
  ): bool|AccessResultInterface {
    return $return_as_object ? AccessResult::allowed() : TRUE;
  }

}
