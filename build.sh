#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p dist
work_dir=$(mktemp -d)
trap 'rm -rf "$work_dir"' EXIT
(
  cd wordpress
  zip -q -X "$work_dir/siterelay-wordpress.zip" siterelay/siterelay.php siterelay/tools.json
)
(
  cp -R desktop "$work_dir/desktop"
  cp wordpress/siterelay/tools.json "$work_dir/desktop/server/"
  cd "$work_dir/desktop"
  zip -q -X "$work_dir/siterelay.mcpb" manifest.json server/index.js server/tools.json
)
mv "$work_dir/siterelay-wordpress.zip" "$work_dir/siterelay.mcpb" dist/
