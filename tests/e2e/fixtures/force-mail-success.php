<?php
/** Keep live browser submission tests local to the WordPress runtime. */
add_filter(
    'pre_wp_mail',
    function () {
        return true;
    }
);

add_filter(
    'eforms_config',
    function ( $config ) {
        $config['uploads']['enable'] = true;
        $config['throttle']['enable'] = true;
        $config['throttle']['per_ip']['max_per_minute'] = 1000;
        return $config;
    }
);
