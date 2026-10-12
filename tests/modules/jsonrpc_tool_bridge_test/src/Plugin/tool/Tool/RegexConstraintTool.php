<?php

declare(strict_types=1);

namespace Drupal\jsonrpc_tool_bridge_test\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;

/**
 * Test tool carrying Regex constraints on its input definitions.
 *
 * Each input covers one shape the deriver has to cope with when converting a
 * PCRE pattern into a JSON Schema "pattern": the conventional slash delimiter,
 * a slash delimiter followed by a modifier, an alternative delimiter chosen so
 * the body can contain slashes, and a pattern already authored bare.
 *
 * The bare "slug" input is exercised through the generated schema only. A bare
 * pattern is not a valid argument to preg_match(), so Symfony's RegexValidator
 * cannot enforce it at runtime; leaving the input optional and unset keeps
 * validation from ever reaching it.
 *
 * Copied from mcp_server_tool_bridge's mcp_server_tool_test module
 * (GPL-2.0-or-later).
 */
#[Tool(
  id: 'jsonrpc_tool_test:regex_constraint',
  label: new TranslatableMarkup('Regex Constraint Test Tool'),
  description: new TranslatableMarkup('A test tool whose inputs carry regex constraints'),
  operation: ToolOperation::Read,
  input_definitions: [
    'username' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Username'),
      description: new TranslatableMarkup('Alphanumeric username, slash delimited pattern'),
      required: TRUE,
      constraints: [
        'Regex' => ['pattern' => '/^[a-zA-Z0-9_]+$/'],
      ],
    ),
    'code' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Code'),
      description: new TranslatableMarkup('Code whose pattern carries a case-insensitive modifier'),
      required: FALSE,
      constraints: [
        'Regex' => ['pattern' => '/^[A-Z]+-\d{3}$/i'],
      ],
    ),
    'path' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Path'),
      description: new TranslatableMarkup('Path whose pattern uses a hash delimiter'),
      required: FALSE,
      constraints: [
        'Regex' => ['pattern' => '#^/[a-z]+(/[a-z]+)*$#'],
      ],
    ),
    'slug' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Slug'),
      description: new TranslatableMarkup('Slug whose pattern is already bare'),
      required: FALSE,
      constraints: [
        'Regex' => ['pattern' => '^[a-z]+(-[a-z]+)*$'],
      ],
    ),
  ],
)]
final class RegexConstraintTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    return ExecutableResult::success(
      $this->t('Regex constrained inputs accepted'),
      ['result' => implode(' | ', array_filter($values, 'is_string'))]
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    // For testing purposes, allow access to all users.
    $access_result = AccessResult::allowed();
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

}
