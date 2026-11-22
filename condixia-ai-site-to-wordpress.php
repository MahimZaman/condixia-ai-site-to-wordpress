<?php

/**
 * Plugin Name: Condixia AI Site-to-WordPress
 * Description: Import AI-generated HTML, CSS, and JavaScript websites and convert them into maintainable WordPress-managed sites.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Author: Mahim Zaman
 * Author URI: https://mahimzaman.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: condixia-ai-site-to-wordpress
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('CONDIXIA_AI_STW_VERSION', '1.0.0');
define('CONDIXIA_AI_STW_MIN_WP_VERSION', '7.0');
define('CONDIXIA_AI_STW_MIN_PHP_VERSION', '8.2');
define('CONDIXIA_AI_STW_FILE', __FILE__);
define('CONDIXIA_AI_STW_BASENAME', plugin_basename(__FILE__));
define('CONDIXIA_AI_STW_PATH', plugin_dir_path(__FILE__));
define('CONDIXIA_AI_STW_URL', plugin_dir_url(__FILE__));
define('CONDIXIA_AI_STW_SRC_PATH', CONDIXIA_AI_STW_PATH . 'src' . DIRECTORY_SEPARATOR);

require_once CONDIXIA_AI_STW_SRC_PATH . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';

\Condixia\AISiteToWordPress\Core\Autoloader::register(
    CONDIXIA_AI_STW_SRC_PATH
);

register_activation_hook(
    CONDIXIA_AI_STW_FILE,
    [\Condixia\AISiteToWordPress\Core\Activator::class, 'activate']
);

register_deactivation_hook(
    CONDIXIA_AI_STW_FILE,
    [\Condixia\AISiteToWordPress\Core\Deactivator::class, 'deactivate']
);

add_action(
    'plugins_loaded',
    [\Condixia\AISiteToWordPress\Core\Bootstrap::class, 'boot'],
    20
);
