# SiteRelay by Brainode

Manage a self-hosted WordPress site from Claude Desktop. SiteRelay connects the desktop app to WordPress over HTTPS, with one settings screen for administrators.

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

You need a self-hosted WordPress site with HTTPS, WordPress 6.5 or newer, PHP 8.0 or newer, and a Claude Desktop installation that allows local MCP extensions. Some managed hosts disable file editing or require filesystem credentials for updates. SiteRelay respects WordPress permissions and those host restrictions.

1. Download [the WordPress plugin](packages/siterelay-wordpress.zip?raw=true) and [the Claude Desktop extension](packages/siterelay.mcpb?raw=true). To build from source, run `bash build.sh` with Bash and `zip`. The resulting files are in `dist/`.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, choose `siterelay-wordpress.zip` and activate SiteRelay.
3. Generate a unique token. On macOS or Linux, run `openssl rand -hex 32`. On Windows PowerShell, run the following.

   ```powershell
   $bytes = New-Object byte[] 32
   $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
   $rng.GetBytes($bytes)
   $rng.Dispose()
   [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
   ```

4. Open **Settings → SiteRelay** as a WordPress administrator. Paste the token, save it and copy the full **Connection URL**.
5. In Claude Desktop, open **Settings → Extensions**. Use **Advanced settings → Install Extension…** to install `siterelay.mcpb`. Enter the connection URL and the same token in the extension settings.
6. Start a conversation in Claude Desktop and ask “Check the WordPress version and active theme using SiteRelay.”

The SiteRelay token authenticates Claude Desktop to your WordPress site. SiteRelay uses Claude Desktop’s existing model access. The plugin stores a SHA-256 digest and the ID of the administrator who saved it. The password field is empty on reload. Save a new token to rotate access or click **Revoke token** to disconnect the site.

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
- Existing content, CSS and file edits require a matching revision. Their responses include previous values that Claude can use to prepare a reversal. Updates use WordPress’s native upgrader and require a separate backup for dependable recovery. Start on staging for PHP edits, theme activation and updates.
- PHP editing follows the native WordPress editor, including its loopback checks for active code. Depending on the host, a remote edit can be rejected and reverted by WordPress. Theme creation requires direct write access to the theme directory.
- The first version supports standard posts, pages and attachments. It has no integration with page builders, help desks, inbound email or automatic reply delivery. It provides basic diagnostics from the WordPress inventory and can use the details of a support issue that you supply in Claude.
- If WordPress is behind a TLS-terminating proxy, it must correctly recognize the connection as HTTPS. Use the exact URL from the settings screen, including its subdirectory or `rest_route` query parameter when present.

## Source layout

- `wordpress/siterelay/siterelay.php` contains the WordPress plugin.
- `desktop/manifest.json` and `desktop/server/index.js` contain the MCP desktop extension.
- `build.sh` builds both installable archives.
