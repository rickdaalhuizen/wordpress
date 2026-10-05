<?php

/**
 * Flux Core entry point: boots the plugin.
 *
 * @package Flux_Core
 *
 * @wordpress-plugin
 * Plugin Name:       Flux Core
 * Description:       Flux Core Backend.
 * Version:           0.0.1
 * Author:            3D Config
 * Author URI:        https://3dconfig.com/
 * Update URI:        flux-core
 * Text Domain:       flux-core
 * Requires PHP:      8.4
 * Requires at least: 6.5
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

(new Flux\Core\Plugin())->register();
