<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\jsonrpc\Attribute\JsonRpcParameterDefinition;
use Drupal\jsonrpc\JsonRpcMethodDefinition;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Drupal\jsonrpc_tool_bridge\JsonRpcToolBridgeInvoker;
use Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method\ToolApi;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\Tool\ToolManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emits one ToolApi method derivative per enabled JsonRpcToolConfig entity.
 */
final class JsonRpcToolConfigDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * Constructs a JsonRpcToolConfigDeriver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ToolManager $toolManager,
    private readonly ToolDefinitionSerializer $toolDefinitionSerializer,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.tool'),
      $container->get('tool.definition_serializer'),
      $container->get('logger.factory')->get('jsonrpc_tool_bridge'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array|\Drupal\Component\Plugin\Definition\PluginDefinitionInterface $base_plugin_definition
   *   The base plugin definition. A JsonRpcMethodDefinition is required for
   *   this deriver to emit derivatives.
   *
   * @return array<string, \Drupal\jsonrpc\JsonRpcMethodDefinition>
   *   Derivative definitions keyed by derivative ID.
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    if (!$base_plugin_definition instanceof JsonRpcMethodDefinition) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('jsonrpc_tool_config');

    foreach ($storage->loadMultiple() as $config) {
      if (!$config instanceof JsonRpcToolConfig || !$config->status()) {
        continue;
      }
      $id = $config->id();
      $tool_id = $config->getToolPluginId();

      try {
        $tool_definition = $this->toolManager->getDefinition(
          $tool_id,
          FALSE,
        );
      }
      catch (\Exception) {
        // Skip configs whose Tool API tool is unavailable.
        continue;
      }

      if (!$tool_definition instanceof ToolDefinition) {
        continue;
      }

      $description = !empty($config->getDescription())
        ? $config->getDescription()
        : (string) $tool_definition->getDescription();

      try {
        $this->derivatives[$id] = $this->createDerivativeDefinition(
          base_plugin_definition: $base_plugin_definition,
          derivative_id: $id,
          tool_id: $tool_id,
          description: $description,
        );
      }
      catch (\Throwable $e) {
        // The jsonrpc module builds every method's definition together, so a
        // tool whose schema cannot be built would take down every JSON-RPC
        // method on the site, not only this one.
        $this->logger->error('Cannot expose tool @tool as a JSON-RPC method: @message', [
          '@tool' => $tool_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $this->derivatives;
  }

  /**
   * Creates a JSON-RPC method definition for one bridged tool.
   *
   * The definition carries no reference to the Tool API tool: ToolApi looks
   * it up from the config entity named by the derivative ID, so nothing
   * beyond the jsonrpc definition properties reaches discovery output.
   *
   * MCP's behavior annotations (readOnly, destructive, idempotent) have no
   * place in a JSON-RPC definition. Only readOnly has an effect here: ToolApi
   * refuses modifying tools over GET.
   */
  private function createDerivativeDefinition(
    JsonRpcMethodDefinition $base_plugin_definition,
    string $derivative_id,
    string $tool_id,
    string $description,
  ): JsonRpcMethodDefinition {
    $tool = $this->createInvokerAwareTool($tool_id);
    return new JsonRpcMethodDefinition([
      'id' => $base_plugin_definition->id() . ':' . $derivative_id,
      'class' => $base_plugin_definition->getClass(),
      'provider' => $base_plugin_definition->getProvider(),
      'usage' => new TranslatableMarkup('@text', ['@text' => $description]),
      'access' => $base_plugin_definition->access,
      'params' => $this->buildParams($this->toolDefinitionSerializer->normalizeInputSchema($tool)),
      'output' => $this->buildOutputSchema($tool),
    ]);
  }

  /**
   * Creates a tool instance bound to the JSON-RPC invoker.
   *
   * Schema normalization runs ToolInputDefinitionNormalizeEvent and
   * ToolOutputDefinitionNormalizeEvent subscribers against this instance, so
   * the invoker's capabilities (entities as handle strings) shape both the
   * advertised input and output schemas the same way they shape execution.
   *
   * @param string $tool_id
   *   The Tool API plugin ID.
   */
  private function createInvokerAwareTool(string $tool_id): ToolInterface {
    return $this->toolManager->createInstance(
      $tool_id,
      [],
      JsonRpcToolBridgeInvoker::create(),
    );
  }

  /**
   * Builds one named JSON-RPC parameter per tool input.
   *
   * Each parameter carries the input's own JSON Schema, so jsonrpc rejects a
   * value of the wrong shape with an invalid-params error before the tool
   * runs, as the MCP SDK does against the tool's inputSchema. Tool API's own
   * validation still runs on execution.
   *
   * A tool without inputs gets an empty list rather than NULL: jsonrpc reads
   * NULL as "accepts no parameters" and rejects even an empty params object,
   * which JSON-RPC allows and many clients send for such methods.
   *
   * @param array $input_schema
   *   The tool's input schema from ToolDefinitionSerializer.
   *
   * @return array<string, \Drupal\jsonrpc\Attribute\JsonRpcParameterDefinition>
   *   The parameter definitions keyed by input name.
   */
  private function buildParams(array $input_schema): array {
    $required = $input_schema['required'] ?? [];
    $params = [];
    foreach ($input_schema['properties'] as $name => $property_schema) {
      $description = $property_schema['description'] ?? NULL;
      $params[$name] = new JsonRpcParameterDefinition(
        id: $name,
        schema: self::plainSchema($property_schema),
        description: $description === NULL ? NULL : new TranslatableMarkup('@text', ['@text' => $description]),
        required: in_array($name, $required, TRUE),
      );
    }
    return $params;
  }

  /**
   * Builds the JSON Schema of the result ToolApi returns.
   *
   * The schema describes the tool's outputs keyed by output name, exactly as
   * ToolApi::execute() returns them. Failures are JSON-RPC errors, so the
   * schema does not need to account for them.
   *
   * No member is listed as required: Tool API does not enforce that a
   * successful result sets every declared output (tool#3583029), and
   * declaring them required would make results violate the schema the tool
   * advertises. That matters more here than over MCP, because jsonrpc
   * validates every result against the method's output schema.
   *
   * @param \Drupal\tool\Tool\ToolInterface $tool
   *   The invoker-aware tool instance.
   *
   * @return array
   *   A JSON Schema object. A tool without declared outputs may still pass
   *   undeclared values through, so its result is any object.
   */
  private function buildOutputSchema(ToolInterface $tool): array {
    $outputs = $this->toolDefinitionSerializer->normalizeOutputDefinitions($tool);
    if ($outputs === []) {
      return ToolApi::outputSchema();
    }
    return self::plainSchema([
      'type' => 'object',
      'properties' => $outputs,
    ]);
  }

  /**
   * Turns a Tool API schema into plain arrays that keep their meaning.
   *
   * Tool API writes an empty "properties" as \ArrayObject so it encodes as
   * {} rather than []. jsonrpc_discovery normalizes definitions with the
   * serializer, which turns any \ArrayObject back into [], an invalid
   * "properties". An empty "properties" constrains nothing, so it is left
   * out instead. A non-empty \ArrayObject becomes the keyed array it holds,
   * which encodes as an object anyway.
   *
   * @param array<mixed> $schema
   *   The schema.
   *
   * @return array<mixed>
   *   The schema without \ArrayObject members.
   */
  private static function plainSchema(array $schema): array {
    foreach ($schema as $key => $value) {
      if ($value instanceof \ArrayObject) {
        $value = $value->getArrayCopy();
        if ($value === [] && $key === 'properties') {
          unset($schema[$key]);
          continue;
        }
      }
      if (is_array($value)) {
        $schema[$key] = self::plainSchema($value);
      }
    }
    return $schema;
  }

}
