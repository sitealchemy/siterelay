#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT
(
  cd wordpress
  zip -q -X "$work_dir/siterelay-wordpress.zip" siterelay/siterelay.php
)
(
  cd desktop
  zip -q -X "$work_dir/siterelay.mcpb" manifest.json server/index.js
)
mv "$work_dir/siterelay-wordpress.zip" "$work_dir/siterelay.mcpb" dist/
