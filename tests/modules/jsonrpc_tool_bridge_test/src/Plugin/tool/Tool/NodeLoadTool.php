<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge_test\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test tool that loads a node by ID and returns it as an entity output.
 *
 * This is the only fixture that produces an entity handle from plain input,
 * so it is the entry point for exercising the handle round-trip from a JSON-RPC
 * client: call this tool, then pass the returned handle to
 * EntityRoundTripTool or EntityListTool.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:node_load',
  label: new TranslatableMarkup('Node Load Tool'),
  description: new TranslatableMarkup('Loads a node by ID and returns it as an entity handle.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'nid' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Node ID'),
      description: new TranslatableMarkup('The ID of the node to load.'),
    ),
  ],
  output_definitions: [
    'node' => new EntityContextDefinition(
      data_type: 'entity:node',
      label: new TranslatableMarkup('Node'),
      description: new TranslatableMarkup('The loaded node.'),
    ),
  ],
)]
final class NodeLoadTool extends ToolBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $node = $this->entityTypeManager->getStorage('node')->load($values['nid']);
    if (!$node instanceof NodeInterface) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Node not found with ID: @id', ['@id' => $values['nid']]),
      );
    }

    return ExecutableResult::success(
      new TranslatableMarkup('Node loaded.'),
      ['node' => $node],
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
