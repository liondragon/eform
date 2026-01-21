<?php
/**
 * Plugin Name: eForms
 * Description: Electronic forms plugin (scaffold).
 * Version: 0.1.0
 * Author: eForms Team
 */

require_once __DIR__ . '/src/Compat.php';

// Educational note: if the host platform is incompatible, fail closed by not
// registering public surfaces, and (in wp-admin) show a notice + deactivate.
if ( ! Compat::guard( __FILE__ ) ) {
    return;
}

require_once __DIR__ . '/src/bootstrap.php';

eforms_bootstrap();
