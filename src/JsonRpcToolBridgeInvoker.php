<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge;

use Drupal\tool\Tool\Invoker;
use Drupal\tool\Tool\InvokerCapability;

/**
 * Defines the invoker identity for the JSON-RPC tool bridge.
 *
 * The ID is shared between execution (ToolApi) and schema normalization
 * (JsonRpcToolConfigDeriver). The factory is the single place that declares
 * what the bridge needs from Tool API.
 */
final class JsonRpcToolBridgeInvoker {

  /**
   * The invoker ID used to identify JSON-RPC tool executions.
   */
  public const ID = 'jsonrpc';

  /**
   * Builds the invoker for JSON-RPC tool executions.
   *
   * Entities cannot cross the JSON-RPC wire boundary, so they travel as
   * handle strings.
   */
  public static function create(): Invoker {
    return new Invoker(self::ID, InvokerCapability::EntitiesAsHandles);
  }

}
