<?php
/**
 * Plugin Name: Woo Quick ADD
 * Plugin URI:  https://uaserver.pp.ua/woo-quick-add/
 * Description: Голосове додавання товарів у WooCommerce. Голос, фото, атрибути, мітки, варіативні, CSV.
 * Version:     1.8.0
 * Author:      UAServer
 * Author URI:  https://uaserver.pp.ua/
 * License:     GPL v2 or later
 * Text Domain: woo-quick-add
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.5
 */

if (!defined('ABSPATH')) exit;

define('WQA_VERSION',    '1.8.0');
define('WQA_FREE_LIMIT', 25);
define('WQA_FILE',       __FILE__);
define('WQA_GITHUB',     'portallcomua/woo-quick-add');

// WooCommerce HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WQA_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WQA_FILE, true);
    }
});

// =====================================================================
// АВТООНОВЛЕННЯ
// =====================================================================
class WQA_Updater {
    private $file, $slug, $repo;
    public function __construct($file, $repo) {
        $this->file = $file;
        $this->slug = dirname(plugin_basename($file));
        $this->repo = $repo;
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
        add_filter('plugins_api', [$this, 'info'], 10, 3);
    }
    public function check($t) {
        if (empty($t->checked)) return $t;
        $r = $this->remote();
        if ($r && version_compare(WQA_VERSION, $r->version, '<')) {
            $t->response[plugin_basename($this->file)] = (object)[
                'slug' => $this->slug, 'new_version' => $r->version,
                'package' => $r->zip, 'url' => $r->url, 'tested' => '6.8',
            ];
        }
        return $t;
    }
    public function info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->slug) return $res;
        $r = $this->remote();
        if (!$r) return $res;
        return (object)['name' => 'Woo Quick ADD', 'slug' => $this->slug,
            'version' => $r->version, 'download_link' => $r->zip,
            'tested' => '6.8', 'requires_php' => '7.4',
            'sections' => ['description' => 'Голосове додавання товарів у WooCommerce.']];
    }
    private function remote() {
        $resp = wp_remote_get("https://api.github.com/repos/{$this->repo}/releases/latest",
            ['headers' => ['Accept' => 'application/json'], 'timeout' => 5]);
        if (is_wp_error($resp)) return null;
        $d = json_decode(wp_remote_retrieve_body($resp));
        if (!$d || !isset($d->tag_name)) return null;
        return (object)['version' => ltrim($d->tag_name, 'v'), 'zip' => $d->zipball_url, 'url' => $d->html_url];
    }
}
new WQA_Updater(WQA_FILE, WQA_GITHUB);

// =====================================================================
// ХЕЛПЕРИ
// =====================================================================
function wqa_en()       { return in_array(get_locale(), ['en_US','en_GB','en_CA','en_AU','en']); }
function wqa_t($ua,$en) { return wqa_en() ? $en : $ua; }
function wqa_count()    { return (int) get_option('wqa_ops', 0); }
function wqa_inc()      { update_option('wqa_ops', wqa_count() + 1); }
function wqa_limit()    { return WQA_FREE_LIMIT; }
function wqa_left()     { return max(0, wqa_limit() - wqa_count()); }
function wqa_can()      { return wqa_pro() || wqa_count() < wqa_limit(); }
function wqa_sysinfo()  {
    global $wp_version;
    return "WP:{$wp_version} WC:".(class_exists('WooCommerce') ? WC()->version : 'n/a')
          ." PHP:".PHP_VERSION." Plugin:".WQA_VERSION
          ." Lic:".(wqa_pro() ? 'PRO' : 'Free '.wqa_count().'/'.wqa_limit())
          ." ".home_url();
}

// =====================================================================
// МОНЕТИЗАЦІЯ ТА ДОСТУП
// =====================================================================
function wqa_pro() {
    if ( function_exists( 'wcc_check_user_premium' ) ) {
        return (bool) wcc_check_user_premium( get_current_user_id(), 3 );
    }
    return false;
}

function wqa_pro_url() {
    $url = get_option('wqa_pro_url', '');
    return $url ? $url : 'https://uaserver.pp.ua/woo-quick-add/';
}

function wqa_user_can_use() {
    if ( ! is_user_logged_in() ) return false;
    $user = wp_get_current_user();
    if ( in_array( 'administrator', (array) $user->roles ) ) return true;
    
    $allowed = get_option('wqa_allowed_roles', ['administrator']);
    foreach ( $user->roles as $role ) {
        if ( in_array( $role, (array) $allowed ) ) return true;
    }
    return false;
}

// =====================================================================
// АКТИВАЦІЯ
// =====================================================================
register_activation_hook(__FILE__, function () {
    add_option('wqa_ops', 0);
    add_option('wqa_pro_url', '');
    add_option('wqa_rbg_api', '');
    add_option('wqa_draft', '1'); // За замовчуванням чернетка
    add_option('wqa_allowed_roles', ['administrator']);
    add_option('wqa_instructions', ''); // Порожньо = стандартна інструкція
    
    // Створюємо роль "Менеджер магазину", якщо її немає
    if ( ! get_role( 'shop_manager_custom' ) ) {
        add_role( 'shop_manager_custom', 'Менеджер магазину', [
            'read' => true,
            'edit_products' => true,
            'publish_products' => true,
            'edit_published_products' => true,
            'upload_files' => true,
        ]);
    }
});

// =====================================================================
// МЕНЮ
// =====================================================================
add_action('admin_menu', function () {
    add_menu_page('Woo Quick ADD', 'Woo Quick ADD', 'manage_woocommerce', 'wqa_main', 'wqa_page_main', 'dashicons-microphone', 30);
    add_submenu_page('wqa_main', wqa_t('Налаштування','Settings'), '⚙️ '.wqa_t('Налаштування','Settings'), 'manage_woocommerce', 'wqa_settings', 'wqa_page_settings');
});

// =====================================================================
// ШОРТКОД ДЛЯ ФРОНТЕНДУ
// =====================================================================
add_shortcode('wqa_frontend', function () {
    if ( ! wqa_user_can_use() ) return '<p>' . wqa_t('Доступ заборонено.', 'Access denied.') . '</p>';
    ob_start();
    wqa_render_form();
    return ob_get_clean();
});

// =====================================================================
// НАЛАШТУВАННЯ
// =====================================================================
function wqa_page_settings() {
    if (isset($_POST['wqa_save']) && check_admin_referer('wqa_cfg')) {
        update_option('wqa_rbg_api', sanitize_text_field($_POST['rbg_api']));
        update_option('wqa_pro_url', esc_url_raw($_POST['pro_url']));
        update_option('wqa_draft', isset($_POST['wqa_draft']) ? '1' : '0');
        update_option('wqa_allowed_roles', (array) $_POST['wqa_roles'] ?? []);
        update_option('wqa_instructions', wp_kses_post($_POST['wqa_instructions']));
        echo '<div class="notice notice-success"><p>✅ '.wqa_t('Збережено!', 'Saved!').'</p></div>';
    }
    $rbg_key = get_option('wqa_rbg_api', '');
    $pro_url = get_option('wqa_pro_url', '');
    $draft = get_option('wqa_draft', '1');
    $roles = get_option('wqa_allowed_roles', ['administrator']);
    $instr = get_option('wqa_instructions', '');
    ?>
    <div class="wrap">
    <h1>⚙️ <?php echo wqa_t('Налаштування Woo Quick ADD', 'Woo Quick ADD Settings') ?></h1>
    <form method="post"><?php wp_nonce_field('wqa_cfg') ?>

    <h2>🖼️ Remove.bg</h2>
    <table class="form-table">
        <tr><th>Remove.bg API Key</th>
            <td><input type="text" name="rbg_api" value="<?php echo esc_attr($rbg_key) ?>" style="width:340px" placeholder="tSZ2c5cR...">
            <p class="description"><?php echo wqa_t('Отримайте ключ на remove.bg/api (50 фото/міс безкоштовно).', 'Get key at remove.bg/api (50 photos/month free).') ?></p></td></tr>
    </table>

    <h2>💳 <?php echo wqa_t('Оплата PRO', 'PRO payment') ?></h2>
    <table class="form-table">
        <tr><th><?php echo wqa_t('URL сторінки оплати', 'Payment page URL') ?></th>
            <td><input type="url" name="pro_url" value="<?php echo esc_attr($pro_url) ?>" style="width:420px" placeholder="https://send.monobank.ua/...">
            <p class="description"><?php echo wqa_t('Кнопка «Перейти на PRO» веде сюди.', 'The "Go PRO" button leads here.') ?></p></td></tr>
    </table>

    <h2>⚙️ <?php echo wqa_t('Загальні', 'General') ?></h2>
    <table class="form-table">
        <tr><th><?php echo wqa_t('Статус за замовчуванням', 'Default status') ?></th>
            <td><label><input type="checkbox" name="wqa_draft" value="1" <?php checked($draft, '1') ?>> <?php echo wqa_t('Зберігати як чернетку', 'Save as draft') ?></label>
            <p class="description"><?php echo wqa_t('Якщо зняти — товар одразу публікується.', 'If unchecked — product is published immediately.') ?></p></td></tr>
        <tr><th><?php echo wqa_t('Доступ до фронтенду', 'Frontend access') ?></th>
            <td>
                <?php foreach (get_editable_roles() as $role_name => $role_info) : ?>
                    <label style="display:block;margin-bottom:4px">
                        <input type="checkbox" name="wqa_roles[]" value="<?php echo esc_attr($role_name) ?>" <?php checked(in_array($role_name, (array)$roles)) ?>>
                        <?php echo esc_html($role_info['name']) ?>
                    </label>
                <?php endforeach ?>
                <p class="description"><?php echo wqa_t('Хто може бачити форму на фронтенді (шорткод [wqa_frontend]).', 'Who can see the form on frontend (shortcode [wqa_frontend]).') ?></p>
            </td></tr>
        <tr><th><?php echo wqa_t('Текст інструкції', 'Instruction text') ?></th>
            <td><textarea name="wqa_instructions" rows="5" style="width:100%"><?php echo esc_textarea($instr) ?></textarea>
            <p class="description"><?php echo wqa_t('Якщо порожньо — показується стандартна інструкція. Можна вставити шорткод.', 'If empty — default instruction is shown. You can paste a shortcode.') ?></p></td></tr>
    </table>

    <input type="hidden" name="wqa_save" value="1">
    <?php submit_button(wqa_t('Зберегти', 'Save')) ?>
    </form>
    </div>
    <?php
}

// =====================================================================
// РЕНДЕР ФОРМИ (для адмінки та фронтенду)
// =====================================================================
function wqa_page_main() {
    if ( ! wqa_user_can_use() ) {
        wp_die( wqa_t('Доступ заборонено.', 'Access denied.') );
    }
    wqa_render_form();
}

function wqa_render_form() {
    $en      = wqa_en();
    $pro     = wqa_pro();
    $has_rbg = !empty(get_option('wqa_rbg_api', ''));
    $can     = wqa_can();
    $draft   = get_option('wqa_draft', '1') === '1';

    $fields = $en
        ? ['NAME','BRAND','PRICE','SALE','SKU','WEIGHT','CATEGORY','TAGS','DESCRIPTION']
        : ['НАЗВА','БРЕНД','ЦІНА','АКЦІЯ','АРТИКУЛ','ВАГА','КАТЕГОРІЯ','МІТКИ','ОПИС'];

    $template = implode("\n", array_map(fn($f) => $f.': ', $fields));

    $vm = $en ? [
        'name' => ['field' => 'NAME', 'type' => 'simple'],
        'brand' => ['field' => 'BRAND', 'type' => 'simple'],
        'price' => ['field' => 'PRICE', 'type' => 'simple'],
        'sale' => ['field' => 'SALE', 'type' => 'simple'],
        'sku' => ['field' => 'SKU', 'type' => 'simple'],
        'weight' => ['field' => 'WEIGHT', 'type' => 'simple'],
        'category' => ['field' => 'CATEGORY', 'type' => 'simple'],
        'description' => ['field' => 'DESCRIPTION', 'type' => 'simple'],
        'tag' => ['field' => 'TAGS', 'type' => 'append'],
        'tags' => ['field' => 'TAGS', 'type' => 'append'],
        'attribute' => ['field' => null, 'type' => 'attr'],
        'variation' => ['field' => null, 'type' => 'variation'],
    ] : [
        'назва' => ['field' => 'НАЗВА', 'type' => 'simple'],
        'бренд' => ['field' => 'БРЕНД', 'type' => 'simple'],
        'ціна' => ['field' => 'ЦІНА', 'type' => 'simple'],
        'акція' => ['field' => 'АКЦІЯ', 'type' => 'simple'],
        'артикул' => ['field' => 'АРТИКУЛ', 'type' => 'simple'],
        'вага' => ['field' => 'ВАГА', 'type' => 'simple'],
        'категорія' => ['field' => 'КАТЕГОРІЯ', 'type' => 'simple'],
        'опис' => ['field' => 'ОПИС', 'type' => 'simple'],
        'мітка' => ['field' => 'МІТКИ', 'type' => 'append'],
        'мітки' => ['field' => 'МІТКИ', 'type' => 'append'],
        'атрибут' => ['field' => null, 'type' => 'attr'],
        'варіація' => ['field' => null, 'type' => 'variation'],
    ];

    $js_config = [
        'EN' => $en, 'PRO' => $pro, 'HAS_RBG' => $has_rbg, 'CAN' => $can, 'DRAFT' => $draft,
        'AJAX' => admin_url('admin-ajax.php'),
        'NS' => wp_create_nonce('wqa_save'),
        'NE' => wp_create_nonce('wqa_export'),
        'TPL' => $template, 'VM' => $vm,
        'L' => [
            'photo' => wqa_t('✅ Фото ', '✅ Photo '),
            'saving' => wqa_t('⏳ ЗБЕРІГАЮ…', '⏳ SAVING…'),
            'saved' => wqa_t('✅ Збережено! ID: ', '✅ Saved! ID: '),
            'publish' => wqa_t('➕ ОПУБЛІКУВАТИ', '➕ PUBLISH'),
            'draft' => wqa_t('➕ ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ', '➕ SAVE AS DRAFT'),
            'limit' => wqa_t('❌ Ліміт вичерпано!', '❌ Limit reached!'),
            'stop' => wqa_t('🛑 ЗУПИНИТИ', '🛑 STOP'),
            'dictate' => wqa_t('🎤 ДИКТУВАТИ', '🎤 VOICE INPUT'),
            'mic_ready' => wqa_t('🎤 Маяк: назва, ціна, бренд…', '🎤 Beacon: name, price, brand…'),
            'mic_blocked' => wqa_t('❌ Мікрофон заблоковано!', '❌ Mic blocked!'),
            'val_prompt' => wqa_t(' → значення…', ' → value…'),
            'tag_prompt' => wqa_t('⏳ Мітки → говоріть…', '⏳ Tags → say…'),
            'attr_name' => wqa_t('⏳ Назва атрибуту…', '⏳ Attribute name…'),
            'attr_val' => wqa_t('⏳ Значення атрибуту…', '⏳ Attribute values…'),
            'var_name' => wqa_t('⏳ Назва варіації…', '⏳ Variation name…'),
            'hint' => wqa_t('💡 назва / ціна / бренд / атрибут колір значення червоний…', '💡 name / price / brand / attribute color value red…'),
            'attr_ph_name' => wqa_t('Назва (Колір)', 'Attribute (Color)'),
            'attr_ph_val' => wqa_t('Через кому (Червоний, Синій)', 'Comma sep. (Red, Blue)'),
            'var_ph_name' => wqa_t('Назва варіації (Розмір)', 'Variation name (Size)'),
            'var_ph_val' => wqa_t('Значення (S)', 'Value (S)'),
            'var_ph_price' => wqa_t('Ціна', 'Price'),
            'var_add_val' => wqa_t('+ Значення', '+ Value'),
            'not_supported' => wqa_t('❌ НЕ ПІДТРИМУЄТЬСЯ', '❌ NOT SUPPORTED'),
        ],
    ];
    ?>
    <style>
    @keyframes wqa_pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
    #wqa_w * { box-sizing:border-box }
    .wqa-frontend-wrap { max-width:800px; margin:20px auto; padding:20px; background:#fff; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.1); }
    </style>

    <div id="wqa_w" class="<?php echo is_admin() ? '' : 'wqa-frontend-wrap' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <h1 style="margin:0">🎤 Woo Quick ADD <sup style="font-size:11px;color:#aaa;font-weight:400">v<?php echo WQA_VERSION ?></sup></h1>
        <?php if (is_admin()) : ?>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="<?php echo admin_url('admin.php?page=wqa_settings') ?>" class="button">⚙️</a>
            <button id="btn_export" style="background:#0d6efd;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:13px;cursor:pointer">📤 CSV</button>
        </div>
        <?php endif ?>
    </div>

    <?php if (!$pro) : ?>
    <div style="background:#fff3cd;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px">
        📊 <?php echo wqa_t('Залишилось:', 'Left:') ?> <strong><?php echo wqa_left() ?></strong>/<?php echo wqa_limit() ?>
        &nbsp; <a href="<?php echo esc_url( wqa_pro_url() ) ?>" target="_blank" style="color:#c00;font-weight:bold">💰 <?php echo wqa_t('Перейти на PRO (499 грн)', 'Go PRO (499 UAH)') ?> →</a>
    </div>
    <?php endif ?>

    <video id="wqa_cam" autoplay playsinline muted style="width:100%;background:#111;border-radius:10px;margin-bottom:6px;max-height:280px;object-fit:cover"></video>
    <div id="wqa_gal" style="display:flex;gap:4px;flex-wrap:wrap;min-height:2px;margin-bottom:8px"></div>

    <?php if ($has_rbg) : ?>
    <div id="wqa_bg_wrap" style="background:#f8f9fa;border-radius:10px;padding:10px 14px;margin-bottom:10px;display:none">
        <div style="font-size:13px;font-weight:bold;margin-bottom:8px">🖼️ <?php echo wqa_t('Фон:', 'Background:') ?></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <label><input type="radio" name="wqa_bg" value="original" checked> <?php echo wqa_t('Оригінал', 'Original') ?></label>
            <label><input type="radio" name="wqa_bg" value="transparent"> <?php echo wqa_t('Прозорий', 'Transparent') ?></label>
            <label><input type="radio" name="wqa_bg" value="ffffff"> <?php echo wqa_t('Білий', 'White') ?></label>
            <label><input type="radio" name="wqa_bg" value="000000"> <?php echo wqa_t('Чорний', 'Black') ?></label>
            <label><input type="radio" name="wqa_bg" value="custom"> <?php echo wqa_t('Колір:', 'Color:') ?> <input type="color" id="wqa_cc" value="#ff0000" style="width:32px;height:22px;padding:0;border:1px solid #ccc;cursor:pointer"></label>
        </div>
    </div>
    <?php endif ?>

    <div style="display:flex;gap:8px;margin-bottom:12px">
        <button id="btn_photo" style="flex:3;background:#2271b1;color:#fff;border:none;padding:12px;border-radius:8px;font-size:15px;font-weight:bold;cursor:pointer">📷 <?php echo wqa_t('ФОТО', 'PHOTO') ?></button>
        <button id="btn_clr" style="flex:1;background:#6c757d;color:#fff;border:none;padding:12px;border-radius:8px;font-size:14px;cursor:pointer">❌</button>
    </div>

    <div id="wqa_bar" style="display:none;padding:12px 16px;border-radius:9px;font-size:15px;font-weight:bold;text-align:center;color:#fff;margin-bottom:8px"></div>

    <label style="font-weight:bold;display:block;margin-bottom:5px">📝 <?php echo wqa_t('ДАНІ ТОВАРУ', 'PRODUCT DATA') ?></label>
    <textarea id="wqa_ta" rows="<?php echo count($fields) ?>" style="width:100%;font-family:monospace;font-size:14px;padding:10px 12px;border:2px solid #2271b1;border-radius:10px;line-height:1.8;resize:vertical"><?php echo esc_textarea($template) ?></textarea>

    <div style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <label style="font-weight:bold">🏷️ <?php echo wqa_t('АТРИБУТИ', 'ATTRIBUTES') ?></label>
            <button id="btn_add_attr" style="background:#6f42c1;color:#fff;border:none;padding:7px 13px;border-radius:6px;font-size:13px;cursor:pointer">+ <?php echo wqa_t('Додати', 'Add') ?></button>
        </div>
        <p style="margin:0 0 6px;font-size:12px;color:#777"><?php echo wqa_t('Голос: «атрибут» → назва («колір») → «значення» → значення («червоний білий чорний»).', 'Voice: "attribute" → name ("color") → "value" → values ("red white black").') ?></p>
        <div id="wqa_attrs"></div>
    </div>

    <div style="display:flex;gap:8px;margin-top:14px">
        <button id="btn_mic" style="flex:2;background:#1877F2;color:#fff;border:none;padding:14px;border-radius:10px;font-size:16px;font-weight:bold;cursor:pointer">🎤 <?php echo wqa_t('ДИКТУВАТИ', 'VOICE INPUT') ?></button>
    </div>

    <?php if ($pro) : ?>
    <div style="margin-top:12px;padding:14px;background:#f8f0ff;border-radius:10px;border:2px solid #6f42c1">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <label style="font-weight:bold"><?php echo wqa_t('Тип:', 'Type:') ?></label>
            <select id="wqa_ptype" style="padding:7px 12px;border-radius:7px;border:2px solid #6f42c1;font-size:14px">
                <option value="simple"><?php echo wqa_t('Простий', 'Simple') ?></option>
                <option value="variable"><?php echo wqa_t('Варіативний', 'Variable') ?></option>
            </select>
        </div>
        <div id="wqa_vwrap" style="display:none">
            <label style="font-weight:bold;display:block;margin-bottom:5px"><?php echo wqa_t('Варіації:', 'Variations:') ?></label>
            <div id="wqa_var_rows"></div>
            <button id="btn_add_var" style="background:#6f42c1;color:#fff;border:none;padding:7px 13px;border-radius:6px;font-size:13px;cursor:pointer;margin-top:6px">+ <?php echo wqa_t('Додати варіацію', 'Add variation') ?></button>
        </div>
    </div>
    <?php endif ?>

    <button id="btn_save" style="width:100%;background:#00a32a;color:#fff;border:none;padding:16px;border-radius:10px;font-size:18px;font-weight:bold;cursor:pointer;margin-top:14px">
        <?php echo $draft ? wqa_t('➕ ЗБЕРЕГТИ ЯК ЧЕРНЕТКУ', '➕ SAVE AS DRAFT') : wqa_t('➕ ОПУБЛІКУВАТИ', '➕ PUBLISH') ?>
    </button>

    <div id="wqa_msg" style="display:none;margin-top:12px;padding:12px 14px;border-radius:8px;font-size:14px"></div>

    <?php
    $instr = get_option('wqa_instructions', '');
    if ( $instr ) {
        echo '<div style="margin-top:18px;background:#f8f9fa;border-radius:10px;padding:12px 16px;font-size:13px;line-height:1.9">' . do_shortcode( wp_kses_post( $instr ) ) . '</div>';
    } else {
    ?>
    <details style="margin-top:18px;background:#f8f9fa;border-radius:10px;padding:12px 16px">
    <summary style="cursor:pointer;font-weight:bold;font-size:14px">📋 <?php echo wqa_t('Як диктувати', 'How to dictate') ?></summary>
    <div style="margin-top:10px;font-size:13px;line-height:1.9">
        <?php if (!$en) : ?>
        <b>Правило:</b> 🎤 → маяк → пауза → значення.<br><br>
        <table style="border-collapse:collapse;width:100%;font-size:12px">
        <tr style="background:#dee2e6"><th style="padding:4px 8px;text-align:left">Маяк</th><th style="padding:4px 8px;text-align:left">Приклад</th><th style="padding:4px 8px;text-align:left">Результат</th></tr>
        <?php foreach ([
            ['«назва»','«назва Штани»','НАЗВА: Штани'],
            ['«ціна»','«ціна 850»','ЦІНА: 850'],
            ['«бренд»','«бренд Nike»','БРЕНД: Nike'],
            ['«категорія»','«категорія Одяг»','КАТЕГОРІЯ: Одяг'],
            ['«мітка»','«мітка новинка»','МІТКИ: новинка'],
            ['«атрибут»','«атрибут» → «колір» → «значення» → «червоний білий»','Атрибут Колір: червоний, білий'],
            ['«варіація»','«варіація» → «розмір» → «S M L»','Варіація Розмір: S, M, L'],
        ] as $r) : ?>
        <tr style="border-bottom:1px solid #eee">
            <td style="padding:4px 8px;font-family:monospace"><?php echo $r[0] ?></td>
            <td style="padding:4px 8px;color:#0d6efd"><?php echo $r[1] ?></td>
            <td style="padding:4px 8px;color:#198754"><?php echo $r[2] ?></td>
        </tr>
        <?php endforeach ?>
        </table>
        <?php endif ?>
    </div>
    </details>
    <?php } ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
    var C = <?php echo json_encode($js_config, JSON_UNESCAPED_UNICODE) ?>;

    var photos = [], cam = null, listening = false;
    var vs = 'idle', vf = null, va = null, vattrName = null;

    function msg(text, type) {
        var d = document.getElementById('wqa_msg');
        d.innerHTML = text;
        var bg = type === 'err' ? '#f8d7da' : type === 'ok' ? '#d1e7dd' : '#cfe2ff';
        var cl = type === 'err' ? '#58151c' : type === 'ok' ? '#0a3622' : '#052c65';
        d.style.cssText = 'display:block;padding:12px 14px;border-radius:8px;background:' + bg + ';color:' + cl + ';margin-top:12px';
        clearTimeout(d._t);
        d._t = setTimeout(function () { d.style.display = 'none'; }, 5000);
    }

    function bar(text, bg) {
        var b = document.getElementById('wqa_bar');
        b.textContent = text;
        b.style.cssText = 'display:block;padding:12px;border-radius:9px;font-size:15px;font-weight:bold;text-align:center;color:#fff;margin-bottom:8px;background:' + bg;
    }
    function barOff() { document.getElementById('wqa_bar').style.display = 'none'; }
    function barReady() { if (listening) bar(C.L.mic_ready, '#1877F2'); }

    function taSet(field, value) {
        var ta = document.getElementById('wqa_ta');
        var found = false;
        var out = ta.value.split('\n').map(function (l) {
            if (l.toLowerCase().indexOf(field.toLowerCase() + ':') === 0) { found = true; return field + ': ' + value; }
            return l;
        });
        if (!found) out.push(field + ': ' + value);
        ta.value = out.join('\n');
    }
    function taAppend(field, value) {
        var ta = document.getElementById('wqa_ta');
        var found = false;
        var out = ta.value.split('\n').map(function (l) {
            if (l.toLowerCase().indexOf(field.toLowerCase() + ':') === 0) {
                found = true;
                var cur = l.substring(l.indexOf(':') + 1).trim();
                return field + ': ' + (cur ? cur + ', ' + value : value);
            }
            return l;
        });
        if (!found) out.push(field + ': ' + value);
        ta.value = out.join('\n');
    }

    function attrUpsert(name, value) {
        var rows = document.querySelectorAll('.wqa_ar');
        for (var i = 0; i < rows.length; i++) {
            var inputs = rows[i].querySelectorAll('input');
            if (inputs[0].value.toLowerCase() === name.toLowerCase()) {
                var cur = inputs[1].value.trim();
                inputs[1].value = cur ? cur + ', ' + value : value;
                return;
            }
        }
        attrAdd(name, value);
    }
    function attrAdd(name, value) {
        var list = document.getElementById('wqa_attrs');
        var row = document.createElement('div');
        row.className = 'wqa_ar';
        row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:5px';
        var i0 = document.createElement('input');
        i0.type = 'text'; i0.value = name || ''; i0.placeholder = C.L.attr_ph_name;
        i0.style.cssText = 'flex:1;padding:7px 9px;border:1px solid #ccc;border-radius:6px;font-size:14px';
        var i1 = document.createElement('input');
        i1.type = 'text'; i1.value = value || ''; i1.placeholder = C.L.attr_ph_val;
        i1.style.cssText = 'flex:2;padding:7px 9px;border:1px solid #ccc;border-radius:6px;font-size:14px';
        var del = document.createElement('button');
        del.textContent = '✕';
        del.style.cssText = 'background:#dc3545;color:#fff;border:none;padding:7px 10px;border-radius:6px;cursor:pointer';
        del.addEventListener('click', function () { row.remove(); });
        row.appendChild(i0); row.appendChild(i1); row.appendChild(del);
        list.appendChild(row);
    }
    document.getElementById('btn_add_attr').addEventListener('click', function () { attrAdd('', ''); });

    if (C.PRO) {
        document.getElementById('wqa_ptype').addEventListener('change', function () {
            document.getElementById('wqa_vwrap').style.display = this.value === 'variable' ? 'block' : 'none';
        });
        document.getElementById('btn_add_var').addEventListener('click', function () { varAddRow('', [], []); });
        function varAddRow(name, vals, prices) {
            var rows = document.getElementById('wqa_var_rows');
            var row = document.createElement('div');
            row.className = 'wqa_vr';
            row.style.cssText = 'background:#fff;border:1px solid #d4b0ff;border-radius:8px;padding:10px;margin-bottom:8px';
            var head = document.createElement('div');
            head.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:8px';
            var nameInp = document.createElement('input');
            nameInp.type = 'text'; nameInp.value = name || ''; nameInp.placeholder = C.L.var_ph_name;
            nameInp.style.cssText = 'flex:1;padding:7px 9px;border:1.5px solid #6f42c1;border-radius:6px;font-size:14px;font-weight:bold';
            var del = document.createElement('button');
            del.textContent = '✕';
            del.style.cssText = 'background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:6px;cursor:pointer';
            del.addEventListener('click', function () { row.remove(); });
            head.appendChild(nameInp); head.appendChild(del);
            row.appendChild(head);
            var valList = document.createElement('div');
            valList.className = 'wqa_vvals';
            row.appendChild(valList);
            function addValRow(v, p) {
                var vrow = document.createElement('div');
                vrow.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:5px';
                var vi = document.createElement('input');
                vi.type = 'text'; vi.value = v || ''; vi.placeholder = C.L.var_ph_val;
                vi.style.cssText = 'flex:2;padding:6px 9px;border:1px solid #ccc;border-radius:6px;font-size:13px';
                var pi = document.createElement('input');
                pi.type = 'number'; pi.value = p || ''; pi.placeholder = C.L.var_ph_price; pi.min = '0'; pi.step = '0.01';
                pi.style.cssText = 'flex:1;padding:6px 9px;border:1px solid #ccc;border-radius:6px;font-size:13px';
                var vdel = document.createElement('button');
                vdel.textContent = '✕';
                vdel.style.cssText = 'background:#6c757d;color:#fff;border:none;padding:5px 9px;border-radius:6px;cursor:pointer;font-size:12px';
                vdel.addEventListener('click', function () { vrow.remove(); });
                vrow.appendChild(vi); vrow.appendChild(pi); vrow.appendChild(vdel);
                valList.appendChild(vrow);
            }
            var vArr = vals || [], pArr = prices || [];
            if (vArr.length) { for (var i = 0; i < vArr.length; i++) addValRow(vArr[i], pArr[i] || ''); }
            else addValRow('', '');
            var addValBtn = document.createElement('button');
            addValBtn.textContent = C.L.var_add_val;
            addValBtn.style.cssText = 'background:#6f42c1;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:12px;cursor:pointer;margin-top:2px';
            addValBtn.addEventListener('click', function () { addValRow('', ''); });
            row.appendChild(addValBtn);
            rows.appendChild(row);
        }
        window.collectVariations = function () {
            var result = [];
            var rows = document.querySelectorAll('.wqa_vr');
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var name = row.querySelector('input').value.trim();
                if (!name) continue;
                var vals = [], prices = [];
                var vrows = row.querySelectorAll('.wqa_vvals > div');
                for (var j = 0; j < vrows.length; j++) {
                    var inp = vrows[j].querySelectorAll('input');
                    var v = inp[0].value.trim(), p = inp[1].value.trim();
                    if (v) { vals.push(v); prices.push(p); }
                }
                if (vals.length) result.push({ name: name, values: vals, prices: prices });
            }
            return result;
        };
    }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        .then(function (stream) { cam = stream; document.getElementById('wqa_cam').srcObject = stream; })
        .catch(function (e) { console.log(e); });

    document.getElementById('btn_photo').addEventListener('click', function () {
        var v = document.getElementById('wqa_cam');
        if (!v.videoWidth) return;
        var c = document.createElement('canvas');
        c.width = v.videoWidth; c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        var d = c.toDataURL('image/jpeg', 0.82);
        photos.push(d);
        var img = document.createElement('img');
        img.src = d;
        img.style.cssText = 'width:66px;height:66px;object-fit:cover;border-radius:7px;border:2px solid #2271b1;cursor:pointer';
        img.addEventListener('click', (function (idx) {
            return function () { photos.splice(idx, 1); img.remove(); };
        })(photos.length - 1));
        document.getElementById('wqa_gal').prepend(img);
        var w = document.getElementById('wqa_bg_wrap');
        if (w) w.style.display = 'block';
    });

    document.getElementById('btn_clr').addEventListener('click', function () {
        photos = []; document.getElementById('wqa_gal').innerHTML = '';
        var w = document.getElementById('wqa_bg_wrap');
        if (w) w.style.display = 'none';
    });

    if (window.SpeechRecognition || window.webkitSpeechRecognition) {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        var rec = new SR();
        rec.lang = C.EN ? 'en-US' : 'uk-UA';
        rec.continuous = true; rec.interimResults = false;
        var mb = document.getElementById('btn_mic');

        mb.addEventListener('click', function () {
            if (!listening) {
                try {
                    rec.start(); listening = true; vs = 'idle'; vf = null; va = null; vattrName = null;
                    mb.style.cssText = 'flex:2;background:#dc3545;color:#fff;border:none;padding:14px;border-radius:10px;font-size:16px;font-weight:bold;cursor:pointer;animation:wqa_pulse 1s infinite';
                    mb.textContent = C.L.stop;
                    bar(C.L.mic_ready, '#1877F2');
                } catch (e) { msg('❌ ' + e.message, 'err'); }
            } else stopVoice();
        });

        function stopVoice() {
            listening = false; vs = 'idle'; vf = null; va = null; vattrName = null;
            try { rec.stop(); } catch(e) {}
            mb.style.cssText = 'flex:2;background:#1877F2;color:#fff;border:none;padding:14px;border-radius:10px;font-size:16px;font-weight:bold;cursor:pointer';
            mb.textContent = C.L.dictate;
            barOff();
        }

        rec.onresult = function (e) {
            var spoken = '';
            var res = e.results[e.results.length - 1];
            for (var i = 0; i < res.length; i++) { spoken = res[i].transcript.trim(); if (spoken) break; }
            var low = spoken.toLowerCase().replace(/[.,!?;]$/g, '').trim();

            if (vs === 's_attr_name') {
                vattrName = spoken;
                vs = 's_attr_val';
                bar('🏷️ ' + spoken + ' → ' + C.L.attr_val, '#fd7e14');
                return;
            }
            if (vs === 's_attr_val' && vattrName) {
                var vals = spoken.split(/[\s,]+/).filter(Boolean);
                vals.forEach(function (v) { attrUpsert(vattrName, v); });
                bar('✅ ' + vattrName + ': ' + vals.join(', '), '#198754');
                vs = 'idle'; vattrName = null;
                setTimeout(barReady, 2200);
                return;
            }
            if (vs === 's_val' && vf) { taSet(vf, spoken); bar('✅ ' + vf + ': ' + spoken, '#198754'); vs = 'idle'; vf = null; setTimeout(barReady, 2200); return; }
            if (vs === 's_tag' && vf) { spoken.split(/[\s,]+/).filter(Boolean).forEach(function (v) { taAppend(vf, v); }); bar('✅ ' + (C.EN ? 'Tags: ' : 'Мітки: ') + spoken, '#198754'); vs = 'idle'; vf = null; setTimeout(barReady, 2200); return; }
            if (vs === 's_vn') { va = spoken; vs = 's_vv'; bar('⏳ ' + spoken + C.L.val_prompt, '#fd7e14'); return; }
            if (vs === 's_vv' && va) {
                var vals2 = spoken.split(/[\s,]+/).filter(Boolean);
                if (C.PRO && typeof varAddRow === 'function') { varAddRow(va, vals2, []); document.getElementById('wqa_ptype').value = 'variable'; document.getElementById('wqa_vwrap').style.display = 'block'; }
                bar('✅ ' + va + ': ' + vals2.join(', '), '#198754'); vs = 'idle'; va = null; setTimeout(barReady, 2200); return;
            }

            var sorted = Object.keys(C.VM).sort(function (a, b) { return b.length - a.length; });
            var found = null, inline = null;
            for (var i = 0; i < sorted.length; i++) {
                var trig = sorted[i];
                if (low === trig) { found = C.VM[trig]; inline = null; break; }
                if (low.indexOf(trig + ' ') === 0) { found = C.VM[trig]; inline = spoken.substring(trig.length).trim(); break; }
            }

            if (!found) { bar(C.L.hint, '#6c757d'); setTimeout(barReady, 3500); return; }

            if (found.type === 'simple') {
                if (inline) { taSet(found.field, inline); bar('✅ ' + found.field + ': ' + inline, '#198754'); setTimeout(barReady, 2200); }
                else { vf = found.field; vs = 's_val'; bar('⏳ ' + found.field + C.L.val_prompt, '#fd7e14'); }
            } else if (found.type === 'append') {
                if (inline) { inline.split(/[\s,]+/).filter(Boolean).forEach(function (v) { taAppend(found.field, v); }); bar('✅ ' + (C.EN ? 'Tags: ' : 'Мітки: ') + inline, '#198754'); setTimeout(barReady, 2200); }
                else { vf = found.field; vs = 's_tag'; bar(C.L.tag_prompt, '#fd7e14'); }
            } else if (found.type === 'attr') {
                if (inline) {
                    vattrName = inline;
                    vs = 's_attr_val';
                    bar('🏷️ ' + inline + ' → ' + C.L.attr_val, '#fd7e14');
                } else {
                    vs = 's_attr_name';
                    bar(C.L.attr_name, '#fd7e14');
                }
            } else if (found.type === 'variation') {
                if (!inline) { vs = 's_vn'; bar(C.L.var_name, '#fd7e14'); }
                else {
                    var parts = inline.split(/\s+/);
                    if (parts.length === 1) { va = inline; vs = 's_vv'; bar('⏳ ' + inline + C.L.val_prompt, '#fd7e14'); }
                    else {
                        if (C.PRO) { varAddRow(parts[0], parts.slice(1), []); document.getElementById('wqa_ptype').value = 'variable'; document.getElementById('wqa_vwrap').style.display = 'block'; }
                        bar('✅ ' + parts[0] + ': ' + parts.slice(1).join(', '), '#198754'); setTimeout(barReady, 2200);
                    }
                }
            }
        };

        rec.onerror = function (e) {
            if (e.error === 'no-speech') return;
            if (e.error === 'not-allowed') { bar(C.L.mic_blocked, '#dc3545'); stopVoice(); return; }
        };
        rec.onend = function () { if (listening) setTimeout(function () { try { rec.start(); } catch (x) {} }, 120); };
    } else {
        var mb2 = document.getElementById('btn_mic');
        mb2.disabled = true; mb2.textContent = C.L.not_supported;
    }

    document.getElementById('btn_save').addEventListener('click', function () {
        var self = this;
        if (!C.CAN) { msg(C.L.limit, 'err'); return; }

        var attrs = [];
        var ar = document.querySelectorAll('.wqa_ar');
        for (var i = 0; i < ar.length; i++) {
            var inp = ar[i].querySelectorAll('input');
            var k = inp[0].value.trim(), v = inp[1].value.trim();
            if (k && v) attrs.push({ name: k, value: v });
        }

        var ptype = 'simple', variations = [];
        if (C.PRO) {
            var pt = document.getElementById('wqa_ptype');
            if (pt) ptype = pt.value;
            if (ptype === 'variable' && typeof collectVariations === 'function') variations = collectVariations();
        }

        self.disabled = true; self.textContent = C.L.saving;

        var fd = new FormData();
        fd.append('action', 'wqa_save'); fd.append('nonce', C.NS);
        fd.append('text', document.getElementById('wqa_ta').value);
        fd.append('images', JSON.stringify(photos));
        fd.append('attrs', JSON.stringify(attrs));
        fd.append('type', ptype);
        fd.append('variations', JSON.stringify(variations));

        fetch(C.AJAX, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    msg(C.L.saved + d.data.id, 'ok');
                    photos = []; document.getElementById('wqa_gal').innerHTML = '';
                    document.getElementById('wqa_attrs').innerHTML = '';
                    document.getElementById('wqa_ta').value = C.TPL;
                    var vr = document.getElementById('wqa_var_rows'); if (vr) vr.innerHTML = '';
                    self.disabled = false; self.textContent = C.DRAFT ? C.L.draft : C.L.publish;
                } else {
                    msg('❌ ' + (d.data || 'Error'), 'err');
                    self.disabled = false; self.textContent = C.DRAFT ? C.L.draft : C.L.publish;
                }
            });
    });

    var btnExport = document.getElementById('btn_export');
    if (btnExport) {
        btnExport.addEventListener('click', function () {
            var self = this;
            self.textContent = '⏳'; self.disabled = true;
            var fd = new FormData(); fd.append('action', 'wqa_export'); fd.append('nonce', C.NE);
            fetch(C.AJAX, { method: 'POST', body: fd })
                .then(function (r) { return r.blob(); })
                .then(function (b) {
                    var u = URL.createObjectURL(b);
                    var a = document.createElement('a'); a.href = u;
                    a.download = 'wc-products-' + new Date().toISOString().split('T')[0] + '.csv';
                    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(u);
                });
            self.textContent = '📤 CSV'; self.disabled = false;
        });
    }

    });
    </script>
    <?php
}

// =====================================================================
// AJAX: ЗБЕРЕЖЕННЯ
// =====================================================================
add_action('wp_ajax_wqa_save', function () {
    if (!check_ajax_referer('wqa_save', 'nonce', false)) wp_send_json_error('Nonce');
    if (!current_user_can('manage_woocommerce') && !current_user_can('edit_products')) wp_send_json_error('Permission');
    if (!wqa_can()) wp_send_json_error('Ліміт вичерпано');

    $raw   = sanitize_textarea_field($_POST['text']       ?? '');
    $imgs  = json_decode(stripslashes($_POST['images']    ?? '[]'), true) ?: [];
    $attrs = json_decode(stripslashes($_POST['attrs']     ?? '[]'), true) ?: [];
    $ptype = sanitize_text_field($_POST['type']           ?? 'simple');
    $vars  = json_decode(stripslashes($_POST['variations'] ?? '[]'), true) ?: [];
    $draft = get_option('wqa_draft', '1') === '1';

    $data = [];
    foreach (explode("\n", $raw) as $l) {
        if (strpos($l, ':') !== false) {
            [$k, $v] = array_map('trim', explode(':', $l, 2));
            if ($k && $v) $data[strtoupper($k)] = $v;
        }
    }
    $name  = $data['NAME']        ?? $data['НАЗВА']     ?? '';
    $price = preg_replace('/[^0-9.]/', '', $data['PRICE'] ?? $data['ЦІНА']  ?? '');
    $sale  = preg_replace('/[^0-9.]/', '', $data['SALE']  ?? $data['АКЦІЯ'] ?? '');
    $desc  = $data['DESCRIPTION'] ?? $data['ОПИС']      ?? '';
    $cat   = $data['CATEGORY']    ?? $data['КАТЕГОРІЯ'] ?? '';
    $brand = $data['BRAND']       ?? $data['БРЕНД']     ?? '';
    $tags  = $data['TAGS']        ?? $data['МІТКИ']     ?? '';

    $product = ($ptype === 'variable' && wqa_pro()) ? new WC_Product_Variable() : new WC_Product_Simple();
    $product->set_status($draft ? 'draft' : 'publish');
    $product->set_name($name ?: wqa_t('Товар', 'Product').' '.date('H:i:s'));
    if ($price) $product->set_regular_price($price);
    if ($sale)  $product->set_sale_price($sale);
    if ($desc)  $product->set_description($desc);
    $pid = $product->save();
    if (!$pid) wp_send_json_error('Could not save');

    if ($cat) {
        $t = term_exists($cat, 'product_cat') ?: wp_insert_term($cat, 'product_cat');
        if (!is_wp_error($t)) wp_set_object_terms($pid, (int)$t['term_id'], 'product_cat');
    }
    if ($brand) {
        foreach (['pwb-brand','product_brand','pa_brand'] as $tax) {
            if (taxonomy_exists($tax)) {
                $t = term_exists($brand, $tax) ?: wp_insert_term($brand, $tax);
                if (!is_wp_error($t)) { wp_set_object_terms($pid, (int)$t['term_id'], $tax); break; }
            }
        }
    }
    if ($tags) {
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $tags))) as $tn) {
            $t = term_exists($tn, 'product_tag') ?: wp_insert_term($tn, 'product_tag');
            if (!is_wp_error($t)) $ids[] = (int)$t['term_id'];
        }
        if ($ids) wp_set_object_terms($pid, $ids, 'product_tag');
    }

    $wca = [];
    foreach ($attrs as $a) {
        if (empty($a['name']) || empty($a['value'])) continue;
        $attr_name = wc_clean($a['name']);
        $taxonomy  = wc_attribute_taxonomy_name($attr_name);
        if (!taxonomy_exists($taxonomy)) {
            $attr_id = wc_create_attribute(['name' => $attr_name, 'slug' => sanitize_title($attr_name), 'type' => 'select', 'order_by' => 'menu_order']);
            if (!is_wp_error($attr_id)) { delete_transient('wc_attribute_taxonomies'); register_taxonomy($taxonomy, ['product'], []); }
        }
        $values = array_filter(array_map('trim', explode(',', $a['value'])));
        foreach ($values as $val) { if (!term_exists($val, $taxonomy)) wp_insert_term($val, $taxonomy); }
        $attr = new WC_Product_Attribute();
        $attr->set_id(wc_attribute_taxonomy_id_by_name($attr_name));
        $attr->set_name($taxonomy);
        $attr->set_options($values);
        $attr->set_visible(true);
        $wca[] = $attr;
    }

    if ($wca) { $product->set_attributes($wca); $product->save(); }

    require_once ABSPATH.'wp-admin/includes/image.php';
    foreach ($imgs as $i => $b64) {
        $img = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $b64));
        $file = wp_upload_bits('wqa_'.$pid.'_'.time().'_'.$i.'.jpg', null, $img);
        if ($file['error']) continue;
        $aid = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $file['file'], $pid);
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $file['file']));
        if ($i === 0) set_post_thumbnail($pid, $aid);
    }

    wqa_inc();
    wp_send_json_success(['id' => $pid]);
});

// =====================================================================
// AJAX: ЕКСПОРТ CSV (СУМІСНИЙ З WOOCOMMERCE)
// =====================================================================
add_action('wp_ajax_wqa_export', function () {
    if (!check_ajax_referer('wqa_export', 'nonce', false)) wp_die('Nonce');
    if (!current_user_can('manage_woocommerce')) wp_die('Permission');

    $products = wc_get_products(['limit' => -1, 'status' => 'any', 'return' => 'objects']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wc-import-'.date('Y-m-d').'.csv"');
    
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для Excel

    // Стандартні заголовки WooCommerce
    $headers = [
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
        'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
        'Tax status', 'Tax class', 'In stock?', 'Stock', 'Low stock amount', 'Backorders allowed?',
        'Sold individually?', 'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)',
        'Allow customer reviews?', 'Purchase note', 'Sale price', 'Regular price',
        'Categories', 'Tags', 'Shipping class', 'Images', 'Download limit', 'Download expiry',
        'Parent', 'Grouped products', 'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
        'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 1 visible', 'Attribute 1 global',
        'Attribute 2 name', 'Attribute 2 value(s)', 'Attribute 2 visible', 'Attribute 2 global',
        'Attribute 3 name', 'Attribute 3 value(s)', 'Attribute 3 visible', 'Attribute 3 global'
    ];
    fputcsv($out, $headers);

    foreach ($products as $p) {
        $pid = $p->get_id();
        $cats = implode(', ', wp_get_object_terms($pid, 'product_cat', ['fields' => 'names']));
        $tgs  = implode(', ', wp_get_object_terms($pid, 'product_tag', ['fields' => 'names']));
        
        $imgs = [];
        if ($p->get_image_id()) $imgs[] = wp_get_attachment_url($p->get_image_id());
        foreach ($p->get_gallery_image_ids() as $gi) if ($gi) $imgs[] = wp_get_attachment_url($gi);

        $as = array_values($p->get_attributes());
        $attr_cols = [];
        for ($i = 0; $i < 3; $i++) {
            if (isset($as[$i])) {
                $attr_cols[] = $as[$i]->get_name();
                $attr_cols[] = implode(', ', $as[$i]->get_options());
                $attr_cols[] = $as[$i]->get_visible() ? 1 : 0;
                $attr_cols[] = $as[$i]->get_id() ? 1 : 0;
            } else {
                $attr_cols[] = ''; $attr_cols[] = ''; $attr_cols[] = 1; $attr_cols[] = 0;
            }
        }

        $row = [
            $pid, $p->get_type(), $p->get_sku(), $p->get_name(), $p->get_status() === 'publish' ? 1 : 0,
            $p->is_featured() ? 1 : 0, 'visible', $p->get_short_description(), $p->get_description(),
            '', '', $p->get_tax_status(), $p->get_tax_class(), $p->is_in_stock() ? 1 : 0,
            $p->get_stock_quantity() ?? '', $p->get_low_stock_amount() ?? '', $p->get_backorders(),
            $p->get_sold_individually() ? 1 : 0, $p->get_weight(), $p->get_length(), $p->get_width(), $p->get_height(),
            $p->get_reviews_allowed() ? 1 : 0, $p->get_purchase_note(), $p->get_sale_price(), $p->get_regular_price(),
            $cats, $tgs, $p->get_shipping_class(), implode(', ', $imgs), $p->get_download_limit(), $p->get_download_expiry(),
            $p->get_parent_id(), '', implode(', ', $p->get_upsell_ids()), implode(', ', $p->get_cross_sell_ids()),
            $p->get_product_url(), $p->get_button_text(), '',
        ];
        $row = array_merge($row, $attr_cols);
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
});
