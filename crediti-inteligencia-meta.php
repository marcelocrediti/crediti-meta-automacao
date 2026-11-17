<?php
/**
 * Plugin Name: Crediti Inteligência Meta
 * Description: Integração privada da Crediti Inteligência com a Meta Marketing API. Não altera o aplicativo meucrediti.
 * Version: 1.0.0
 * Author: Crediti Soluções Financeiras
 */
if (!defined('ABSPATH')) { exit; }

const CREDITI_META_ACCOUNT_ID = 'act_1754387845224500';
const CREDITI_META_API_VERSION = 'v25.0';
const CREDITI_META_TRACKING_START = '2026-11-15';

function crediti_meta_encrypt_token($plain) {
    if ($plain === '') return '';
    $key = hash('sha256', wp_salt('auth'), true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';
    return base64_encode($iv . $cipher);
}

function crediti_meta_decrypt_token() {
    $stored = get_option('crediti_meta_token_enc', '');
    if (!$stored) return '';
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = hash('sha256', wp_salt('auth'), true);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function crediti_meta_admin_menu() {
    add_management_page(
        'Crediti Inteligência Meta',
        'Crediti Inteligência Meta',
        'manage_options',
        'crediti-inteligencia-meta',
        'crediti_meta_admin_page'
    );
}
add_action('admin_menu', 'crediti_meta_admin_menu');

function crediti_meta_admin_page() {
    if (!current_user_can('manage_options')) return;
    $configured = crediti_meta_decrypt_token() !== '';
    $saved = isset($_GET['crediti_saved']) && $_GET['crediti_saved'] === '1';
    $tested = isset($_GET['crediti_test']) ? sanitize_text_field(wp_unslash($_GET['crediti_test'])) : '';

    echo '<div class="wrap"><h1>Crediti Inteligência Meta</h1>';
    echo '<p>Integração exclusiva do painel Crediti Inteligência. Não altera o aplicativo meucrediti.</p>';
    if ($saved) echo '<div class="notice notice-success"><p>Token salvo com criptografia.</p></div>';
    if ($tested === 'ok') echo '<div class="notice notice-success"><p>Conexão com a Meta validada.</p></div>';
    if ($tested === 'fail') echo '<div class="notice notice-error"><p>Não foi possível validar a conexão. Verifique o token e as permissões da Marketing API.</p></div>';

    echo '<table class="widefat striped" style="max-width:760px;margin:18px 0"><tbody>';
    echo '<tr><td><strong>Conta Meta Ads</strong></td><td>1754387845224500</td></tr>';
    echo '<tr><td><strong>Início oficial</strong></td><td>15/11/2026</td></tr>';
    echo '<tr><td><strong>Token</strong></td><td>' . ($configured ? 'Configurado' : 'Ainda não configurado') . '</td></tr>';
    echo '<tr><td><strong>Modo</strong></td><td>Somente leitura de relatórios</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Guardar token da Meta</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px">';
    wp_nonce_field('crediti_meta_save_token');
    echo '<input type="hidden" name="action" value="crediti_meta_save_token">';
    echo '<p><input type="password" autocomplete="off" name="meta_token" class="regular-text" style="width:100%;max-width:760px" placeholder="Cole aqui o token novo da Meta" required></p>';
    echo '<p class="description">O token é criptografado antes de ser salvo e não volta a ser exibido.</p>';
    submit_button('Salvar token');
    echo '</form>';

    if ($configured) {
        echo '<h2>Testar conexão</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crediti_meta_test_token');
        echo '<input type="hidden" name="action" value="crediti_meta_test_token">';
        submit_button('Testar Meta API', 'secondary');
        echo '</form>';
    }
    echo '</div>';
}

function crediti_meta_save_token() {
    if (!current_user_can('manage_options')) wp_die('Sem permissão.');
    check_admin_referer('crediti_meta_save_token');
    $token = isset($_POST['meta_token']) ? trim(wp_unslash($_POST['meta_token'])) : '';
    if ($token === '' || strlen($token) < 40) wp_die('Token inválido.');
    $enc = crediti_meta_encrypt_token($token);
    if ($enc === '') wp_die('Falha ao proteger o token.');
    update_option('crediti_meta_token_enc', $enc, false);
    wp_safe_redirect(add_query_arg(['page'=>'crediti-inteligencia-meta','crediti_saved'=>'1'], admin_url('tools.php')));
    exit;
}
add_action('admin_post_crediti_meta_save_token', 'crediti_meta_save_token');

function crediti_meta_graph_get($path, $params = []) {
    $token = crediti_meta_decrypt_token();
    if ($token === '') return new WP_Error('crediti_meta_not_configured', 'Token da Meta não configurado.');
    $params['access_token'] = $token;
    $url = 'https://graph.facebook.com/' . CREDITI_META_API_VERSION . '/' . ltrim($path, '/');
    $url = add_query_arg($params, $url);
    $resp = wp_remote_get($url, ['timeout'=>25, 'redirection'=>3]);
    if (is_wp_error($resp)) return $resp;
    $status = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $msg = is_array($body) && isset($body['error']['message']) ? $body['error']['message'] : 'Erro HTTP ' . $status;
        return new WP_Error('crediti_meta_graph_error', $msg, ['status'=>$status]);
    }
    return $body;
}

function crediti_meta_test_token() {
    if (!current_user_can('manage_options')) wp_die('Sem permissão.');
    check_admin_referer('crediti_meta_test_token');
    $test = crediti_meta_graph_get(CREDITI_META_ACCOUNT_ID, [
        'fields' => 'id,name,currency,timezone_name,account_status'
    ]);
    update_option('crediti_meta_last_test_at', current_time('mysql'), false);
    if (is_wp_error($test)) {
        update_option('crediti_meta_last_error', $test->get_error_message(), false);
        $result = 'fail';
    } else {
        update_option('crediti_meta_last_error', '', false);
        update_option('crediti_meta_account_meta', [
            'name' => isset($test['name']) ? sanitize_text_field($test['name']) : '',
            'currency' => isset($test['currency']) ? sanitize_text_field($test['currency']) : '',
            'timezone_name' => isset($test['timezone_name']) ? sanitize_text_field($test['timezone_name']) : '',
            'account_status' => isset($test['account_status']) ? intval($test['account_status']) : 0,
        ], false);
        $result = 'ok';
    }
    wp_safe_redirect(add_query_arg(['page'=>'crediti-inteligencia-meta','crediti_test'=>$result], admin_url('tools.php')));
    exit;
}
add_action('admin_post_crediti_meta_test_token', 'crediti_meta_test_token');

function crediti_meta_valid_date($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function crediti_meta_default_range() {
    $tz = new DateTimeZone('America/Fortaleza');
    $to = new DateTime('now', $tz);
    $from = clone $to;
    $from->modify('-6 days');
    $start = new DateTime(CREDITI_META_TRACKING_START, $tz);
    if ($from < $start) $from = $start;
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

function crediti_meta_fetch_insights($from, $to) {
    $params = [
        'fields' => 'campaign_id,campaign_name,impressions,clicks,spend',
        'level' => 'campaign',
        'time_increment' => 1,
        'breakdowns' => 'publisher_platform',
        'time_range' => wp_json_encode(['since'=>$from, 'until'=>$to]),
        'limit' => 500,
    ];
    $first = crediti_meta_graph_get(CREDITI_META_ACCOUNT_ID . '/insights', $params);
    if (is_wp_error($first)) return $first;

    $all = [];
    $page = $first;
    $guard = 0;
    while (is_array($page) && $guard < 20) {
        $guard++;
        if (!empty($page['data']) && is_array($page['data'])) {
            foreach ($page['data'] as $row) $all[] = $row;
        }
        if (empty($page['paging']['next'])) break;
        $next = esc_url_raw($page['paging']['next']);
        if (strpos($next, 'https://graph.facebook.com/') !== 0) break;
        $resp = wp_remote_get($next, ['timeout'=>25, 'redirection'=>2]);
        if (is_wp_error($resp)) return $resp;
        $status = wp_remote_retrieve_response_code($resp);
        $page = json_decode(wp_remote_retrieve_body($resp), true);
        if ($status < 200 || $status >= 300 || !is_array($page)) break;
    }

    $out = [];
    foreach ($all as $row) {
        $platform = isset($row['publisher_platform']) ? strtolower((string)$row['publisher_platform']) : '';
        if ($platform === 'instagram') {
            $source = 'instagram_ads';
        } elseif ($platform === 'facebook') {
            $source = 'facebook_ads';
        } else {
            continue;
        }
        $date = isset($row['date_start']) ? (string)$row['date_start'] : '';
        if (!crediti_meta_valid_date($date)) continue;
        $spend = isset($row['spend']) ? (float)$row['spend'] : 0.0;
        $out[] = [
            'campaignId' => isset($row['campaign_id']) ? (string)$row['campaign_id'] : '',
            'campaignName' => isset($row['campaign_name']) ? (string)$row['campaign_name'] : '',
            'date' => $date,
            'source' => $source,
            'impressions' => isset($row['impressions']) ? intval($row['impressions']) : 0,
            'clicks' => isset($row['clicks']) ? intval($row['clicks']) : 0,
            'spendCents' => (int) round($spend * 100),
        ];
    }
    return $out;
}

function crediti_meta_register_routes() {
    register_rest_route('crediti-inteligencia/v1', '/meta-status', [
        'methods' => 'GET',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function(){
            $meta = get_option('crediti_meta_account_meta', []);
            return rest_ensure_response([
                'configured' => crediti_meta_decrypt_token() !== '',
                'accountId' => CREDITI_META_ACCOUNT_ID,
                'trackingStart' => CREDITI_META_TRACKING_START,
                'apiVersion' => CREDITI_META_API_VERSION,
                'account' => is_array($meta) ? $meta : [],
                'lastTestAt' => get_option('crediti_meta_last_test_at', ''),
                'lastError' => get_option('crediti_meta_last_error', ''),
            ]);
        }
    ]);

    register_rest_route('crediti-inteligencia/v1', '/meta-insights', [
        'methods' => 'GET',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function(WP_REST_Request $request){
            [$default_from, $default_to] = crediti_meta_default_range();
            $from = $request->get_param('from') ?: $default_from;
            $to = $request->get_param('to') ?: $default_to;
            if (!crediti_meta_valid_date($from) || !crediti_meta_valid_date($to)) {
                return new WP_Error('invalid_dates', 'Datas inválidas.', ['status'=>400]);
            }
            if ($from < CREDITI_META_TRACKING_START) $from = CREDITI_META_TRACKING_START;
            $d1 = new DateTime($from);
            $d2 = new DateTime($to);
            if ($d2 < $d1) return new WP_Error('invalid_range', 'Intervalo inválido.', ['status'=>400]);
            if ($d1->diff($d2)->days > 6) return new WP_Error('range_too_large', 'Use no máximo 7 dias por consulta.', ['status'=>400]);

            $rows = crediti_meta_fetch_insights($from, $to);
            if (is_wp_error($rows)) return $rows;
            $meta = get_option('crediti_meta_account_meta', []);
            return rest_ensure_response([
                'from' => $from,
                'to' => $to,
                'accountTimezone' => is_array($meta) && isset($meta['timezone_name']) ? $meta['timezone_name'] : null,
                'rows' => $rows,
                'rowCount' => count($rows),
            ]);
        }
    ]);
}
add_action('rest_api_init', 'crediti_meta_register_routes');
