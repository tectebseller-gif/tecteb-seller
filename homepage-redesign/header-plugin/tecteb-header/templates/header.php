<?php
/**
 * هدر B — همان ساختار نمونهٔ تأییدشده (header/shared/header.html)، با دادهٔ واقعی.
 * دسکتاپ و کشوی موبایل هر دو از $vm['cats'] و $vm['nav'] ساخته می‌شوند.
 *
 * @package Tecteb\Header
 *
 * @var array<string,mixed> $vm
 */

defined( 'ABSPATH' ) || exit;

$tth_icon = static function ( array $c, string $cls ): string {
	if ( '' !== $c['icon'] ) {
		return '<span class="' . esc_attr( $cls ) . '"><img src="' . esc_url( $c['icon'] ) . '" width="24" height="24" alt="" loading="lazy" decoding="async"></span>';
	}
	return '<span class="' . esc_attr( $cls . ' ' . $cls . '--none' ) . '"><svg class="tth-i" aria-hidden="true"><use href="#tth-i-grid"/></svg></span>';
};
$tth_nav_link = static function ( array $item ): string {
	$attrs = ' href="' . esc_url( $item['url'] ) . '"';
	if ( $item['current'] ) {
		$attrs .= ' aria-current="page"';
	}
	if ( '' !== $item['target'] ) {
		$attrs .= ' target="_blank"';
		$item['rel'] = trim( $item['rel'] . ' noopener' );
	}
	if ( '' !== $item['rel'] ) {
		$attrs .= ' rel="' . esc_attr( $item['rel'] ) . '"';
	}
	return '<a' . $attrs . '>' . esc_html( $item['title'] ) . '</a>';
};
$tth_acct    = $vm['account'];
$tth_wish    = $vm['wishlist'];
$tth_cats    = $vm['cats'];
$tth_has_cat = array() !== $tth_cats;
?>
<div class="<?php echo esc_attr( implode( ' ', $vm['classes'] ) ); ?>" data-tth-header data-user="<?php echo $tth_acct['logged_in'] ? 'member' : 'guest'; ?>" dir="rtl" lang="fa"<?php echo $vm['banner'] ? ' role="banner"' : ''; ?>>
	<?php if ( '' !== $vm['skip'] ) : ?>
	<a class="tth-skip" href="<?php echo esc_attr( $vm['skip'] ); ?>"><?php esc_html_e( 'رفتن به محتوای اصلی', 'tecteb-header' ); ?></a>
	<?php endif; ?>
	<?php echo file_get_contents( $vm['sprite'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- فایل ثابت خودِ افزونه، بی هیچ ورودی. ?>

	<div class="tth-bar" data-tth-bar>
		<div class="tth-wrap tth-top">
			<button class="tth-burger tth-icon-btn" type="button" data-tth-burger aria-controls="tth-drawer" aria-expanded="false" aria-label="<?php esc_attr_e( 'منوی سایت', 'tecteb-header' ); ?>">
				<svg class="tth-i" aria-hidden="true"><use href="#tth-i-menu"/></svg>
			</button>

			<a class="tth-logo" href="<?php echo esc_url( $vm['home'] ); ?>" aria-label="<?php echo esc_attr( $vm['logo']['label'] ); ?>">
				<img class="tth-logo__mark" src="<?php echo esc_url( $vm['logo']['src'] ); ?>" width="96" height="96" alt="">
				<?php if ( '' !== $vm['logo']['text'] ) : ?>
				<span class="tth-logo__word"><?php echo esc_html( $vm['logo']['text'] ); ?></span>
				<?php endif; ?>
			</a>

			<?php if ( 'shortcode' === $vm['search']['mode'] ) : ?>
			<div class="tth-search tth-search--custom" id="tth-search-form">
				<?php echo do_shortcode( $vm['search']['shortcode'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی shortcode ِ افزونهٔ جست‌وجویی که مدیر صریحاً انتخاب کرده. ?>
			</div>
			<?php else : ?>
			<form class="tth-search" id="tth-search-form" role="search" action="<?php echo esc_url( $vm['search']['action'] ); ?>" method="get">
				<label class="tth-sr" for="tth-s"><?php esc_html_e( 'جست‌وجوی محصولات', 'tecteb-header' ); ?></label>
				<input class="tth-search__input" id="tth-s" type="search" name="s" value="<?php echo esc_attr( $vm['search']['value'] ); ?>" placeholder="<?php esc_attr_e( 'جست‌وجوی نام، برند یا کد کالا…', 'tecteb-header' ); ?>" autocomplete="off" enterkeyhint="search">
				<input type="hidden" name="post_type" value="product">
				<button class="tth-search__go" type="submit" aria-label="<?php esc_attr_e( 'جست‌وجو', 'tecteb-header' ); ?>">
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-search"/></svg>
				</button>
			</form>
			<?php endif; ?>

			<div class="tth-tools">
				<?php // همیشه چاپ می‌شود (header.js به آن تکیه دارد)؛ فقط در موبایلِ صفحهٔ اصلی دیده می‌شود (CSS ِ --home). ?>
				<button class="tth-tool tth-tool--search" type="button" data-tth-search-toggle aria-controls="tth-search-form" aria-expanded="false">
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-search"/></svg>
					<span class="tth-sr"><?php esc_html_e( 'جست‌وجوی محصولات', 'tecteb-header' ); ?></span>
				</button>

				<?php if ( ! $tth_acct['logged_in'] ) : ?>
				<a class="tth-tool tth-tool--account" href="<?php echo esc_url( $tth_acct['url'] ); ?>" data-tth-guest>
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-user"/></svg>
					<span class="tth-tool__label"><?php esc_html_e( 'ورود / ثبت‌نام', 'tecteb-header' ); ?></span>
				</a>
				<?php else : ?>
				<div class="tth-acct" data-tth-disclosure data-tth-member>
					<a class="tth-tool tth-tool--account tth-nojs" href="<?php echo esc_url( $tth_acct['url'] ); ?>">
						<svg class="tth-i" aria-hidden="true"><use href="#tth-i-user"/></svg>
						<span class="tth-tool__label"><?php esc_html_e( 'حساب من', 'tecteb-header' ); ?></span>
					</a>
					<button class="tth-tool tth-tool--account tth-js" type="button" data-tth-toggle aria-expanded="false" aria-controls="tth-acct-menu">
						<svg class="tth-i" aria-hidden="true"><use href="#tth-i-user"/></svg>
						<span class="tth-tool__label"><?php esc_html_e( 'حساب من', 'tecteb-header' ); ?></span>
						<svg class="tth-i tth-i--chev" aria-hidden="true"><use href="#tth-i-chev"/></svg>
					</button>
					<div class="tth-pop" id="tth-acct-menu" hidden>
						<p class="tth-pop__hello"><?php esc_html_e( 'سلام،', 'tecteb-header' ); ?> <bdi><?php echo esc_html( $tth_acct['name'] ); ?></bdi></p>
						<ul class="tth-pop__list">
							<?php foreach ( $tth_acct['items'] as $tth_item ) : ?>
								<?php if ( 'customer-logout' === $tth_item['key'] ) : ?>
							<li class="tth-pop__sep"><a href="<?php echo esc_url( $tth_item['url'] ); ?>"><svg class="tth-i" aria-hidden="true"><use href="#tth-i-out"/></svg><?php echo esc_html( $tth_item['label'] ); ?></a></li>
								<?php else : ?>
							<li><a href="<?php echo esc_url( $tth_item['url'] ); ?>"><?php echo esc_html( $tth_item['label'] ); ?></a></li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( null !== $tth_wish ) : ?>
				<a class="tth-tool tth-tool--count tth-tool--wish" href="<?php echo esc_url( $tth_wish['url'] ); ?>">
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-heart"/></svg>
					<?php if ( null !== $tth_wish['count'] && $tth_wish['count'] > 0 ) : ?>
					<span class="tth-sr"><?php esc_html_e( 'علاقه‌مندی‌ها،', 'tecteb-header' ); ?></span>
					<span class="tth-badge" data-tth-count><?php echo esc_html( \Tecteb\Header\Account::fa( $tth_wish['count'] ) ); ?></span>
					<span class="tth-sr"><?php esc_html_e( 'مورد', 'tecteb-header' ); ?></span>
					<?php else : ?>
					<span class="tth-sr"><?php esc_html_e( 'علاقه‌مندی‌ها', 'tecteb-header' ); ?></span>
					<?php endif; ?>
				</a>
				<?php endif; ?>

				<?php if ( '' !== $vm['cart']['url'] ) : ?>
					<?php echo \Tecteb\Header\Renderer::cart_link( $vm['cart'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- هر جزء داخل cart_link escape شده. ?>
				<?php endif; ?>
			</div>
		</div>

		<nav class="tth-nav" aria-label="<?php esc_attr_e( 'منوی اصلی', 'tecteb-header' ); ?>">
			<div class="tth-wrap tth-nav__row">
				<?php if ( $tth_has_cat ) : ?>
				<div class="tth-cats" data-tth-disclosure data-tth-hover>
					<a class="tth-cats__btn tth-nojs" href="<?php echo esc_url( $vm['shop'] ); ?>">
						<svg class="tth-i" aria-hidden="true"><use href="#tth-i-grid"/></svg><?php esc_html_e( 'دسته‌بندی کالاها', 'tecteb-header' ); ?>
					</a>
					<button class="tth-cats__btn tth-js" type="button" data-tth-toggle aria-expanded="false" aria-controls="tth-mega">
						<svg class="tth-i" aria-hidden="true"><use href="#tth-i-grid"/></svg>
						<?php esc_html_e( 'دسته‌بندی کالاها', 'tecteb-header' ); ?>
						<svg class="tth-i tth-i--chev" aria-hidden="true"><use href="#tth-i-chev"/></svg>
					</button>
					<div class="tth-mega" id="tth-mega" hidden>
						<div class="tth-mega__body"><ul class="tth-mega__mains">
						<?php foreach ( $tth_cats as $tth_i => $tth_c ) : ?>
							<li class="tth-mega__item<?php echo 0 === $tth_i ? ' is-active' : ''; ?>"><a class="tth-mega__main" href="<?php echo esc_url( $tth_c['url'] ); ?>"><?php echo $tth_icon( $tth_c, 'tth-mega__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در $tth_icon. ?><span class="tth-mega__name"><?php echo esc_html( $tth_c['name'] ); ?></span><svg class="tth-i tth-mega__chev" aria-hidden="true"><use href="#tth-i-back"/></svg></a><div class="tth-mega__sub"><a class="tth-mega__subhead" href="<?php echo esc_url( $tth_c['url'] ); ?>"><?php /* translators: %s: category name */ echo esc_html( sprintf( __( 'همهٔ محصولات %s', 'tecteb-header' ), $tth_c['name'] ) ); ?><svg class="tth-i" aria-hidden="true"><use href="#tth-i-back"/></svg></a>
							<?php if ( array() !== $tth_c['children'] ) : ?>
								<ul class="tth-mega__list">
								<?php foreach ( $tth_c['children'] as $tth_k ) : ?>
									<li><a href="<?php echo esc_url( $tth_k['url'] ); ?>"><?php echo esc_html( $tth_k['name'] ); ?></a></li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							</div></li>
						<?php endforeach; ?>
						</ul></div>
						<div class="tth-mega__foot">
							<a class="tth-mega__all" href="<?php echo esc_url( $vm['shop'] ); ?>"><?php esc_html_e( 'مشاهدهٔ همهٔ محصولات در فروشگاه', 'tecteb-header' ); ?><svg class="tth-i" aria-hidden="true"><use href="#tth-i-back"/></svg></a>
						</div>
					</div>
				</div>
				<?php else : ?>
				<div class="tth-cats">
					<a class="tth-cats__btn" href="<?php echo esc_url( $vm['shop'] ); ?>">
						<svg class="tth-i" aria-hidden="true"><use href="#tth-i-grid"/></svg><?php esc_html_e( 'دسته‌بندی کالاها', 'tecteb-header' ); ?>
					</a>
				</div>
				<?php endif; ?>

				<?php if ( array() !== $vm['nav'] ) : ?>
				<ul class="tth-menu">
					<?php foreach ( $vm['nav'] as $tth_item ) : ?>
					<li><?php echo $tth_nav_link( $tth_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در $tth_nav_link. ?></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>

				<?php if ( '' !== $vm['supplier'] ) : ?>
				<a class="tth-seller" href="<?php echo esc_url( $vm['supplier'] ); ?>">
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-partner"/></svg><?php esc_html_e( 'همکاری تأمین‌کنندگان', 'tecteb-header' ); ?>
				</a>
				<?php endif; ?>
			</div>
		</nav>
	</div>

	<dialog class="tth-drawer" id="tth-drawer" aria-label="<?php esc_attr_e( 'منوی سایت', 'tecteb-header' ); ?>">
		<div class="tth-drawer__panel">
			<div class="tth-drawer__head">
				<a class="tth-logo" href="<?php echo esc_url( $vm['home'] ); ?>" aria-label="<?php echo esc_attr( $vm['logo']['label'] ); ?>">
					<img class="tth-logo__mark" src="<?php echo esc_url( $vm['logo']['src'] ); ?>" width="96" height="96" alt="">
					<?php if ( '' !== $vm['logo']['text'] ) : ?>
					<span class="tth-logo__word"><?php echo esc_html( $vm['logo']['text'] ); ?></span>
					<?php endif; ?>
				</a>
				<button class="tth-icon-btn" type="button" data-tth-close aria-label="<?php esc_attr_e( 'بستن منو', 'tecteb-header' ); ?>">
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-close"/></svg>
				</button>
			</div>

			<div class="tth-drawer__account">
				<?php if ( ! $tth_acct['logged_in'] ) : ?>
				<a class="tth-drawer__acct" href="<?php echo esc_url( $tth_acct['url'] ); ?>" data-tth-guest>
					<svg class="tth-i" aria-hidden="true"><use href="#tth-i-user"/></svg><?php esc_html_e( 'ورود / ثبت‌نام', 'tecteb-header' ); ?>
				</a>
				<?php else : ?>
				<div data-tth-member>
					<p class="tth-pop__hello"><?php esc_html_e( 'سلام،', 'tecteb-header' ); ?> <bdi><?php echo esc_html( $tth_acct['name'] ); ?></bdi></p>
					<ul class="tth-drawer__list tth-drawer__list--two">
						<?php foreach ( $tth_acct['items'] as $tth_item ) : ?>
						<li><a href="<?php echo esc_url( $tth_item['url'] ); ?>"><?php echo esc_html( $tth_item['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $vm['nav'] || null !== $tth_wish ) : ?>
			<nav aria-label="<?php esc_attr_e( 'منوی اصلی (موبایل)', 'tecteb-header' ); ?>">
				<ul class="tth-drawer__list">
					<?php foreach ( $vm['nav'] as $tth_item ) : ?>
					<li><?php echo $tth_nav_link( $tth_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در $tth_nav_link. ?></li>
					<?php endforeach; ?>
					<?php if ( null !== $tth_wish ) : ?>
					<li><a href="<?php echo esc_url( $tth_wish['url'] ); ?>"><?php esc_html_e( 'علاقه‌مندی‌ها', 'tecteb-header' ); ?><?php if ( null !== $tth_wish['count'] && $tth_wish['count'] > 0 ) : ?> <span class="tth-badge"><?php echo esc_html( \Tecteb\Header\Account::fa( $tth_wish['count'] ) ); ?></span><?php endif; ?></a></li>
					<?php endif; ?>
				</ul>
			</nav>
			<?php endif; ?>

			<section class="tth-drawer__cats" aria-labelledby="tth-drawer-cats">
				<h2 class="tth-drawer__title" id="tth-drawer-cats"><?php esc_html_e( 'دسته‌بندی کالاها', 'tecteb-header' ); ?></h2>
				<?php foreach ( $tth_cats as $tth_c ) : ?>
				<details class="tth-drawer__cat"><summary><?php echo $tth_icon( $tth_c, 'tth-drawer__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در $tth_icon. ?><span class="tth-drawer__name"><?php echo esc_html( $tth_c['name'] ); ?></span><svg class="tth-i tth-i--chev" aria-hidden="true"><use href="#tth-i-chev"/></svg></summary><ul><li><a href="<?php echo esc_url( $tth_c['url'] ); ?>"><?php /* translators: %s: category name */ echo esc_html( sprintf( __( 'همهٔ %s', 'tecteb-header' ), $tth_c['name'] ) ); ?></a></li>
					<?php foreach ( $tth_c['children'] as $tth_k ) : ?>
					<li><a href="<?php echo esc_url( $tth_k['url'] ); ?>"><?php echo esc_html( $tth_k['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul></details>
				<?php endforeach; ?>
				<a class="tth-drawer__all" href="<?php echo esc_url( $vm['shop'] ); ?>"><?php esc_html_e( 'همهٔ محصولات در فروشگاه', 'tecteb-header' ); ?></a>
			</section>

			<?php if ( '' !== $vm['supplier'] ) : ?>
			<a class="tth-seller tth-seller--block" href="<?php echo esc_url( $vm['supplier'] ); ?>">
				<svg class="tth-i" aria-hidden="true"><use href="#tth-i-partner"/></svg><?php esc_html_e( 'همکاری تأمین‌کنندگان', 'tecteb-header' ); ?>
			</a>
			<?php endif; ?>
		</div>
	</dialog>
</div>
