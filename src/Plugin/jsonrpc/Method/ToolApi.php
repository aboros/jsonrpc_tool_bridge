<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\jsonrpc\Attribute\JsonRpcMethod;
use Drupal\jsonrpc\Handler;
use Drupal\jsonrpc\JsonRpcObject\ParameterBag;
use Drupal\jsonrpc\JsonRpcObject\Response;
use Drupal\jsonrpc\MethodInterface;
use Drupal\jsonrpc\Plugin\JsonRpcMethodBase;
use Drupal\jsonrpc_tool_bridge\Entity\JsonRpcToolConfig;
use Drupal\jsonrpc_tool_bridge\JsonRpcToolBridgeInvoker;
use Drupal\jsonrpc_tool_bridge\Plugin\Derivative\JsonRpcToolConfigDeriver;
use Drupal\tool\Exception\InputException;
use Drupal\tool\FailureCategory;
use Drupal\tool\Handle\HandleExceptionInterface;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\Tool\ToolManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * JSON-RPC method that exposes Tool API tools.
 *
 * Each enabled JsonRpcToolConfig entity is surfaced as a distinct derivative
 * by JsonRpcToolConfigDeriver, named "tool_api:<config_id>". The derivative
 * ID names the config entity, which binds the method to one Tool API tool.
 *
 * Execution mirrors mcp_server_tool_bridge's ToolApi: the result is a
 * CallToolResult-shaped envelope, and a failed tool call is a successful
 * JSON-RPC response with isError set, not a JSON-RPC error.
 */
#[JsonRpcMethod(
  id: JsonRpcToolConfig::BASE_METHOD,
  usage: new TranslatableMarkup('Provides Tool API tools as JSON-RPC methods.'),
  access: ['access jsonrpc tool bridge'],
  deriver: JsonRpcToolConfigDeriver::class,
)]
final class ToolApi extends JsonRpcMethodBase {

  /**
   * Constructs a ToolApi object.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    MethodInterface $plugin_definition,
    protected ToolManager $toolManager,
    protected LoggerChannelInterface $logger,
    protected ToolDefinitionSerializer $toolDefinitionSerializer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected KillSwitch $pageCacheKillSwitch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.tool'),
      $container->get('logger.factory')->get('jsonrpc_tool_bridge'),
      $container->get('tool.definition_serializer'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * A success carries the tool's outputs as structuredContent, which is what
   * the output schema JsonRpcToolConfigDeriver advertises describes. A
   * failure sets isError with the explanation in content and no
   * structuredContent. Either way the response is never cached.
   */
  public function execute(ParameterBag $params): Response {
    return $this->respond($this->callTool($params));
  }

  /**
   * Runs the bridged tool and builds the result envelope.
   *
   * @param \Drupal\jsonrpc\JsonRpcObject\ParameterBag $params
   *   The JSON-RPC parameters, one per tool input.
   *
   * @return array{content: list<array{type: string, text: string}>, isError: bool, structuredContent?: array<string, mixed>}
   *   The result envelope.
   */
  private function callTool(ParameterBag $params): array {
    $plugin_id = $this->loadToolPluginId();
    if ($plugin_id === NULL) {
      return $this->failure('ToolApi derivative is missing its tool_id definition.', NULL, FailureCategory::Runtime);
    }
    $tool = NULL;

    try {
      // The invoker's EntitiesAsHandles capability enables handle
      // resolution on input and entity-to-handle on output via Tool API's
      // own transform subscriber.
      $tool = $this->toolManager->createInstance(
        $plugin_id,
        [],
        JsonRpcToolBridgeInvoker::create(),
      );

      // The jsonrpc module accepts every method over GET, and a GET response
      // can be cached and replayed by intermediaries. A tool that changes
      // data only runs on POST, before any input reaches it.
      if ($this->isSafeHttpRequest() && $this->isModifying($tool)) {
        return $this->failure('Tool modifies data and must be called with POST.', $tool, FailureCategory::Access);
      }

      // Set input values — ToolInputTransformEvent handles upcast
      // (e.g., handle string → entity) and type coercion automatically.
      foreach ($tool->getInputDefinitions() as $name => $definition) {
        if ($params->has($name)) {
          $tool->setInputValue($name, $params->get($name));
        }
      }

      // Validate explicitly before the access check: access() fails closed
      // with a generic reason on invalid input (Tool API #3582961), and an
      // "access denied" reply would wrongly suggest a permission problem
      // rather than fixable arguments — clients need the violation detail
      // to self-correct.
      $violations = $tool->validateInputs();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $this->plainText($violation->getMessage());
        }
        return $this->failure("Tool plugin execution failed:\n" . implode("\n", $messages), $tool, FailureCategory::Input);
      }

      $access = $tool->access(NULL, TRUE);
      if (!$access->isAllowed()) {
        // getReason() always returns a string, so the reason is sent as the
        // tool gave it, as tool:run does.
        $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
        $message = $reason ? 'Tool plugin access denied: ' . $reason : 'Tool plugin access denied.';
        return $this->failure($message, $tool, FailureCategory::Access);
      }

      $tool->execute();

      // Use getFormattedResult() which applies output transforms
      // (entity → handle) and collects hints automatically.
      $formatted = $tool->getFormattedResult();
      $message = $this->plainText($formatted->getMessage());
      // Append hints to the message for the client.
      if ($formatted->getHints()) {
        $message .= "\n" . implode("\n", $formatted->getHints());
      }

      if (!$formatted->isSuccess()) {
        // The category on the result decides whether the schema is echoed:
        // a failure inside the wrapped tool's own execute() cannot be fixed
        // by reshaping the arguments unless it says so (#3582993).
        return $this->failure($message, $tool, $formatted->getFailureCategory() ?? FailureCategory::Runtime);
      }
      return $this->success($formatted->getContextValues(), $message);
    }
    catch (InputException | HandleExceptionInterface $e) {
      // Both come from setInputValue() above, before validateInputs() can
      // name the problem in violation text: InputException when a value
      // cannot be stored at all (e.g. a scalar passed for a map input), a
      // handle exception when the input transform event rejects a
      // malformed, missing, or wrong-type entity handle. Still an input
      // problem, so it keeps the input schema.
      // @see \Drupal\tool\Handle\HandleExceptionInterface
      return $this->inputFailure($plugin_id, $e, $tool);
    }
    catch (\Throwable $e) {
      // Anything else caught here is a bug in this method's own code, not
      // in the wrapped tool's business logic. Reshaping the arguments
      // cannot fix it, so the schema is omitted.
      $this->logger->error('Tool execution failed for @tool: @message', [
        '@tool' => $plugin_id,
        '@message' => $e->getMessage(),
      ]);

      return $this->failure('Tool plugin execution failed: ' . $e->getMessage(), $tool, FailureCategory::Runtime);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Describes the base definition only. Each derivative advertises the same
   * envelope with its own structuredContent schema.
   */
  public static function outputSchema(): array {
    return self::envelopeSchema(NULL);
  }

  /**
   * Builds the JSON Schema of the result envelope.
   *
   * The envelope has the shape of an MCP CallToolResult, so a client can
   * handle results from both bridges the same way.
   *
   * @param array|null $structured_content_schema
   *   The schema of the tool's outputs, or NULL when the tool declares none.
   *   structuredContent is then left undescribed: a tool without declared
   *   outputs may still pass undeclared values through.
   *
   * @return array<string, mixed>
   *   The JSON Schema.
   */
  public static function envelopeSchema(?array $structured_content_schema): array {
    $schema = [
      'type' => 'object',
      'properties' => [
        'content' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'type' => ['type' => 'string', 'enum' => ['text']],
              'text' => ['type' => 'string'],
            ],
            'required' => ['type', 'text'],
          ],
        ],
        'isError' => ['type' => 'boolean'],
      ],
      'required' => ['content', 'isError'],
    ];
    if ($structured_content_schema !== NULL) {
      $schema['properties']['structuredContent'] = $structured_content_schema;
    }
    return $schema;
  }

  /**
   * Wraps the envelope in an uncacheable JSON-RPC response.
   *
   * The jsonrpc module caches GET responses by default. A tool result
   * depends on more than the request (the data it reads, the handles it
   * issues), and MCP responses are never cached either. The internal page
   * cache ignores max-age, so an anonymous GET also needs the kill switch.
   *
   * @param array<string, mixed> $envelope
   *   The result envelope.
   */
  private function respond(array $envelope): Response {
    $request = $this->currentRequest();
    // The Handler drops the response to a notification, but a Response
    // still needs a valid ID to be built.
    $id = $request->isNotification() ? NULL : $request->id();
    $response = new Response(Handler::SUPPORTED_VERSION, $id, $envelope);
    $response->mergeCacheMaxAge(0);
    $this->pageCacheKillSwitch->trigger();
    return $response;
  }

  /**
   * Loads the Tool API plugin ID the method is bound to.
   *
   * @return string|null
   *   The Tool API plugin ID, or NULL when no enabled config entity backs
   *   this derivative.
   */
  private function loadToolPluginId(): ?string {
    $config_id = $this->getDerivativeId();
    if ($config_id === NULL) {
      return NULL;
    }
    $config = $this->entityTypeManager->getStorage('jsonrpc_tool_config')->load($config_id);
    if (!$config instanceof JsonRpcToolConfig || !$config->status() || $config->getToolId() === '') {
      return NULL;
    }
    return $config->getToolPluginId();
  }

  /**
   * Checks whether the JSON-RPC endpoint was called with a safe method.
   *
   * Only a request routed to the endpoint counts. Code that calls the
   * handler directly, such as a Drush command, also has a GET request on the
   * stack, but nothing caches or replays its result.
   */
  private function isSafeHttpRequest(): bool {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL
      && $request->attributes->get(RouteObjectInterface::ROUTE_NAME) === 'jsonrpc.handler'
      && $request->isMethodSafe();
  }

  /**
   * Checks whether the tool's operation modifies data.
   */
  private function isModifying(ToolInterface $tool): bool {
    $definition = $tool->getPluginDefinition();
    // An unknown operation is treated as modifying: refusing a GET is
    // recoverable, running a write from a cacheable request is not.
    return !$definition instanceof ToolDefinition || $definition->getOperation()->isModifying();
  }

  /**
   * Builds a failure result for an input-shaped exception.
   *
   * @param string $plugin_id
   *   The Tool API plugin ID, for the log entry.
   * @param \Throwable $exception
   *   The exception describing the invalid input.
   * @param \Drupal\tool\Tool\ToolInterface|null $tool
   *   The tool instance, when one was created.
   *
   * @return array<string, mixed>
   *   The failure envelope.
   */
  private function inputFailure(string $plugin_id, \Throwable $exception, ?ToolInterface $tool): array {
    $this->logger->error('Tool execution failed for @tool: @message', [
      '@tool' => $plugin_id,
      '@message' => $exception->getMessage(),
    ]);
    return $this->failure('Tool plugin execution failed: ' . $exception->getMessage(), $tool, FailureCategory::Input);
  }

  /**
   * Builds a successful result from the tool's outputs and message.
   *
   * The outputs are the structuredContent, matching the advertised output
   * schema. The message and hints are the first text block, so a client
   * finds the human-readable text at the same index on every outcome. The
   * outputs are mirrored as JSON in a second text block, as MCP does for
   * backwards compatibility. structuredContent is omitted when the outputs
   * are empty, as the MCP SDK does, so a tool without outputs sends only its
   * message.
   *
   * @param array<string, mixed> $outputs
   *   The formatted output values keyed by output name.
   * @param string $message
   *   The result message with any hints appended.
   *
   * @return array<string, mixed>
   *   The success envelope.
   */
  private function success(array $outputs, string $message): array {
    $content = [];
    if ($message !== '') {
      $content[] = $this->text($message);
    }
    if ($outputs !== []) {
      $content[] = $this->text($this->encode($outputs));
    }
    $envelope = [
      'content' => $content,
      'isError' => FALSE,
    ];
    if ($outputs !== []) {
      $envelope['structuredContent'] = $outputs;
    }
    return $envelope;
  }

  /**
   * Builds a failure result, echoing the input schema when it could help.
   *
   * A failure has no structuredContent: the output schema describes the
   * tool's outputs, and a failure has none. The explanation is the first
   * text block and the input schema, when echoed, the second.
   *
   * @param string $message
   *   The failure message.
   * @param \Drupal\tool\Tool\ToolInterface|null $tool
   *   The tool whose input schema should be echoed so clients can
   *   self-correct without a separate discovery round-trip, or NULL when
   *   no tool instance is available.
   * @param \Drupal\tool\FailureCategory $category
   *   The failure category. The schema is echoed only when the category
   *   says a retry with different arguments could fix the failure — an
   *   access denial or a runtime error cannot be fixed by reshaping the
   *   input, so attaching the schema there would only invite a wasted
   *   retry (#3582993).
   *
   * @return array<string, mixed>
   *   The failure envelope.
   */
  private function failure(string $message, ?ToolInterface $tool, FailureCategory $category): array {
    $content = [$this->text($message)];
    if ($tool !== NULL && $category->isCorrectable()) {
      $schema = $this->toolDefinitionSerializer->normalizeInputSchema($tool);
      $content[] = $this->text("Input schema:\n" . $this->encode($schema));
    }
    return [
      'content' => $content,
      'isError' => TRUE,
    ];
  }

  /**
   * Builds a text content block.
   *
   * @return array{type: string, text: string}
   *   The content block.
   */
  private function text(string $text): array {
    return ['type' => 'text', 'text' => $text];
  }

  /**
   * Converts a message to the plain text the client reads.
   *
   * Markup escapes its placeholder values, so the model would read quotes
   * as entities. Markup is rendered as plain text, as tool:run and
   * tool_ai_connector do (#3618726). A plain string is already plain text:
   * rendering it would strip anything that looks like a tag and decode
   * entities the tool wrote on purpose.
   *
   * @param string|\Stringable $message
   *   The message.
   *
   * @return string
   *   The message as plain text.
   */
  private function plainText(string|\Stringable $message): string {
    return $message instanceof MarkupInterface
      ? PlainTextOutput::renderFromHtml((string) $message)
      : (string) $message;
  }

  /**
   * Encodes a value the way the MCP SDK encodes an array result.
   *
   * Keeps slashes and unicode unescaped, so the mirrored text block is byte
   * for byte what mcp_server_tool_bridge sends.
   */
  private function encode(array $value): string {
    return json_encode(
      $value,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );
  }

}
