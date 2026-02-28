<?php

if (!defined('ABSPATH')) {
    exit;
}

class Altek_Sync_Settings {
    const OPTION_KEY = 'altek_sync_pg_config';

    public static function defaults() {
        return [
            'host' => '',
            'port' => '5432',
            'database' => '',
            'user' => '',
            'password' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'batch_size' => '500',
            'cron_interval_minutes' => '5',
            'delete_mode' => 'soft',
        ];
    }

    public static function get() {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, self::defaults());
    }

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_altek_sync_bootstrap', [__CLASS__, 'handle_manual_bootstrap']);
        add_action('admin_post_altek_sync_run_worker', [__CLASS__, 'handle_manual_worker']);
    }

    public static function register_menu() {
        add_options_page(
            'Product Synchronizer',
            'Product Synchronizer',
            'manage_options',
            'altek-sync-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input) {
        $defaults = self::defaults();
        $out = wp_parse_args((array) $input, $defaults);

        $out['host'] = sanitize_text_field($out['host']);
        $out['port'] = (string) max(1, intval($out['port']));
        $out['database'] = sanitize_text_field($out['database']);
        $out['user'] = sanitize_text_field($out['user']);
        $out['password'] = (string) $out['password'];
        $out['schema'] = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $out['schema']) ?: 'public';
        $out['sslmode'] = in_array($out['sslmode'], ['disable', 'allow', 'prefer', 'require'], true)
            ? $out['sslmode']
            : 'prefer';
        $out['batch_size'] = (string) min(1000, max(50, intval($out['batch_size'])));
        $out['cron_interval_minutes'] = (string) min(60, max(1, intval($out['cron_interval_minutes'])));
        $out['delete_mode'] = $out['delete_mode'] === 'hard' ? 'hard' : 'soft';

        self::ensure_cron_rescheduled();

        return $out;
    }

    private static function ensure_cron_rescheduled() {
        $timestamp = wp_next_scheduled(ALTEK_SYNC_CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, ALTEK_SYNC_CRON_HOOK);
        }
        wp_schedule_event(time() + 60, 'altek_sync_custom', ALTEK_SYNC_CRON_HOOK);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $opts = self::get();
        $wpTotal = self::count_wordpress_products();
        $queueCounts = Altek_Sync_Queue::get_status_counts();
        $recentEvents = Altek_Sync_Queue::get_recent_events(20);
        $pgTotal = null;
        $pgError = '';
        $syncNotice = self::read_sync_notice();

        try {
            $repo = new Altek_Sync_Pg_Repository();
            $pgTotal = $repo->count_products();
        } catch (Throwable $e) {
            $pgError = $e->getMessage();
        }
        ?>
        <div class="wrap">
            <h1>Product Synchronizer</h1>
            <?php if (!empty($syncNotice)) : ?>
                <div class="notice notice-<?php echo esc_attr($syncNotice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($syncNotice['message']); ?></p>
                </div>
            <?php endif; ?>
            <div style="display:flex;gap:12px;margin:16px 0;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;min-width:260px;">
                    <div style="color:#50575e;font-size:12px;">Total productos WordPress</div>
                    <div style="font-size:24px;font-weight:600;line-height:1.2;"><?php echo esc_html(number_format_i18n($wpTotal)); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;min-width:260px;">
                    <div style="color:#50575e;font-size:12px;">Total productos Altek (Postgres)</div>
                    <div style="font-size:24px;font-weight:600;line-height:1.2;">
                        <?php echo $pgTotal === null ? 'N/A' : esc_html(number_format_i18n($pgTotal)); ?>
                    </div>
                    <?php if ($pgError !== '') : ?>
                        <p style="margin:8px 0 0;color:#b32d2e;font-size:12px;"><?php echo esc_html($pgError); ?></p>
                    <?php endif; ?>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;min-width:320px;">
                    <div style="color:#50575e;font-size:12px;">Estado de cola</div>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:8px;margin-top:6px;font-size:13px;">
                        <div>Pendientes: <strong><?php echo esc_html(number_format_i18n($queueCounts['pending'])); ?></strong></div>
                        <div>Reintento: <strong><?php echo esc_html(number_format_i18n($queueCounts['retry'])); ?></strong></div>
                        <div>Procesando: <strong><?php echo esc_html(number_format_i18n($queueCounts['processing'])); ?></strong></div>
                        <div>Fallidos: <strong><?php echo esc_html(number_format_i18n($queueCounts['failed'])); ?></strong></div>
                    </div>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 16px 0;">
                <h2 style="margin:0 0 10px 0;">Guia visual de sincronizacion</h2>
                <ol style="margin:0 0 12px 18px;">
                    <li>Comparar SKUs WooCommerce vs Altek.</li>
                    <li>Encolar solo productos faltantes en Postgres.</li>
                    <li>Procesar la cola para insertar/actualizar en Altek.</li>
                    <li>Revisar estado: pendientes, reintentos y fallidos.</li>
                </ol>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('altek_sync_admin_actions', '_altek_sync_nonce'); ?>
                        <input type="hidden" name="action" value="altek_sync_bootstrap" />
                        <button type="submit" class="button button-primary">1) Comparar y encolar faltantes</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('altek_sync_admin_actions', '_altek_sync_nonce'); ?>
                        <input type="hidden" name="action" value="altek_sync_run_worker" />
                        <button type="submit" class="button">2) Procesar cola ahora</button>
                    </form>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:0 0 16px 0;">
                <h2 style="margin:0 0 10px 0;">Ultimos 20 eventos</h2>
                <?php if (empty($recentEvents)) : ?>
                    <p style="margin:0;color:#50575e;">Aun no hay eventos en cola.</p>
                <?php else : ?>
                    <div style="overflow:auto;">
                        <table class="widefat striped" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>SKU</th>
                                    <th>Evento</th>
                                    <th>Estado</th>
                                    <th>Intentos</th>
                                    <th>Producto WP</th>
                                    <th>Actualizado</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $event) : ?>
                                    <?php
                                        $payload = json_decode((string) ($event['payload'] ?? ''), true);
                                        $sku = '';
                                        if (is_array($payload) && isset($payload['item'])) {
                                            $sku = (string) $payload['item'];
                                        }
                                        $error = trim((string) ($event['last_error'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html((string) intval($event['id'] ?? 0)); ?></td>
                                        <td><?php echo esc_html($sku !== '' ? $sku : '—'); ?></td>
                                        <td><?php echo esc_html((string) ($event['event_type'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($event['status'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) intval($event['attempts'] ?? 0)); ?></td>
                                        <td><?php echo esc_html((string) intval($event['product_id'] ?? 0)); ?></td>
                                        <td><?php echo esc_html((string) ($event['updated_at'] ?? '')); ?></td>
                                        <td style="max-width:360px;">
                                            <span style="display:inline-block;max-width:100%;white-space:normal;">
                                                <?php echo esc_html($error !== '' ? $error : '—'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Host</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[host]" value="<?php echo esc_attr($opts['host']); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Port</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[port]" value="<?php echo esc_attr($opts['port']); ?>" class="small-text" /></td></tr>
                    <tr><th scope="row">Database</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[database]" value="<?php echo esc_attr($opts['database']); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">User</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[user]" value="<?php echo esc_attr($opts['user']); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Password</th><td><input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[password]" value="<?php echo esc_attr($opts['password']); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Schema</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[schema]" value="<?php echo esc_attr($opts['schema']); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">SSL mode</th><td>
                        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[sslmode]">
                            <?php foreach (['disable', 'allow', 'prefer', 'require'] as $sslmode) : ?>
                                <option value="<?php echo esc_attr($sslmode); ?>" <?php selected($opts['sslmode'], $sslmode); ?>><?php echo esc_html($sslmode); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th scope="row">Batch size</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[batch_size]" value="<?php echo esc_attr($opts['batch_size']); ?>" class="small-text" /></td></tr>
                    <tr><th scope="row">Cron (min)</th><td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[cron_interval_minutes]" value="<?php echo esc_attr($opts['cron_interval_minutes']); ?>" class="small-text" /></td></tr>
                    <tr><th scope="row">Delete mode</th><td>
                        <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[delete_mode]">
                            <option value="soft" <?php selected($opts['delete_mode'], 'soft'); ?>>Soft delete (bloqueado=TRUE)</option>
                            <option value="hard" <?php selected($opts['delete_mode'], 'hard'); ?>>Hard delete</option>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function count_wordpress_products() {
        $counts = wp_count_posts('product');
        if (!$counts) {
            return 0;
        }

        $total = 0;
        foreach ((array) $counts as $status => $value) {
            if ($status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            $total += intval($value);
        }

        return $total;
    }

    public static function handle_manual_bootstrap() {
        self::assert_admin_action_permissions();

        try {
            $stats = Altek_Sync_Bootstrap::enqueue_missing_products(500);
            $message = sprintf(
                'Comparacion completada. WP: %d, PG existentes: %d, encolados faltantes: %d, omitidos por existir: %d, sin SKU: %d.',
                intval($stats['wp_total']),
                intval($stats['pg_existing']),
                intval($stats['enqueued_missing']),
                intval($stats['skipped_existing']),
                intval($stats['skipped_no_sku'])
            );
            self::store_sync_notice('success', $message);
        } catch (Throwable $e) {
            self::store_sync_notice('error', 'Error en comparacion/encolado: ' . $e->getMessage());
        }

        wp_safe_redirect(self::settings_url());
        exit;
    }

    public static function handle_manual_worker() {
        self::assert_admin_action_permissions();

        try {
            $worker = new Altek_Sync_Worker();
            $worker->run();
            $counts = Altek_Sync_Queue::get_status_counts();
            $message = sprintf(
                'Worker ejecutado. Pendientes: %d, Retry: %d, Fallidos: %d, Done: %d.',
                intval($counts['pending']),
                intval($counts['retry']),
                intval($counts['failed']),
                intval($counts['done'])
            );
            self::store_sync_notice('success', $message);
        } catch (Throwable $e) {
            self::store_sync_notice('error', 'Error ejecutando worker: ' . $e->getMessage());
        }

        wp_safe_redirect(self::settings_url());
        exit;
    }

    private static function assert_admin_action_permissions() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 'Forbidden', ['response' => 403]);
        }
        check_admin_referer('altek_sync_admin_actions', '_altek_sync_nonce');
    }

    private static function settings_url() {
        return admin_url('options-general.php?page=altek-sync-settings');
    }

    private static function store_sync_notice($type, $message) {
        set_transient('altek_sync_admin_notice', [
            'type' => $type === 'error' ? 'error' : 'success',
            'message' => (string) $message,
        ], 120);
    }

    private static function read_sync_notice() {
        $notice = get_transient('altek_sync_admin_notice');
        if ($notice) {
            delete_transient('altek_sync_admin_notice');
        }
        return is_array($notice) ? $notice : null;
    }
}
