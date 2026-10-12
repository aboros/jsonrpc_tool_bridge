<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the JSON-RPC Tool Configuration entity.
 *
 * This configuration entity maps a Tool API tool to a JSON-RPC method,
 * allowing customization of how Drupal tools are exposed to JSON-RPC clients.
 *
 * @ConfigEntityType(
 *   id = "jsonrpc_tool_config",
 *   label = @Translation("JSON-RPC Tool Configuration"),
 *   label_collection = @Translation("JSON-RPC Tool Configurations"),
 *   label_singular = @Translation("JSON-RPC tool configuration"),
 *   label_plural = @Translation("JSON-RPC tool configurations"),
 *   label_count = @PluralTranslation(
 *     singular = "@count JSON-RPC tool configuration",
 *     plural = "@count JSON-RPC tool configurations",
 *   ),
 *   config_prefix = "jsonrpc_tool_config",
 *   admin_permission = "administer jsonrpc tool configurations",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "id",
 *     "status" = "status",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Config\Entity\ConfigEntityStorage",
 *     "list_builder" = "Drupal\jsonrpc_tool_bridge\JsonRpcToolConfigListBuilder",
 *     "form" = {
 *       "add" = "Drupal\jsonrpc_tool_bridge\Form\JsonRpcToolConfigForm",
 *       "edit" = "Drupal\jsonrpc_tool_bridge\Form\JsonRpcToolConfigForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   links = {
 *     "add-form" = "/admin/config/services/jsonrpc/tools/add",
 *     "edit-form" = "/admin/config/services/jsonrpc/tools/{jsonrpc_tool_config}/edit",
 *     "delete-form" = "/admin/config/services/jsonrpc/tools/{jsonrpc_tool_config}/delete",
 *     "collection" = "/admin/config/services/jsonrpc/tools",
 *   },
 *   config_export = {
 *     "id",
 *     "tool_id",
 *     "description",
 *     "status",
 *   },
 * )
 */
#[ConfigEntityType(
  id: 'jsonrpc_tool_config',
  label: new TranslatableMarkup('JSON-RPC Tool Configuration'),
  label_collection: new TranslatableMarkup('JSON-RPC Tool Configurations'),
  label_singular: new TranslatableMarkup('JSON-RPC tool configuration'),
  label_plural: new TranslatableMarkup('JSON-RPC tool configurations'),
  config_prefix: 'jsonrpc_tool_config',
  admin_permission: 'administer jsonrpc tool configurations',
  entity_keys: [
    'id' => 'id',
    'label' => 'id',
    'status' => 'status',
  ],
  handlers: [
    'storage' => ConfigEntityStorage::class,
    'list_builder' => 'Drupal\jsonrpc_tool_bridge\JsonRpcToolConfigListBuilder',
    'form' => [
      'add' => 'Drupal\jsonrpc_tool_bridge\Form\JsonRpcToolConfigForm',
      'edit' => 'Drupal\jsonrpc_tool_bridge\Form\JsonRpcToolConfigForm',
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'add-form' => '/admin/config/services/jsonrpc/tools/add',
    'edit-form' => '/admin/config/services/jsonrpc/tools/{jsonrpc_tool_config}/edit',
    'delete-form' => '/admin/config/services/jsonrpc/tools/{jsonrpc_tool_config}/delete',
    'collection' => '/admin/config/services/jsonrpc/tools',
  ],
  label_count: [
    'singular' => '@count JSON-RPC tool configuration',
    'plural' => '@count JSON-RPC tool configurations',
  ],
  config_export: [
    'id',
    'tool_id',
    'description',
    'status',
  ],
)]
final class JsonRpcToolConfig extends ConfigEntityBase {

  /**
   * The ID of the base JSON-RPC method every configuration derives from.
   */
  public const BASE_METHOD = 'tool_api';

  /**
   * The cache tag of the JSON-RPC method definitions.
   *
   * @see \Drupal\jsonrpc\Plugin\JsonRpcMethodManager
   */
  public const METHOD_CACHE_TAG = 'jsonrpc_plugins';

  /**
   * Reference to the Tool API tool ID.
   */
  protected string $tool_id = '';

  /**
   * Override for the tool description.
   */
  protected ?string $description = NULL;

  /**
   * Gets the Tool API tool ID.
   *
   * @return string
   *   The tool ID from Tool API.
   */
  public function getToolId(): string {
    return $this->tool_id;
  }

  /**
   * Gets the Tool API plugin ID the tool ID refers to.
   *
   * A tool ID may spell the derivative separator ":" as "___", the form some
   * clients and machine names need. Both resolve to the same plugin.
   */
  public function getToolPluginId(): string {
    return str_replace('___', ':', $this->tool_id);
  }

  /**
   * Gets the tool description override.
   *
   * @return string|null
   *   The description override, or NULL if not set.
   */
  public function getDescription(): ?string {
    return $this->description;
  }

  /**
   * Gets the JSON-RPC method name for this configuration.
   *
   * The method is a derivative of the tool_api method, so its name is the
   * derivative plugin ID.
   */
  public function getMethodName(): string {
    return self::BASE_METHOD . ':' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (empty($this->tool_id)) {
      throw new \InvalidArgumentException('The tool_id field is required.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    Cache::invalidateTags([self::METHOD_CACHE_TAG]);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    Cache::invalidateTags([self::METHOD_CACHE_TAG]);
  }

}
