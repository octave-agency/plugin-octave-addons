<?php

/*
SITE STATUS TEMPLATE
-- Self-contained branded maintenance and critical-error page.
-- Uses no WordPress functions so generated drop-ins can load before WordPress.
---------------------------------------------------------- */

$octave_status_is_maintenance = 'maintenance' === $octave_status_type;
$octave_status_title          = $octave_status_is_maintenance ? 'We’re making a few improvements' : 'Something didn’t load as expected';
$octave_status_description    = $octave_status_is_maintenance
	? 'The site is temporarily unavailable while scheduled work is completed. Please check back shortly.'
	: 'The site has encountered a temporary problem. Try again in a moment, or contact the site team if it continues.';
$octave_status_label          = $octave_status_is_maintenance ? 'Scheduled maintenance' : 'Temporary issue';
$octave_status_action         = $octave_status_is_maintenance ? 'Check again' : 'Try again';
$octave_status_code           = $octave_status_is_maintenance ? '503' : '500';
$octave_status_document_title = $octave_status_title . ' — ' . $octave_status_site_name;
$octave_status_safe_name      = htmlspecialchars( $octave_status_site_name, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_title     = htmlspecialchars( $octave_status_document_title, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_home      = htmlspecialchars( $octave_status_home_url, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_logo      = htmlspecialchars( $octave_status_logo, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_background   = htmlspecialchars( $octave_status_background, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_surface      = htmlspecialchars( $octave_status_surface, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_border       = htmlspecialchars( $octave_status_border, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_primary      = htmlspecialchars( $octave_status_primary, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_primary_rgb  = htmlspecialchars( $octave_status_primary_rgb, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_on_primary   = htmlspecialchars( $octave_status_on_primary, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_text         = htmlspecialchars( $octave_status_text, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_muted        = htmlspecialchars( $octave_status_muted, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_shadow       = htmlspecialchars( $octave_status_shadow, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_color_scheme = htmlspecialchars( $octave_status_color_scheme, ENT_QUOTES, 'UTF-8' );

if ( ! headers_sent() ) {

	http_response_code( $octave_status_is_maintenance ? 503 : 500 );
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	if ( $octave_status_is_maintenance ) {

		header( 'Retry-After: 600' );

	}

}

?>

<!doctype html>
<html lang="en-GB">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?= $octave_status_safe_title; ?></title>
	<style>
		:root {
			color-scheme: <?= $octave_status_safe_color_scheme; ?>;
			--oa-bg: <?= $octave_status_safe_background; ?>;
			--oa-surface: <?= $octave_status_safe_surface; ?>;
			--oa-border: <?= $octave_status_safe_border; ?>;
			--oa-primary: <?= $octave_status_safe_primary; ?>;
			--oa-primary-rgb: <?= $octave_status_safe_primary_rgb; ?>;
			--oa-on-primary: <?= $octave_status_safe_on_primary; ?>;
			--oa-text: <?= $octave_status_safe_text; ?>;
			--oa-muted: <?= $octave_status_safe_muted; ?>;
			--oa-shadow: <?= $octave_status_safe_shadow; ?>;
		}

		* {
			box-sizing: border-box;
		}

		html {
			min-height: 100%;
			background: var(--oa-bg);
		}

		body {
			display: grid;
			place-items: center;
			min-height: 100vh;
			min-height: 100svh;
			margin: 0;
			padding: clamp(20px, 5vw, 72px);
			background: var(--oa-bg);
			color: var(--oa-text);
			font-family: "Avenir Next", Avenir, "Segoe UI", Helvetica, Arial, sans-serif;
			-webkit-font-smoothing: antialiased;
		}

		.oa-status {
			display: grid;
			grid-template-columns: minmax(240px, 0.72fr) minmax(0, 1.45fr);
			width: min(100%, 1080px);
			min-height: min(620px, calc(100svh - 80px));
			overflow: hidden;
			background: var(--oa-surface);
			border: 1px solid var(--oa-border);
			box-shadow: var(--oa-shadow);
		}

		.oa-status__rail {
			display: flex;
			flex-direction: column;
			justify-content: space-between;
			padding: clamp(30px, 5vw, 58px);
			background: var(--oa-primary);
			color: var(--oa-on-primary);
		}

		.oa-status__brand {
			display: flex;
			align-items: flex-start;
		}

		.oa-status__logo {
			display: block;
			width: auto;
			max-width: 190px;
			height: 60px;
			object-fit: contain;
			object-position: left center;
		}

		.oa-status__name {
			max-width: 220px;
			font-size: 17px;
			font-weight: 700;
			letter-spacing: -0.015em;
			line-height: 1.35;
		}

		.oa-status__rail-note {
			padding-top: 24px;
			border-top: 1px solid currentColor;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.12em;
			line-height: 1.5;
			opacity: 0.78;
			text-transform: uppercase;
		}

		.oa-status__content {
			display: flex;
			flex-direction: column;
			justify-content: center;
			padding: clamp(40px, 8vw, 96px);
		}

		.oa-status__meta {
			display: flex;
			align-items: center;
			gap: 16px;
			margin-bottom: 42px;
			color: var(--oa-muted);
			font-size: 11px;
			font-weight: 750;
			letter-spacing: 0.12em;
			text-transform: uppercase;
		}

		.oa-status__code {
			color: var(--oa-primary);
			font-variant-numeric: tabular-nums;
		}

		.oa-status__meta::after {
			width: 58px;
			height: 1px;
			background: var(--oa-border);
			content: "";
		}

		h1 {
			max-width: 680px;
			margin: 0 0 24px;
			font-size: clamp(38px, 6vw, 62px);
			font-weight: 650;
			letter-spacing: -0.055em;
			line-height: 0.98;
		}

		.oa-status__description {
			max-width: 590px;
			margin: 0;
			color: var(--oa-muted);
			font-size: clamp(16px, 2vw, 19px);
			line-height: 1.65;
		}

		.oa-status__actions {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 22px;
			margin-top: 42px;
		}

		.oa-status__button {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-height: 50px;
			padding: 0 24px;
			background: var(--oa-primary);
			border: 1px solid var(--oa-primary);
			border-radius: 1px;
			color: var(--oa-on-primary);
			font: inherit;
			font-size: 12px;
			font-weight: 750;
			letter-spacing: 0.09em;
			text-decoration: none;
			text-transform: uppercase;
			transition: filter 160ms ease, transform 160ms ease;
		}

		.oa-status__button:hover {
			filter: brightness(0.9);
			transform: translateY(-1px);
		}

		.oa-status__button:focus-visible,
		.oa-status__home:focus-visible {
			outline: 2px solid var(--oa-primary);
			outline-offset: 4px;
		}

		.oa-status__home {
			color: var(--oa-muted);
			font-size: 14px;
			font-weight: 650;
			text-decoration-color: currentColor;
			text-underline-offset: 5px;
		}

		.oa-status__home:hover {
			color: var(--oa-text);
		}

		@media (max-width: 720px) {
			body {
				padding: 14px;
			}

			.oa-status {
				grid-template-columns: 1fr;
				min-height: calc(100svh - 28px);
			}

			.oa-status__rail {
				min-height: 160px;
			}

			.oa-status__rail-note {
				align-self: flex-end;
				padding: 0;
				border: 0;
			}

			.oa-status__content {
				padding: 42px 30px 54px;
			}

			.oa-status__meta {
				margin-bottom: 30px;
			}
		}

		@media (prefers-reduced-motion: reduce) {
			.oa-status__button {
				transition: none;
			}
		}
	</style>
</head>
<body>
	<main class="oa-status">
		<aside class="oa-status__rail">
			<div class="oa-status__brand">

				<?php

				if ( '' !== $octave_status_logo ) :

				?>

				<img class="oa-status__logo" src="<?= $octave_status_safe_logo; ?>" alt="<?= $octave_status_safe_name; ?>">

				<?php

				else :

				?>

				<span class="oa-status__name"><?= $octave_status_safe_name; ?></span>

				<?php

				endif;

				?>

			</div>
			<div class="oa-status__rail-note"><?= htmlspecialchars( $octave_status_label, ENT_QUOTES, 'UTF-8' ); ?></div>
		</aside>

		<section class="oa-status__content">
			<div class="oa-status__meta">
				<span class="oa-status__code"><?= $octave_status_code; ?></span>
				<span>Site notice</span>
			</div>

			<h1><?= htmlspecialchars( $octave_status_title, ENT_QUOTES, 'UTF-8' ); ?></h1>
			<p class="oa-status__description"><?= htmlspecialchars( $octave_status_description, ENT_QUOTES, 'UTF-8' ); ?></p>

			<div class="oa-status__actions">
				<a class="oa-status__button" href=""><?= htmlspecialchars( $octave_status_action, ENT_QUOTES, 'UTF-8' ); ?></a>

				<?php

				if ( ! $octave_status_is_maintenance ) :

				?>

				<a class="oa-status__home" href="<?= $octave_status_safe_home; ?>">Return to homepage</a>

				<?php

				endif;

				?>

			</div>
		</section>
	</main>
</body>
</html>
