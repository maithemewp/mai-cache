<?php
// Some class files guard on ABSPATH, as WordPress code does. Defined here,
// before any of them load.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
