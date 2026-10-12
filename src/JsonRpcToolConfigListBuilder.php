<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of JSON-RPC Tool Configuration entities.
 */
final class JsonRpcToolConfigListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['method_name'] = $this->t('Method name');
    $header['tool_id'] = $this->t('Tool ID');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig $entity */
    $row['method_name'] = $entity->getMethodName();
    $row['tool_id'] = $entity->getToolId();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
