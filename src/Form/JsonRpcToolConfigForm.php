<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for JSON-RPC Tool Configuration entities.
 */
final class JsonRpcToolConfigForm extends EntityForm {

  /**
   * Constructs a JsonRpcToolConfigForm.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;
    if (!$entity instanceof JsonRpcToolConfig) {
      return $form;
    }

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [$this, 'exist'],
      ],
      '#disabled' => !$entity->isNew(),
      '#description' => $this->t(
        'Unique machine name. Becomes the JSON-RPC method name as %prefix<machine_name>.',
        ['%prefix' => JsonRpcToolConfig::BASE_METHOD . ':'],
      ),
      '#element_validate' => [[$this, 'validateMachineName']],
    ];

    $form['tool_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tool Plugin ID'),
      '#default_value' => $entity->get('tool_id'),
      '#required' => TRUE,
      '#description' => $this->t('The ID of the tool to expose (e.g., plugin_id:tool_name)'),
      '#autocomplete_route_name' => 'jsonrpc_tool_bridge.tool_autocomplete',
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->get('description'),
      '#description' => $this->t('Override the tool description shown as the method usage.'),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->status(),
      '#description' => $this->t('When enabled, this tool will be exposed as a JSON-RPC method.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->entity;

    $message_args = ['%label' => $entity->label()];
    $message = $result === SAVED_NEW
      ? $this->t('Created new JSON-RPC tool configuration %label.', $message_args)
      : $this->t('Updated JSON-RPC tool configuration %label.', $message_args);
    $this->messenger()->addStatus($message);

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

  /**
   * Validates the machine name.
   *
   * The character class mirrors the Regex constraint in
   * config/schema/jsonrpc_tool_bridge.schema.yml, so a name accepted here can
   * never violate the stored config schema. JSON-RPC puts no limit on the
   * length of a method name, so unlike the MCP bridge there is no length rule
   * beyond the one the machine name element enforces.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateMachineName(
    array &$element,
    FormStateInterface $form_state,
  ): void {
    $value = (string) ($element['#value'] ?? '');
    if ($value === '') {
      return;
    }

    if (!preg_match('/^[a-z0-9_]+$/', $value)) {
      $form_state->setError(
        $element,
        $this->t('The machine name must contain only lowercase letters, numbers, and underscores.'),
      );
    }
  }

  /**
   * Checks if an entity with the given ID exists.
   */
  public function exist(string $id): bool {
    $entity = $this->entityTypeManager
      ->getStorage('jsonrpc_tool_config')
      ->getQuery()
      ->condition('id', $id)
      ->accessCheck(FALSE)
      ->execute();
    return (bool) $entity;
  }

}
