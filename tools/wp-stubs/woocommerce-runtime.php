<?php
declare(strict_types=1);

/*
 * `WC()`, ON PURPOSE IN A FILE OF ITS OWN — and `load.php` does not load it.
 *
 * THIS IS NOT WOOCOMMERCE. Nothing measured through it may be reported as a
 * WooCommerce result.
 *
 * It is separate because `function_exists('WC')` is how real code asks
 * «is WooCommerce here?» — `WcOrderAdminUrl::editUrl()` asks exactly that
 * before it will build an order link, and answers `null` when the answer is
 * no, because a guessed `post.php` link on an HPOS site sends a manager to
 * «Invalid post ID» while looking like it works (`alpha.100`).
 *
 * So a stub that defined `WC()` at bootstrap would make «WooCommerce is
 * absent» impossible to express in any suite, and the test that measures it
 * would start reporting a link where there must be none. That happened on the
 * first run of this round, and it is the `alpha.42` rule in its own words: a
 * double you create can break a test that is about the absence of that very
 * thing.
 *
 * A test that needs a basket therefore requires THIS file and takes on what
 * requiring it means: from that point on, in that process, WooCommerce is
 * present as far as every `function_exists('WC')` is concerned.
 */

require_once __DIR__ . '/woocommerce-cart.php';

if (!function_exists('WC')) {
    function WC(): \TmcWpStubs\FakeWooCommerce
    {
        return \TmcWpStubs\State::$woocommerce ??= new \TmcWpStubs\FakeWooCommerce();
    }
}
