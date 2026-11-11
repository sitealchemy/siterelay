<?php
/**
 * Plugin Name: SiteRelay
 * Description: Connect Claude Desktop to this WordPress site for content, media, design and maintenance.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Brainode
 */

defined('ABSPATH') || exit;

final class SiteRelay {
    private const OPTION = 'siterelay_connection';
    private const ROUTE = '/siterelay/v1/action';

    public static function boot(): void {
        add_action('admin_menu', static function () {
            if (!self::is_admin_user()) {
                return;
            }
            add_options_page('SiteRelay', 'SiteRelay', 'manage_options', 'siterelay', [self::class, 'settings']);
        });
        add_action('admin_post_siterelay_save', [self::class, 'save']);
        add_action('rest_api_init', static function () {
            register_rest_route('siterelay/v1', '/action', [
                'methods' => 'POST',
                'permission_callback' => [self::class, 'authorize'],
                'callback' => [self::class, 'dispatch'],
            ]);
        });
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if ($request->get_route() === self::ROUTE) {
                $response->header('Cache-Control', 'no-store, private');
            }
            return $response;
        }, 10, 3);
    }

    private static function is_admin_user(): bool {
        $user = wp_get_current_user();
        return current_user_can('manage_options') && (in_array('administrator', $user->roles, true) || (is_multisite() && is_super_admin($user->ID)));
    }

    public static function settings(): void {
        if (!self::is_admin_user()) {
            wp_die('Only WordPress administrators can configure SiteRelay.', '', ['response' => 403]);
        }
        $connected = (bool) get_option(self::OPTION);
        ?>
        <div class="wrap">
            <h1>SiteRelay</h1>
            <p>Connect SiteRelay in Claude Desktop with this URL and the same token.</p>
            <p><label for="siterelay-url">Connection URL</label><br>
                <input id="siterelay-url" class="large-text" readonly value="<?php echo esc_attr(rest_url(self::ROUTE)); ?>">
            </p>
            <p><?php echo $connected ? 'A token is configured.' : 'No token configured.'; ?></p>
            <?php if (!is_ssl()) : ?>
                <p>Open this screen over HTTPS to manage the connection.</p>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="siterelay_save">
                <?php wp_nonce_field('siterelay_settings'); ?>
                <label for="siterelay-token">New token (64 lowercase hexadecimal characters)</label><br>
                <input type="password" id="siterelay-token" name="token" class="regular-text" autocomplete="new-password" pattern="[a-f0-9]{64}" value="">
                <p class="description">Generate a new token with <code>openssl rand -hex 32</code>. The saved token cannot be displayed.</p>
                <p><button class="button button-primary" name="mode" value="save">Save token</button>
                <?php if ($connected) : ?>
                    <button class="button" name="mode" value="revoke" formnovalidate>Revoke token</button>
                <?php endif; ?></p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save(): void {
        if (!self::is_admin_user() || !is_ssl() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Access denied.', '', ['response' => 403]);
        }
        check_admin_referer('siterelay_settings');
        $mode = $_POST['mode'] ?? '';
        if ($mode === 'revoke') {
            delete_option(self::OPTION);
        } elseif ($mode === 'save') {
            $token = isset($_POST['token']) && is_string($_POST['token']) ? trim(wp_unslash($_POST['token'])) : '';
            if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
                wp_die('Enter a newly generated token with 64 lowercase hexadecimal characters.', '', ['response' => 400, 'back_link' => true]);
            }
            update_option(self::OPTION, ['hash' => hash('sha256', $token), 'user' => get_current_user_id()], false);
        }
        wp_safe_redirect(admin_url('options-general.php?page=siterelay'));
        exit;
    }

    public static function authorize(WP_REST_Request $request) {
        $token = $request->get_header('x_siterelay_token');
        $saved = get_option(self::OPTION);
        if (!is_ssl() || !is_string($saved['hash'] ?? null) || !is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token) || !hash_equals($saved['hash'], hash('sha256', $token))) {
            return new WP_Error('siterelay_unauthorized', 'Valid SiteRelay token and HTTPS required.', ['status' => 401]);
        }

        wp_set_current_user((int) $saved['user']);
        if (!self::is_admin_user()) {
            return new WP_Error('siterelay_forbidden', 'The token owner must still be an administrator.', ['status' => 403]);
        }
        return true;
    }

    private static function require_cap(string $capability, ...$args): void {
        if (!current_user_can($capability, ...$args)) {
            throw new RuntimeException('WordPress capability required: ' . $capability, 403);
        }
    }

    private static function value(array $args, string $key, int $limit = 262144): string {
        $value = $args[$key] ?? null;
        if (!is_string($value) || strlen($value) > $limit) {
            throw new RuntimeException("Invalid value for {$key}.", 400);
        }
        return $value;
    }

    private static function id(array $args): int {
        if (!isset($args['id']) || !is_int($args['id']) || $args['id'] < 1) {
            throw new RuntimeException('A positive integer ID is required.', 400);
        }
        return $args['id'];
    }

    private static function ensure($result) {
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message(), 422);
        }
        if (!$result) {
            throw new RuntimeException('WordPress could not complete this operation.', 422);
        }
        return $result;
    }

    private static function unchanged(string $expected, string $current): void {
        if (!hash_equals($current, $expected)) {
            throw new RuntimeException('The target changed. Read it again before applying the change.', 409);
        }
    }

    public static function dispatch(WP_REST_Request $request) {
        $args = $request->get_json_params();
        if (!is_array($args) || !is_string($args['operation'] ?? null) || !is_array($args['input'] ?? null) || strlen($request->get_body()) > 1024 * 1024) {
            return new WP_Error('siterelay_request', 'Invalid JSON request or payload too large.', ['status' => 400]);
        }
        $op = $args['operation'];
        $input = $args['input'];
        $writes = ['save_content', 'save_css', 'write_file', 'create_theme', 'switch_theme', 'import_media', 'update_component'];
        try {
            if (!in_array($op, $writes, true)) {
                return rest_ensure_response(self::run($op, $input));
            }

            global $wpdb;
            $lock = 'siterelay_' . md5(DB_NAME . ':' . $wpdb->base_prefix);
            if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
                throw new RuntimeException('Another SiteRelay write is running or the database does not support locking.', 409);
            }
            try {
                return rest_ensure_response(self::run($op, $input));
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        } catch (RuntimeException $error) {
            $status = in_array($error->getCode(), [400, 403, 404, 409, 422], true) ? $error->getCode() : 500;
            return new WP_Error('siterelay_action', $error->getMessage(), ['status' => $status]);
        }
    }

    private static function run(string $op, array $args): array {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        return match ($op) {
            'site_status' => self::status(),
            'list_content' => self::list_content($args),
            'get_content' => self::get_content(self::id($args)),
            'save_content' => self::save_content($args),
            'get_css', 'save_css' => self::css($op, $args),
            'list_files', 'read_file', 'write_file' => self::files($op, $args),
            'create_theme' => self::create_theme($args),
            'switch_theme' => self::switch_theme($args),
            'import_media' => self::import_media($args),
            'list_updates', 'update_component' => self::updates($op, $args),
            default => throw new RuntimeException('Unknown SiteRelay operation.', 404),
        };
    }

    private static function status(): array {
        $plugins = [];
        foreach (get_plugins() as $id => $plugin) {
            $plugins[] = ['id' => $id, 'name' => $plugin['Name'], 'version' => $plugin['Version'], 'active' => is_plugin_active($id)];
        }
        $themes = [];
        foreach (wp_get_themes() as $id => $theme) {
            $themes[] = ['id' => $id, 'name' => $theme->get('Name'), 'version' => $theme->get('Version')];
        }
        return [
            'site' => home_url('/'), 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
            'active_theme' => get_stylesheet(), 'parent_theme' => get_template(), 'plugins' => $plugins,
            'themes' => $themes, 'multisite' => is_multisite(), 'permalink_structure' => get_option('permalink_structure'),
            'debug_enabled' => WP_DEBUG, 'max_upload_bytes' => wp_max_upload_size(),
            'file_edits' => current_user_can('edit_themes') || current_user_can('edit_plugins'),
            'admin_site_health' => admin_url('site-health.php'),
        ];
    }

    private static function list_content(array $args): array {
        $type = $args['type'] ?? 'page';
        if (!in_array($type, ['page', 'post', 'attachment'], true)) {
            throw new RuntimeException('Choose page, post or attachment.', 400);
        }
        $type === 'attachment' ? self::require_cap('upload_files') : self::require_cap(get_post_type_object($type)->cap->edit_posts);
        $query = new WP_Query([
            'post_type' => $type, 'post_status' => $type === 'attachment' ? 'inherit' : ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 20, 'paged' => min(1000, max(1, (int) ($args['page'] ?? 1))),
            's' => isset($args['search']) ? sanitize_text_field(self::value($args, 'search', 300)) : '',
        ]);
        $items = [];
        foreach ($query->posts as $post) {
            if (!current_user_can('edit_post', $post->ID)) {
                continue;
            }
            $items[] = [
                'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status,
                'url' => $type === 'attachment' ? wp_get_attachment_url($post->ID) : get_permalink($post),
            ];
        }
        return ['items' => $items, 'pages' => $query->max_num_pages];
    }

    private static function get_content(int $id): array {
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, ['page', 'post', 'attachment'], true)) {
            throw new RuntimeException('Content not found.', 404);
        }
        self::require_cap('edit_post', $id);
        $content = [
            'id' => $id, 'type' => $post->post_type, 'status' => $post->post_status,
            'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt,
            'alt' => $post->post_type === 'attachment' ? (string) get_post_meta($id, '_wp_attachment_image_alt', true) : null,
            'modified' => $post->post_modified_gmt,
            'url' => $post->post_type === 'attachment' ? wp_get_attachment_url($id) : get_permalink($post),
        ];
        $content['revision'] = hash('sha256', wp_json_encode($content));
        return $content;
    }

    private static function save_content(array $args): array {
        $id = array_key_exists('id', $args) ? self::id($args) : 0;
        $before = $id ? self::get_content($id) : null;
        $type = $before ? $before['type'] : ($args['type'] ?? 'page');
        if (!in_array($type, ['page', 'post', 'attachment'], true)) {
            throw new RuntimeException('New content must be a post or page. Use import_media for files.', 400);
        }
        if ($before) {
            self::unchanged(self::value($args, 'expected_revision', 64), $before['revision']);
        } else {
            self::require_cap(get_post_type_object($type)->cap->create_posts);
        }
        $fields = [];
        foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt'] as $input => $field) {
            if (array_key_exists($input, $args)) {
                $value = self::value($args, $input);
                $fields[$field] = $input === 'title' ? sanitize_text_field($value) : wp_kses_post($value);
            }
        }
        if (array_key_exists('status', $args)) {
            $status = self::value($args, 'status', 10);
            if ($type === 'attachment' || !in_array($status, ['draft', 'publish'], true)) {
                throw new RuntimeException('Choose draft or publish for a post or page.', 400);
            }
            if ($status === 'publish') {
                self::require_cap(get_post_type_object($type)->cap->publish_posts);
            }
            $fields['post_status'] = $status;
        }
        $alt = array_key_exists('alt', $args) ? sanitize_text_field(self::value($args, 'alt', 1000)) : null;
        if ($alt !== null && $type !== 'attachment') {
            throw new RuntimeException('Alt text applies to attachments.', 400);
        }
        if (!$id && trim($fields['post_title'] ?? '') === '') {
            throw new RuntimeException('A title is required for new content.', 400);
        }
        if (!$fields && $alt === null) {
            throw new RuntimeException('No content changes were provided.', 400);
        }
        if ($id && $fields) {
            self::ensure(wp_update_post(wp_slash(['ID' => $id] + $fields), true));
        } elseif (!$id) {
            $id = self::ensure(wp_insert_post(wp_slash($fields + ['post_type' => $type, 'post_status' => 'draft']), true));
        }
        if ($alt !== null) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
        }
        return ['before' => $before, 'after' => self::get_content($id)];
    }

    private static function css(string $op, array $args): array {
        self::require_cap('edit_theme_options');
        $theme = get_stylesheet();
        $old = wp_get_custom_css($theme);
        $revision = hash('sha256', $theme . "\0" . $old);
        if ($op === 'get_css') {
            return ['theme' => $theme, 'css' => $old, 'revision' => $revision];
        }
        self::unchanged(self::value($args, 'expected_revision', 64), $revision);
        $css = self::value($args, 'css');
        if (str_contains($css, '<')) {
            throw new RuntimeException('CSS cannot contain markup.', 400);
        }
        self::ensure(wp_update_custom_css_post($css, ['stylesheet' => $theme]));
        return ['theme' => $theme, 'previous_css' => $old, 'revision' => hash('sha256', $theme . "\0" . wp_get_custom_css($theme))];
    }

    private static function files(string $op, array $args): array {
        $kind = self::value($args, 'kind', 10);
        $component = self::value($args, 'component', 250);
        $extensions = ['php', 'css', 'js', 'mjs', 'json', 'html'];
        if ($kind === 'theme') {
            self::require_cap('edit_themes');
            $theme = wp_get_themes()[$component] ?? null;
            if (!$theme || !$theme->exists()) {
                throw new RuntimeException('Theme not found.', 404);
            }
            $base = $theme->get_stylesheet_directory();
            $files = [];
            foreach ($extensions as $ext) {
                $files = array_merge($files, array_keys($theme->get_files($ext, -1)));
            }
        } elseif ($kind === 'plugin') {
            self::require_cap('edit_plugins');
            if (!array_key_exists($component, get_plugins()) || $component === plugin_basename(__FILE__)) {
                throw new RuntimeException('Plugin is unavailable for file editing.', 404);
            }
            $directory = dirname($component);
            $base = WP_PLUGIN_DIR . ($directory === '.' ? '' : '/' . $directory);
            $files = get_plugin_files($component);
        } else {
            throw new RuntimeException('Choose theme or plugin.', 400);
        }
        $files = array_values(array_filter($files, static function ($file) use ($extensions) {
            return in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true);
        }));
        sort($files);
        if ($op === 'list_files') {
            $offset = max(0, (int) ($args['offset'] ?? 0));
            return ['files' => array_slice($files, $offset, 150), 'total' => count($files)];
        }

        $file = self::value($args, 'file', 500);
        if (!in_array($file, $files, true)) {
            throw new RuntimeException('File is outside the component or unsupported.', 404);
        }
        $path = realpath(($kind === 'plugin' ? WP_PLUGIN_DIR : $base) . '/' . $file);
        $root = realpath($base);
        if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path) > 262144) {
            throw new RuntimeException('File is outside the component or exceeds 256 KiB.', 422);
        }
        $old = file_get_contents($path);
        if ($old === false || !preg_match('//u', $old)) {
            throw new RuntimeException('Could not read the file as UTF-8.', 422);
        }
        $digest = hash('sha256', $old);
        if ($op === 'read_file') {
            return ['file' => $file, 'content' => $old, 'revision' => $digest];
        }
        self::unchanged(self::value($args, 'expected_revision', 64), $digest);
        $new = self::value($args, 'content');
        if (str_ends_with($file, '.php')) {
            try {
                token_get_all($new, TOKEN_PARSE);
            } catch (ParseError $error) {
                throw new RuntimeException($error->getMessage(), 422);
            }
        }
        $nonce = $kind === 'theme' ? 'edit-theme_' . $component . '_' . $file : 'edit-plugin_' . $file;
        self::ensure(wp_edit_theme_plugin_file([$kind => $component, 'file' => $file, 'newcontent' => $new, 'nonce' => wp_create_nonce($nonce)]));
        clearstatcache(true, $path);
        $written = hash_file('sha256', $path);
        if ($written !== hash('sha256', $new)) {
            wp_edit_theme_plugin_file([$kind => $component, 'file' => $file, 'newcontent' => $old, 'nonce' => wp_create_nonce($nonce)]);
            throw new RuntimeException('WordPress reported a partial write. Verify the file on disk.', 422);
        }
        return ['file' => $file, 'previous_content' => $old, 'revision' => $written];
    }

    private static function create_theme(array $args): array {
        self::require_cap('install_themes');
        $slug = self::value($args, 'slug', 64);
        $name = sanitize_text_field(self::value($args, 'name', 100));
        $css = self::value($args, 'css', 64000);
        if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) || $name === '' || str_contains($name, '*/') || str_contains($css, '<')) {
            throw new RuntimeException('Invalid theme slug, name or CSS.', 400);
        }

        $root = get_theme_root();
        $destination = $root . '/' . $slug;
        if (file_exists($destination) || is_link($destination) || wp_get_theme($slug)->exists()) {
            throw new RuntimeException('A theme with that slug already exists.', 409);
        }
        if (get_filesystem_method([], $root) !== 'direct' || !is_writable($root)) {
            throw new RuntimeException('Theme creation requires direct write access to the theme directory.', 422);
        }

        $stage = $root . '/.siterelay-' . wp_generate_uuid4();
        if (!mkdir($stage, 0755)) {
            throw new RuntimeException('Cannot create a temporary theme directory.', 422);
        }
        $theme_json = ['version' => 2, 'settings' => ['layout' => ['contentSize' => '760px', 'wideSize' => '1200px']], 'styles' => ['css' => $css]];
        $header = '<!-- wp:group {"tagName":"header","layout":{"type":"constrained"}} --><header class="wp-block-group"><!-- wp:site-title /--><!-- wp:navigation /--></header><!-- /wp:group -->';
        $page = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:post-title {"level":1} /--><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main><!-- /wp:group -->';
        $index = '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group"><!-- wp:query {"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /--><!-- /wp:post-template --><!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination --></div><!-- /wp:query --></main><!-- /wp:group -->';
        $files = [
            'style.css' => "/*\nTheme Name: {$name}\nVersion: 1.0.0\nRequires at least: 6.5\n*/\n",
            'theme.json' => wp_json_encode($theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'templates/index.html' => $header . $index,
            'templates/page.html' => $header . $page,
            'templates/single.html' => $header . $page,
        ];
        try {
            if (!mkdir($stage . '/templates', 0755)) {
                throw new RuntimeException('Cannot create the templates directory.', 422);
            }
            foreach ($files as $file => $content) {
                if (!is_string($content) || file_put_contents($stage . '/' . $file, $content) !== strlen($content)) {
                    throw new RuntimeException('Could not write the new theme.', 422);
                }
            }
            if (file_exists($destination) || is_link($destination) || !rename($stage, $destination)) {
                throw new RuntimeException('Could not install the new theme directory.', 422);
            }
        } finally {
            if (is_dir($stage)) {
                foreach (array_keys($files) as $file) {
                    if (is_file($stage . '/' . $file)) {
                        unlink($stage . '/' . $file);
                    }
                }
                if (is_dir($stage . '/templates')) {
                    rmdir($stage . '/templates');
                }
                rmdir($stage);
            }
        }
        wp_clean_themes_cache();
        return ['theme' => $slug, 'active' => false, 'themes_screen' => admin_url('themes.php')];
    }

    private static function switch_theme(array $args): array {
        self::require_cap('switch_themes');
        $before = get_stylesheet();
        self::unchanged(self::value($args, 'expected_active', 250), $before);
        $slug = self::value($args, 'slug', 250);
        $theme = wp_get_themes()[$slug] ?? null;
        if (!$theme || !$theme->exists() || !$theme->is_allowed() || $theme->errors()) {
            throw new RuntimeException('Theme is unavailable or invalid.', 422);
        }
        switch_theme($slug);
        if (get_stylesheet() !== $slug) {
            throw new RuntimeException('WordPress did not activate the requested theme.', 422);
        }
        return ['previous_theme' => $before, 'theme' => $slug];
    }

    private static function import_media(array $args): array {
        self::require_cap('upload_files');
        $url = self::value($args, 'url', 2000);
        $name = sanitize_file_name(self::value($args, 'filename', 200));
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || !wp_http_validate_url($url) || !wp_check_filetype($name)['type']) {
            throw new RuntimeException('Use a public HTTPS URL and a filename with an allowed extension.', 400);
        }
        $limit = min(10 * MB_IN_BYTES, wp_max_upload_size());
        if ($limit < 1) {
            throw new RuntimeException('Media uploads are disabled on this site.', 422);
        }
        $title = isset($args['title']) ? sanitize_text_field(self::value($args, 'title', 300)) : null;
        $alt = isset($args['alt']) ? sanitize_text_field(self::value($args, 'alt', 1000)) : null;
        $tmp = self::ensure(wp_tempnam($name));
        try {
            $response = self::ensure(wp_safe_remote_get($url, [
                'timeout' => 25, 'redirection' => 0, 'stream' => true, 'filename' => $tmp,
                'limit_response_size' => $limit + 1, 'decompress' => false,
            ]));
            clearstatcache(true, $tmp);
            $size = filesize($tmp);
            if (wp_remote_retrieve_response_code($response) !== 200 || !$size || $size > $limit) {
                throw new RuntimeException('Download failed or exceeded the upload limit. Use a direct file URL.', 422);
            }
            $id = self::ensure(media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, null, $title === null ? [] : ['post_title' => $title]));
            if ($alt !== null) {
                update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
            }
            return self::get_content($id);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private static function updates(string $op, array $args): array {
        if ($op === 'list_updates') {
            wp_version_check();
            wp_update_plugins();
            wp_update_themes();
            $items = [];
            foreach (['plugin', 'theme'] as $kind) {
                $transient = get_site_transient('update_' . $kind . 's');
                foreach ((array) ($transient->response ?? []) as $id => $offer) {
                    $items[] = ['kind' => $kind, 'id' => $id, 'version' => ((array) $offer)['new_version']];
                }
            }
            foreach (get_core_updates(['dismissed' => false]) ?: [] as $offer) {
                if ($offer->response === 'upgrade') {
                    $items[] = ['kind' => 'core', 'id' => 'wordpress', 'version' => $offer->current];
                    break;
                }
            }
            return ['updates' => $items, 'updates_screen' => network_admin_url('update-core.php')];
        }

        $kind = self::value($args, 'kind', 10);
        $id = self::value($args, 'id', 250);
        $version = self::value($args, 'version', 50);
        $available = self::updates('list_updates', [])['updates'];
        if (!in_array(['kind' => $kind, 'id' => $id, 'version' => $version], $available, true)) {
            throw new RuntimeException('The requested update no longer matches the WordPress update inventory.', 409);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $skin = new Automatic_Upgrader_Skin();
        ob_start();
        try {
            switch ($kind) {
                case 'plugin':
                    self::require_cap('update_plugins');
                    if ($id === plugin_basename(__FILE__)) {
                        throw new RuntimeException('Update SiteRelay itself through WordPress.', 422);
                    }
                    $result = (new Plugin_Upgrader($skin))->upgrade($id);
                    break;
                case 'theme':
                    self::require_cap('update_themes');
                    $result = (new Theme_Upgrader($skin))->upgrade($id);
                    break;
                case 'core':
                    self::require_cap('update_core');
                    $offer = null;
                    foreach (get_core_updates(['dismissed' => false]) ?: [] as $candidate) {
                        if ($candidate->response === 'upgrade' && $candidate->current === $version) {
                            $offer = $candidate;
                            break;
                        }
                    }
                    if (!$offer) {
                        throw new RuntimeException('The WordPress update offer changed.', 409);
                    }
                    $result = (new Core_Upgrader($skin))->upgrade($offer);
                    break;
                default:
                    throw new RuntimeException('Unknown update type.', 400);
            }
            self::ensure($result);
        } finally {
            ob_end_clean();
        }
        return ['updated' => $id, 'version' => $version];
    }
}

SiteRelay::boot();

register_uninstall_hook(__FILE__, 'siterelay_uninstall');
function siterelay_uninstall(): void {
    if (!is_multisite()) {
        delete_option('siterelay_connection');
        return;
    }
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog((int) $site_id);
        delete_option('siterelay_connection');
        restore_current_blog();
    }
}
