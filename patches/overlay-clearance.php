<?php
/**
 * Drop-in addition for the Shatania Profile System plugin.
 * Paste everything BELOW the <?php line at the very end of profile.php
 * (after the final `}, 101 );`), or keep it as its own mu-plugin file.
 */
// OVERLAY CLEARANCE — keep foreign fixed bottom bars off the chat
// ============================================================
/**
 * On mobile a theme-level bottom navigation bar (or any other plugin's
 * fixed bottom toolbar) is painted on the layout viewport with its own
 * z-index. When the chat panel opens, that bar can end up sitting on top
 * of the composer, so the text field and Send button cannot be tapped.
 *
 * Raising the chat's z-index is not a reliable fix — a theme is free to
 * use a higher one — so instead we *hide* those bars for as long as one
 * of our own full-screen overlays is open, then put them back exactly as
 * they were.
 *
 * Nothing here hardcodes a theme selector. A small depth-limited walk of
 * the document finds elements that are (a) position:fixed or sticky,
 * (b) anchored to the bottom of the viewport, (c) wide and short like a
 * bar, and (d) not part of our own UI. Those are the only things hidden.
 *
 * The trigger is the `schat-locked` class the chat already puts on <body>
 * (every open/close path sets it, including the inbox and deep links), so
 * this needs no changes to the chat's own JavaScript. The profile sheet
 * is wired up through the same helper.
 */
add_action( 'wp_footer', function() {
	if ( ! is_user_logged_in() ) return;
	?>
	<style id="shat-overlay-clearance-css">
	/* Belt-and-braces: the JS below is what actually finds the bar, but
	   these cover the conventional markup without waiting for a frame. */
	body.schat-locked > nav[class*="bottom"],
	body.schat-locked > div[class*="bottom-nav"],
	body.schat-locked > div[class*="bottom-bar"],
	body.schat-locked .shat-nav-suppressed{
		display:none !important;
	}
	/* Applied by JS to anything it identifies. Kept separate from the
	   element's own inline style so nothing of theirs is overwritten. */
	.shat-nav-suppressed{
		display:none !important;
		visibility:hidden !important;
		pointer-events:none !important;
	}
	</style>
	<script id="shat-overlay-clearance-js">
	(function () {
		/* Our own chrome — never hide any of it. */
		var OURS = [
			'.schat-overlay', '.schat-inbox-overlay', '.schat-root',
			'.shat-me-overlay', '.shat-candy-toast', '.shat-root',
			'.schat-fab', '#schat-fab-btn', '#schat-admin-fab-btn',
			'#shat-overlay-clearance-css', '#shat-overlay-clearance-js',
			'#wpadminbar'
		].join(',');

		var suppressed = [];   // elements we hid, so we can restore them
		var depth      = 8;    // how far down from <body> to look

		function isOurs(el) {
			return !!(el.closest && el.closest(OURS));
		}

		/* A bar worth hiding: fixed/sticky, sitting at (or below) the
		   bottom edge, at least half the screen wide, and no taller than
		   a third of the screen. That shape is a navigation bar; a
		   full-screen modal or a page wrapper is not. */
		function looksLikeBottomBar(el) {
			var cs = window.getComputedStyle(el);
			if (cs.position !== 'fixed' && cs.position !== 'sticky') return false;
			if (cs.display === 'none' || cs.visibility === 'hidden') return false;

			var r = el.getBoundingClientRect();
			if (r.width <= 0 || r.height <= 0) return false;

			var vh = window.innerHeight  || document.documentElement.clientHeight;
			var vw = window.innerWidth   || document.documentElement.clientWidth;

			if (r.height > vh / 3) return false;          // too tall for a bar
			if (r.width  < vw * 0.5) return false;        // too narrow for a bar
			if (r.bottom < vh - 24) return false;         // not at the bottom

			return true;
		}

		/* Depth-limited walk. Fixed bottom bars live near the top of the
		   tree, so this stays cheap even on a heavy page — and it runs
		   only when an overlay opens. */
		function collect(node, level, out) {
			if (level > depth) return;
			for (var i = 0; i < node.children.length; i++) {
				var el = node.children[i];
				var tag = el.tagName;
				if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' ||
				    tag === 'TEMPLATE' || tag === 'NOSCRIPT') continue;
				if (isOurs(el)) continue;
				if (looksLikeBottomBar(el)) { out.push(el); continue; }
				collect(el, level + 1, out);
			}
		}

		function suppress() {
			if (suppressed.length) return;   // already hidden
			var found = [];
			try { collect(document.body, 0, found); } catch (e) { return; }
			found.forEach(function (el) {
				el.classList.add('shat-nav-suppressed');
				el.setAttribute('aria-hidden', 'true');
				suppressed.push(el);
			});
		}

		function restore() {
			suppressed.forEach(function (el) {
				el.classList.remove('shat-nav-suppressed');
				el.removeAttribute('aria-hidden');
			});
			suppressed = [];
		}

		/* Public, so anything else in the plugin can borrow it. */
		window.shatSuppressFixedBars = suppress;
		window.shatRestoreFixedBars  = restore;

		function anyOverlayOpen() {
			return document.body.classList.contains('schat-locked') ||
			       !!document.querySelector('.shat-me-overlay.shat-me-open') ||
			       !!document.querySelector('.schat-overlay.open') ||
			       !!document.querySelector('.schat-inbox-overlay.open');
		}

		var lastOpen = null;
		function sync() {
			var open = anyOverlayOpen();
			if (open === lastOpen) return;   // nothing changed
			lastOpen = open;
			if (open) suppress(); else restore();
		}

		/* One observer covers every open and close path: the chat toggles
		   `schat-locked` on <body>, and the profile sheet toggles
		   `shat-me-open` on its own overlay. The chat also retags message
		   rows constantly while you scroll, so the check is coalesced into
		   one animation frame and then short-circuits on `lastOpen`. */
		if (window.MutationObserver) {
			var queued = false;
			new MutationObserver(function () {
				if (queued) return;
				queued = true;
				requestAnimationFrame(function () { queued = false; sync(); });
			}).observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['class'],
				subtree: true
			});
		}

		/* A bar can also be revealed mid-session (lazy-loaded theme JS) or
		   only become bottom-anchored once the keyboard resizes the visual
		   viewport, so re-check on those moments too. */
		if (window.visualViewport) {
			window.visualViewport.addEventListener('resize', function () {
				if (anyOverlayOpen()) { restore(); suppress(); }
			});
		}
		window.addEventListener('orientationchange', function () {
			if (anyOverlayOpen()) { restore(); suppress(); }
		});

		/* The standalone chat page has no overlay to toggle — the thread
		   IS the page — so hide the bars for the whole visit. */
		document.addEventListener('DOMContentLoaded', function () {
			if (document.querySelector('.schat-overlay.schat-standalone-mode')) {
				lastOpen = true; suppress();
			} else { sync(); }
		});
		if (document.readyState !== 'loading') {
			if (document.querySelector('.schat-overlay.schat-standalone-mode')) {
				lastOpen = true; suppress();
			} else { sync(); }
		}
	})();
	</script>
	<?php
}, 102 );
