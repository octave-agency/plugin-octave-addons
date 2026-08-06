<?php

/*
SITE STATUS TEMPLATE
-- Self-contained branded maintenance and critical-error page.
-- Uses no WordPress functions so the generated drop-ins can load before
-- WordPress connects to the database or boots active plugins.
---------------------------------------------------------- */

$octave_status_is_maintenance = 'maintenance' === $octave_status_type;
$octave_status_title          = $octave_status_is_maintenance ? 'We’re working on updates' : 'Something didn’t load as expected';
$octave_status_description    = $octave_status_is_maintenance
	? 'We’re making a few improvements behind the scenes. We’ll have everything ready for you shortly.'
	: 'We’re sorry — the site has hit a temporary problem. Please try again in a moment, or let the site team know if it continues.';
$octave_status_label          = $octave_status_is_maintenance ? 'Updates in progress' : 'Temporary issue';
$octave_status_action         = $octave_status_is_maintenance ? 'Check again' : 'Try again';
$octave_status_document_title = $octave_status_title . ' — ' . $octave_status_site_name;
$octave_status_safe_name      = htmlspecialchars( $octave_status_site_name, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_title     = htmlspecialchars( $octave_status_document_title, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_home      = htmlspecialchars( $octave_status_home_url, ENT_QUOTES, 'UTF-8' );
$octave_status_safe_logo      = htmlspecialchars( $octave_status_logo, ENT_QUOTES, 'UTF-8' );

if ( ! $octave_status_preview && ! headers_sent() ) {

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
			color-scheme: dark;
			--oa-bg: #090b0c;
			--oa-surface: rgba(20, 23, 24, 0.9);
			--oa-border: rgba(147, 147, 147, 0.18);
			--oa-accent: #2bff86;
			--oa-accent-2: #00d084;
			--oa-text: #f4f9ff;
			--oa-muted: #a7aaab;
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
			padding: clamp(20px, 5vw, 56px);
			background:
				radial-gradient(circle at 78% 8%, rgba(0, 208, 132, 0.14), transparent 30%),
				radial-gradient(circle at 28% 18%, rgba(43, 255, 134, 0.12), transparent 34%),
				var(--oa-bg);
			color: var(--oa-text);
			font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			-webkit-font-smoothing: antialiased;
		}

		.oa-status {
			position: relative;
			width: min(100%, 680px);
			padding: clamp(32px, 7vw, 64px);
			overflow: hidden;
			background: var(--oa-surface);
			border: 1px solid var(--oa-border);
			border-radius: 24px;
			box-shadow: 0 28px 80px rgba(0, 0, 0, 0.38), inset 0 1px rgba(255, 255, 255, 0.04);
			text-align: center;
		}

		.oa-status::before {
			position: absolute;
			top: 0;
			left: 18%;
			width: 64%;
			height: 1px;
			background: linear-gradient(90deg, transparent, var(--oa-accent), transparent);
			content: "";
		}

		.oa-status__brand {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 14px;
			margin-bottom: clamp(34px, 7vw, 54px);
		}

		.oa-status__logo-wrap {
			display: grid;
			place-items: center;
			min-width: 58px;
			max-width: 180px;
			height: 58px;
			padding: 10px;
			background: linear-gradient(145deg, rgba(43, 255, 134, 0.22), rgba(0, 208, 132, 0.08));
			border: 1px solid rgba(43, 255, 134, 0.2);
			border-radius: 17px;
			box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
		}

		.oa-status__logo {
			display: block;
			width: auto;
			max-width: 158px;
			height: 100%;
			object-fit: contain;
		}

		.oa-status__name {
			max-width: 260px;
			font-size: 16px;
			font-weight: 700;
			line-height: 1.3;
			text-align: left;
		}

		.oa-status__indicator {
			display: inline-flex;
			align-items: center;
			gap: 9px;
			margin-bottom: 22px;
			padding: 7px 12px;
			background: rgba(43, 255, 134, 0.1);
			border: 1px solid rgba(43, 255, 134, 0.2);
			border-radius: 999px;
			color: var(--oa-accent);
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.05em;
			text-transform: uppercase;
		}

		.oa-status__dot {
			width: 8px;
			height: 8px;
			background: currentColor;
			border-radius: 50%;
			box-shadow: 0 0 0 5px rgba(43, 255, 134, 0.1);
			animation: oa-status-pulse 2s ease-in-out infinite;
		}

		h1 {
			max-width: 540px;
			margin: 0 auto 18px;
			font-size: clamp(34px, 7vw, 58px);
			font-weight: 750;
			letter-spacing: -0.045em;
			line-height: 1.03;
		}

		.oa-status__description {
			max-width: 500px;
			margin: 0 auto;
			color: var(--oa-muted);
			font-size: clamp(16px, 2.5vw, 18px);
			line-height: 1.65;
		}

		.oa-status__actions {
			display: flex;
			flex-wrap: wrap;
			justify-content: center;
			gap: 12px;
			margin-top: 34px;
		}

		.oa-status__button {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-height: 46px;
			padding: 0 20px;
			background: linear-gradient(135deg, var(--oa-accent), var(--oa-accent-2));
			border: 0;
			border-radius: 12px;
			box-shadow: 0 10px 28px rgba(0, 208, 132, 0.18);
			color: #090b0c;
			font: inherit;
			font-size: 14px;
			font-weight: 750;
			text-decoration: none;
			cursor: pointer;
			transition: transform 180ms ease, box-shadow 180ms ease;
		}

		.oa-status__button:hover {
			box-shadow: 0 14px 34px rgba(0, 208, 132, 0.28);
			transform: translateY(-2px);
		}

		.oa-status__button:focus-visible {
			outline: 2px solid var(--oa-accent);
			outline-offset: 4px;
		}

		.oa-status__home {
			display: inline-flex;
			align-items: center;
			min-height: 46px;
			padding: 0 10px;
			color: var(--oa-muted);
			font-size: 14px;
			font-weight: 650;
			text-underline-offset: 4px;
		}

		.oa-status__home:hover {
			color: var(--oa-text);
		}

		.oa-status__home:focus-visible {
			border-radius: 4px;
			outline: 2px solid var(--oa-accent);
			outline-offset: 3px;
		}

		@keyframes oa-status-pulse {
			0%, 100% {
				opacity: 1;
				transform: scale(1);
			}

			50% {
				opacity: 0.58;
				transform: scale(0.82);
			}
		}

		@media (max-width: 520px) {
			.oa-status {
				border-radius: 19px;
			}

			.oa-status__brand {
				align-items: center;
				flex-direction: column;
			}

			.oa-status__name {
				text-align: center;
			}
		}

		@media (prefers-reduced-motion: reduce) {
			.oa-status__dot {
				animation: none;
			}

			.oa-status__button {
				transition: none;
			}
		}
	</style>
</head>
<body>
	<main class="oa-status">
		<div class="oa-status__brand">

			<?php

			if ( '' !== $octave_status_logo ) :

			?>

			<span class="oa-status__logo-wrap">
				<img class="oa-status__logo" src="<?= $octave_status_safe_logo; ?>" alt="">
			</span>

			<?php

			endif;

			?>

			<span class="oa-status__name"><?= $octave_status_safe_name; ?></span>
		</div>

		<div class="oa-status__indicator">
			<span class="oa-status__dot" aria-hidden="true"></span>
			<?= htmlspecialchars( $octave_status_label, ENT_QUOTES, 'UTF-8' ); ?>
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
	</main>
</body>
</html>
