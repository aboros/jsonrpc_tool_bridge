<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides autocomplete suggestions for Tool plugins.
 */
final class ToolAutocompleteController extends ControllerBase {

  /**
   * Constructs a ToolAutocompleteController.
   */
  public function __construct(
    private readonly ToolManager $toolManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.tool')
    );
  }

  /**
   * Handles autocomplete requests for tool IDs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing the search query.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with matching tool suggestions.
   */
  public function handleAutocomplete(Request $request): JsonResponse {
    $matches = [];
    $query = $request->query->get('q');

    if (!$query) {
      return new JsonResponse($matches);
    }

    $query = strtolower($query);
    $definitions = $this->toolManager->getDefinitions();

    foreach ($definitions as $plugin_id => $definition) {
      assert($definition instanceof ToolDefinition);

      $label = (string) $definition->getLabel();
      $description = (string) $definition->getDescription();
      $searchable_text = strtolower($plugin_id . ' ' . $label . ' ' . $description);

      if (str_contains($searchable_text, $query)) {
        $matches[] = [
          'value' => $plugin_id,
          'label' => sprintf('%s (%s)', $label, $plugin_id),
        ];

        if (count($matches) >= 10) {
          break;
        }
      }
    }

    return new JsonResponse($matches);
  }

}
