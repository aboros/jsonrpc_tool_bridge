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
use Drupal\tool\TypedData\InputDefinition;

/**
 * Test tool that accepts and returns entity handles.
 *
 * Used for testing entity handle round-trip through JSON-RPC method execution.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:entity_round_trip',
  label: new TranslatableMarkup('Entity Round Trip Tool'),
  description: new TranslatableMarkup('Accepts an entity handle and returns it with modifications.'),
  operation: ToolOperation::Transform,
  input_definitions: [
    'entity' => new EntityInputDefinition(
      data_type: 'node',
      label: new TranslatableMarkup('Entity'),
      description: new TranslatableMarkup('A node entity to process.'),
    ),
    'suffix' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Suffix'),
      description: new TranslatableMarkup('Suffix to append to the entity title.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'processed_entity' => new EntityContextDefinition(
      data_type: 'entity:node',
      label: new TranslatableMarkup('Processed entity'),
      description: new TranslatableMarkup('The processed entity with modified title.'),
    ),
  ],
)]
final class EntityRoundTripTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $entity = $values['entity'] ?? NULL;
    $suffix = $values['suffix'] ?? ' (processed)';

    if (!$entity instanceof ContentEntityInterface) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Expected an entity, got @type.', [
          '@type' => gettype($entity),
        ]),
      );
    }

    $entity->set('title', $entity->label() . $suffix);

    return ExecutableResult::success(
      new TranslatableMarkup('Entity processed successfully.'),
      ['processed_entity' => $entity],
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
