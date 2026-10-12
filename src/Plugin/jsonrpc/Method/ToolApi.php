<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge\Plugin\jsonrpc\Method;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\jsonrpc\Attribute\JsonRpcMethod;
use Drupal\jsonrpc\Handler;
use Drupal\jsonrpc\JsonRpcObject\Error;
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
 * The execution flow is mcp_server_tool_bridge's, but the response is plain
 * JSON-RPC: a successful call returns the tool's outputs as the result, and
 * a failed one is a JSON-RPC error whose code follows the Tool API failure
 * category.
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
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected KillSwitch $pageCacheKillSwitch,
    protected ConfigFactoryInterface $configFactory,
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
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('page_cache_kill_switch'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * A success returns the tool's outputs, which is what the output schema
   * JsonRpcToolConfigDeriver advertises describes. A failure is an error
   * response. Either way the response is never cached.
   */
  public function execute(ParameterBag $params): Response {
    $outcome = $this->callTool($params);
    $request = $this->currentRequest();
    // The Handler drops the response to a notification, but a Response
    // still needs a valid ID to be built.
    $id = $request->isNotification() ? NULL : $request->id();
    $response = $outcome instanceof Error
      ? new Response(Handler::SUPPORTED_VERSION, $id, NULL, $outcome)
      : new Response(Handler::SUPPORTED_VERSION, $id, $outcome);
    // The jsonrpc module caches GET responses by default. A tool result
    // depends on more than the request (the data it reads, the handles it
    // issues), and MCP responses are never cached either. The internal page
    // cache ignores max-age, so an anonymous GET also needs the kill switch.
    $response->mergeCacheMaxAge(0);
    $this->pageCacheKillSwitch->trigger();
    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * Describes the base definition only. Each derivative advertises its
   * tool's output schema instead.
   */
  public static function outputSchema(): array {
    return ['type' => 'object'];
  }

  /**
   * Runs the bridged tool.
   *
   * @param \Drupal\jsonrpc\JsonRpcObject\ParameterBag $params
   *   The JSON-RPC parameters, one per tool input.
   *
   * @return array<string, mixed>|\stdClass|\Drupal\jsonrpc\JsonRpcObject\Error
   *   The tool's outputs keyed by output name, an empty object for a tool
   *   that returned none, or the error to respond with.
   */
  private function callTool(ParameterBag $params): array|\stdClass|Error {
    $plugin_id = $this->loadToolPluginId();
    if ($plugin_id === NULL) {
      return Error::methodNotFound($this->getPluginId());
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
        return $this->failure('Tool modifies data and must be called with POST.', FailureCategory::Access);
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
      // access denial would wrongly suggest a permission problem rather than
      // fixable parameters.
      $violations = $tool->validateInputs();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $this->plainText($violation->getMessage());
        }
        return $this->failure("Tool plugin execution failed:\n" . implode("\n", $messages), FailureCategory::Input);
      }

      $access = $tool->access(NULL, TRUE);
      if (!$access->isAllowed()) {
        // getReason() always returns a string, so the reason is sent as the
        // tool gave it, as tool:run does.
        $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
        $message = $reason ? 'Tool plugin access denied: ' . $reason : 'Tool plugin access denied.';
        return $this->failure($message, FailureCategory::Access);
      }

      $tool->execute();

      // Use getFormattedResult() which applies output transforms
      // (entity → handle).
      $formatted = $tool->getFormattedResult();

      if (!$formatted->isSuccess()) {
        // The category on the result decides the error code: a failure
        // inside the wrapped tool's own execute() is not a parameter problem
        // unless it says so (#3582993).
        return $this->failure($this->plainText($formatted->getMessage()), $formatted->getFailureCategory() ?? FailureCategory::Runtime);
      }
      // The result message and its hints are meant for a model reading the
      // tool's output. A JSON-RPC result is the return value alone, and an
      // empty one is still an object.
      return $formatted->getContextValues() ?: new \stdClass();
    }
    catch (InputException | HandleExceptionInterface $e) {
      // Both come from setInputValue() above, before validateInputs() can
      // name the problem in violation text: InputException when a value
      // cannot be stored at all (e.g. a scalar passed for a map input), a
      // handle exception when the input transform event rejects a
      // malformed, missing, or wrong-type entity handle. Still a parameter
      // problem the client can fix.
      // @see \Drupal\tool\Handle\HandleExceptionInterface
      $this->logFailure($plugin_id, $e);
      return $this->failure('Tool plugin execution failed: ' . $e->getMessage(), FailureCategory::Input);
    }
    catch (\Throwable $e) {
      // Anything else caught here is a bug in this method's own code, not
      // in the wrapped tool's business logic. As for any other exception in
      // a JSON-RPC method, its message only reaches the client when the
      // site displays errors.
      $this->logFailure($plugin_id, $e);
      return Error::internalError($this->displaysErrors() ? 'Tool plugin execution failed: ' . $e->getMessage() : NULL);
    }
  }

  /**
   * Builds the JSON-RPC error for a Tool API failure.
   *
   * The code follows the failure category, with the codes the jsonrpc module
   * itself uses: a problem with the parameters is "Invalid params", an access
   * denial is "Invalid request" as when the method permission is missing,
   * and anything else is "Internal error". The message is the error data.
   *
   * @param string $message
   *   The failure message.
   * @param \Drupal\tool\FailureCategory $category
   *   The failure category.
   */
  private function failure(string $message, FailureCategory $category): Error {
    return match ($category) {
      FailureCategory::Input => Error::invalidParams($message),
      FailureCategory::Access => Error::invalidRequest($message),
      FailureCategory::Runtime => Error::internalError($message),
    };
  }

  /**
   * Logs an exception caught while running a tool.
   */
  private function logFailure(string $plugin_id, \Throwable $exception): void {
    $this->logger->error('Tool execution failed for @tool: @message', [
      '@tool' => $plugin_id,
      '@message' => $exception->getMessage(),
    ]);
  }

  /**
   * Checks whether the site shows error messages, as jsonrpc does.
   *
   * @see \Drupal\jsonrpc\Exception\JsonRpcException::fromPrevious()
   */
  private function displaysErrors(): bool {
    $error_level = $this->configFactory->get('system.logging')->get('error_level') ?? ERROR_REPORTING_HIDE;
    return $error_level !== ERROR_REPORTING_HIDE;
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
   * Converts a message to plain text.
   *
   * Markup escapes its placeholder values, so a client would read quotes as
   * entities. Markup is rendered as plain text, as tool:run and
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

}
