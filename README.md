# SiteRelay by Brainode

Manage a self-hosted WordPress site from a Claude conversation. SiteRelay runs an MCP server inside WordPress and adds one settings screen for administrators.

## What you can do

| Task | SiteRelay tools |
| --- | --- |
| Content | Find, read and edit pages and posts. Create drafts and publish when requested. |
| Appearance | Edit the active theme’s Additional CSS. Create an inactive block theme and activate it separately. |
| Files | Read and replace existing CSS, JS, HTML, JSON and PHP files exposed by the WordPress theme or plugin editor. |
| Media | Browse attachments, import a file from a direct public HTTPS URL and edit its title, caption, description or alt text. |
| Maintenance | Inspect available WordPress, theme and plugin updates and apply one versioned update at a time. |
| Support | Inspect versions and active components, investigate a user-reported issue and draft a reply in Claude. |

## Install

Use WordPress 6.5+, PHP 8.0+, MySQL or MariaDB, and HTTPS.

1. [Download the WordPress plugin](packages/siterelay-wordpress.zip?raw=true). In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP and activate SiteRelay.
2. Open **Settings → SiteRelay** as an administrator. Click **Generate token and authorize access**. SiteRelay generates, copies and saves the token in one step. If the browser blocks clipboard access, copy the displayed token and save it manually. Copy the **MCP server URL** from the same screen. Save the token somewhere private if you need to use it again.
3. In Claude, open **Customize → Connectors → Add custom connector**. Name it **SiteRelay** and paste the MCP server URL. Choose **No sign in**. In **Request headers**, add the custom header `X-SiteRelay-Token` and paste the generated token as its value.
4. Enable SiteRelay in a normal Claude conversation and ask “Check the WordPress version and active theme using SiteRelay.”

This route uses Claude’s [custom request headers](https://claude.com/docs/connectors/custom/add-unlisted#authenticate-with-request-headers), which are in limited beta. It requires a WordPress endpoint that Anthropic can reach over the internet. Use the desktop extension if the **Request headers** field is unavailable or the site is accessible only from your network.

The SiteRelay token authenticates the connector to your WordPress site. It uses Claude’s existing model access. WordPress stores a SHA-256 digest and the ID of the administrator who saved the token. The password field is empty on reload. Save a new token to rotate access, or click **Revoke token** to disconnect the site.

### Local Claude Desktop extension

1. [Download the Claude Desktop extension](packages/siterelay.mcpb?raw=true).
2. In Claude Desktop, open **Settings → Extensions → Advanced settings → Install Extension…** and choose `siterelay.mcpb`.
3. In the extension settings, paste the **Local Claude Desktop extension URL** from WordPress and the same token.

### Manual desktop setup

For a local MCP setup, you can point `claude_desktop_config.json` at `desktop/server/index.js` using an absolute path and Node.js 18 or newer. Merge this entry into your existing `mcpServers` object and restart Claude Desktop. This approach puts the token in your local JSON config. Use the extension’s sensitive field when available, and keep personal configuration out of Git.

```json
{
  "mcpServers": {
    "siterelay": {
      "command": "node",
      "args": ["/absolute/path/to/siterelay/desktop/server/index.js"],
      "env": {
        "SITERELAY_URL": "https://example.com/wp-json/siterelay/v1/action",
        "SITERELAY_TOKEN": "YOUR_64_CHARACTER_TOKEN"
      }
    }
  }
}
```

## Example requests

- “Prepare a draft of a new Services page. Use the existing About page for tone.”
- “Inspect the theme and show me the CSS changes you would make to improve mobile navigation.”
- “Create an inactive block theme named Studio. Use warm neutral colors and large headings.”
- “List the available updates. Wait for my confirmation before installing any.”
- “A user reports that the contact form has stopped submitting. Check the installed plugins and WordPress version, then suggest how to reproduce the issue and draft a reply.”

## Access and recovery

- The only WordPress interface added by SiteRelay is **Settings → SiteRelay**. The REST endpoint requires the token over HTTPS, then checks the token owner’s current role and the WordPress capabilities for the operation.
- The token grants administrative operations within SiteRelay. Keep Claude Desktop’s approval prompts enabled for writes and avoid saving the token in chat. Tool results sent to Claude can include site content and source code.
- Existing content, CSS and file edits require a matching revision. SiteRelay stores up to 12 recent checkpoints for edits to existing content, CSS and files, and for theme switches. Ask Claude to list the checkpoints and restore one. Restoration checks that the target has not changed since the checkpoint was saved. The history is capped at 1 MiB and large snapshots can be skipped. Package updates, newly created items and media imports require their usual WordPress recovery workflows.
- PHP editing follows the native WordPress editor, including its loopback checks for active code. Depending on the host, a remote edit can be rejected and reverted by WordPress. Theme creation requires direct write access to the theme directory.
- The first version supports standard posts, pages and attachments. It has no integration with page builders, help desks, inbound email or automatic reply delivery. It provides basic diagnostics from the WordPress inventory and can use the details of a support issue that you supply in Claude.
- If WordPress is behind a TLS-terminating proxy, it must correctly recognize the connection as HTTPS. Use the exact URL from the settings screen, including its subdirectory or `rest_route` query parameter when present.

## Source layout

- `wordpress/siterelay/siterelay.php` contains the WordPress plugin.
- `desktop/manifest.json` and `desktop/server/index.js` contain the MCP desktop extension.
- `build.sh` builds both installable archives in `dist/`. It requires Bash and `zip`.
