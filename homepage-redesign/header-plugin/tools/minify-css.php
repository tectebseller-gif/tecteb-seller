<?php
// ابزار آزمون (نه بخشی از بسته). نیازمند: composer require matthiasmullie/minify:1.3.75 در یک پوشهٔ موقت.
//   php tools/minify-css.php <dir-with-vendor> tecteb-header/assets/css <wp>/wp-content/uploads/tthh-min
// WP Rocket «Minify CSS» از کتابخانهٔ matthiasmullie/minify استفاده می‌کند؛ همان را روی CSS ِ هدر اجرا می‌کنیم.
[$_, $lib, $src, $dst] = $argv;
require $lib . '/vendor/autoload.php';
@mkdir( $dst, 0777, true );
foreach ( array( 'header-base.css', 'header-b.css', 'tecteb-header.css' ) as $f ) {
	$m = new MatthiasMullie\Minify\CSS( file_get_contents( "$src/$f" ) );
	file_put_contents( "$dst/$f", $m->minify() );
	printf( "%s %d -> %d bytes\n", $f, filesize( "$src/$f" ), filesize( "$dst/$f" ) );
}
