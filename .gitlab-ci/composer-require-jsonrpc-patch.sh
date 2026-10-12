#!/usr/bin/env bash
set -euo pipefail

# The bridge needs jsonrpc to support derivers, per-definition output
# schemas and a definition cache tag. Until that lands upstream, apply the
# patch shipped in patches/ to whichever jsonrpc 3.x release Composer picks.
composer config --no-plugins allow-plugins.cweagans/composer-patches true
composer require cweagans/composer-patches:^1.7 --no-update
composer config --json extra.patches.drupal/jsonrpc \
  "{\"Support derivers and per-definition output schemas\": \"${CI_PROJECT_DIR}/patches/jsonrpc-derivative-support.patch\"}"
