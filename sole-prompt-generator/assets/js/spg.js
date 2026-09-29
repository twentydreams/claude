/* Studio: prompt generators with AI refine, saved poses, setups and history.
 *
 * Map of this file, in order:
 *   1. Helpers ................ clone, esc, hex, sentence, localStorage, clipboard, api
 *   2. Library store .......... saved poses and setups (WordPress, or this browser)
 *   3. Palettes ............... nails, toes, fashion, jewelry + favourites
 *   4. UI building blocks ..... card, seg, choices, select, colorPicker, aiPanel, libBlock
 *   5. Erase buttons .......... the × inside every text box
 *   6. Camera & framing ....... the shared three dropdowns + reset
 *   7. Shared lists ........... lower-body outfits, hand accessories, earrings
 *   8. SOLE ................... master prompt, 50-pose library, form, build
 *   9. HAND ................... master prompt, quick poses, accessories, form, build
 *  10. FEET ................... standing self-view
 *  11. FEET CAROUSEL ......... next slide of a carousel
 *  12. SELFIE ................ accidental or clear phone selfie
 *  13. Block overrides ....... admin-editable master prompt paragraphs
 *  14. App shell ............. mount, render, bind, history, export/import
 */
(function () {
	'use strict';

	/* Server config injected by WordPress (wp_localize_script). */
	var CONFIG = window.SPG_CONFIG || {};

	var BACKGROUND_LOCK = 'Ensure the background is fully detailed, textured, and clear, avoiding heavy, unnatural blurring.';
	var REF_POSE_LOCK = 'Do not use the pose from the reference image.';

	/* Shared by the Feet and Feet-carousel master prompts. */
	var TOENAIL_GEOMETRY_LOCK = '[CRITICAL GEOMETRY LOCK]: Maintain the exact extreme length, thickness, and natural growth direction of the toenails as shown in reference images. They must remain long, sharp, and un-shortened. The nails should appear highly detailed, with no shaky edges or motion blur. Every nail edge needs to look stable, sharp, and crystal clear.';

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	function clone(o) { return JSON.parse(JSON.stringify(o)); }

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function normalizeHex(v) {
		var h = String(v || '').trim().replace(/^#/, '');
		if (/^[0-9a-f]{3}$/i.test(h)) {
			h = h.split('').map(function (c) { return c + c; }).join('');
		}
		return /^[0-9a-f]{6}$/i.test(h) ? '#' + h.toUpperCase() : null;
	}

	/* Trim and make sure the text ends like a sentence. */
	function sentence(t) {
		t = String(t || '').trim();
		if (t && !/[.!?…]$/.test(t)) t += '.';
		return t;
	}

	function cap(t) { return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; }

	function lsGet(key, fallback) {
		try {
			var raw = window.localStorage.getItem(key);
			return raw ? JSON.parse(raw) : fallback;
		} catch (e) { return fallback; }
	}

	function lsSet(key, val) {
		try { window.localStorage.setItem(key, JSON.stringify(val)); return true; } catch (e) { return false; }
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy') ? resolve() : reject(); } catch (e) { reject(e); }
			document.body.removeChild(ta);
		});
	}

	function download(filename, text) {
		var blob = new Blob([text], { type: 'application/json' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		setTimeout(function () {
			URL.revokeObjectURL(a.href);
			a.parentNode.removeChild(a);
		}, 1000);
	}

	function api(method, path, body) {
		return fetch(CONFIG.restUrl + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CONFIG.nonce },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) {
					/* A page left open long enough for the nonce to expire looks
					 * exactly like being logged out. Say so instead of "403". */
					if (d.code === 'rest_cookie_invalid_nonce' || 401 === r.status || 403 === r.status) {
						var e = new Error('Your login session expired. Reload the page, then try again — your text is still here.');
						e.expired = true;
						throw e;
					}
					throw new Error(d.message || ('Request failed (' + r.status + ')'));
				}
				return d;
			});
		});
	}

	function aiStatus() {
		if (!CONFIG.restUrl) return { ok: false, note: 'AI refine works once the plugin is installed on WordPress.' };
		if (!CONFIG.canUseAi) return { ok: false, note: 'Log in as an editor or admin to use AI refine.' };
		if (!CONFIG.aiReady) {
			return {
				ok: false,
				note: CONFIG.canManage
					? 'Add your Groq API key in <a href="' + esc(CONFIG.settingsUrl) + '">Studio → AI Settings</a> to turn this on.'
					: 'Ask an admin to add the Groq API key in Studio → AI Settings.'
			};
		}
		if (CONFIG.dailyCap) {
			var left = Math.max(0, CONFIG.dailyCap - (CONFIG.dailyUsed || 0));
			return { ok: true, note: left + ' AI refines left today.' };
		}
		return { ok: true, note: '' };
	}

	/* ------------------------------------------------------------------ *
	 * Library store: saved poses and setups.
	 * WordPress database when available, this browser otherwise.
	 * ------------------------------------------------------------------ */

	var LEGACY_LOCAL = { sole_pose: 'spg_saved_poses_v1' };

	function normItems(list) {
		return (Array.isArray(list) ? list : []).map(function (p) {
			return { id: String(p.id), name: String(p.name), value: p.value !== undefined ? p.value : p.pose, updated: p.updated };
		}).filter(function (p) { return p.value !== undefined && p.value !== null; });
	}

	function libStore(kind) {
		if (CONFIG.restUrl && CONFIG.canUseAi) {
			return {
				list: function () { return api('GET', 'library/' + kind).then(normItems); },
				save: function (name, value) {
					return api('POST', 'library/' + kind, { name: name, value: value }).then(function (d) { return normItems(d.items); });
				},
				remove: function (id) {
					return api('DELETE', 'library/' + kind + '/' + encodeURIComponent(id)).then(function (d) { return normItems(d.items); });
				},
				importItems: function (items) {
					return api('POST', 'library/' + kind + '/import', { items: items }).then(function (d) {
						return { items: normItems(d.items), added: d.added, updated: d.updated, skipped: d.skipped };
					});
				}
			};
		}
		var key = LEGACY_LOCAL[kind] || ('spg_lib_' + kind);
		function write(list) {
			if (!lsSet(key, list)) throw new Error('This browser blocked saving.');
			return list;
		}
		return {
			list: function () { return Promise.resolve(normItems(lsGet(key, []))); },
			save: function (name, value) {
				return Promise.resolve().then(function () {
					var list = normItems(lsGet(key, []));
					var hit = list.filter(function (p) { return p.name.toLowerCase() === name.toLowerCase(); })[0];
					if (hit) { hit.value = value; hit.updated = Date.now(); }
					else list.unshift({ id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6), name: name, value: value, updated: Date.now() });
					return write(list);
				});
			},
			remove: function (id) {
				return Promise.resolve().then(function () {
					return write(normItems(lsGet(key, [])).filter(function (p) { return p.id !== id; }));
				});
			},
			importItems: function (incoming) {
				return Promise.resolve().then(function () {
					var list = normItems(lsGet(key, []));
					var added = 0, updated = 0, skipped = 0;
					(Array.isArray(incoming) ? incoming : []).forEach(function (row) {
						var name = row && typeof row.name === 'string' ? row.name.trim().slice(0, 80) : '';
						var value = row ? row.value : undefined;
						if (!name || value === undefined || value === null || value === '') { skipped++; return; }
						var hit = list.filter(function (x) { return x.name.toLowerCase() === name.toLowerCase(); })[0];
						if (hit) { hit.value = value; hit.updated = Date.now(); updated++; return; }
						list.unshift({ id: Date.now().toString(36) + Math.random().toString(36).slice(2, 6), name: name, value: value, updated: Date.now() });
						added++;
					});
					write(list);
					return { items: list, added: added, updated: updated, skipped: skipped };
				});
			}
		};
	}

	/* ------------------------------------------------------------------ *
	 * Nail polish palette
	 * ------------------------------------------------------------------ */

	var PALETTE = [
		{
			group: 'Trending — Fall 2026',
			colors: [
				['Milk Chocolate', '#7B4B36'], ['Espresso', '#3B2219'], ['Smoky Plum', '#5E2B4A'],
				['Deep Wine', '#5A1426'], ['Burgundy', '#6D1A2C'], ['Dusty Blue', '#7B93AE'],
				['Lavender Haze', '#B9A7D4'], ['Butter Yellow', '#EBD9A0'], ['Copper', '#B06A3B'],
				['Glossy Berry', '#B8325E'], ['Forest Green', '#1F4A38'], ['Olive', '#6B6A3A'],
				['Dusty Mauve', '#BC9CB0']
			]
		},
		{
			group: 'Classics',
			colors: [
				['Deep Glossy Blue', '#0B2A8C'], ['Cobalt', '#1E4FD8'], ['Navy', '#14213D'],
				['Classic Red', '#C8102E'], ['Cherry', '#9B111E'], ['Hot Pink', '#E0457B'],
				['Ballet Pink', '#F2C6C2'], ['Nude Beige', '#D9B8A0'], ['Milky White', '#F4EFE8'],
				['Chrome Silver', '#C0C4CA'], ['Gold', '#C9A227'], ['Jet Black', '#111111']
			]
		}
	];

	/* Cute, feminine shades picked for toenails. */
	var TOE_PALETTE = [
		{
			group: 'Pinks & corals',
			colors: [
				['Deep Pink', '#C2185B'], ['Hot Pink', '#E91E63'], ['Bubblegum', '#FF77A9'],
				['Baby Pink', '#F8BBD0'], ['Ballet Slipper', '#F4C2C2'], ['Watermelon', '#FC6C85'],
				['Coral', '#FF6F61'], ['Peach', '#FFB38A'], ['Fuchsia', '#D63384'], ['Rose Gold', '#B76E79']
			]
		},
		{
			group: 'Pastels',
			colors: [
				['Lilac', '#C8A2C8'], ['Lavender', '#B9A7D4'], ['Baby Blue', '#A7C7E7'],
				['Mint', '#98E2C6'], ['Butter Yellow', '#F3E5AB'], ['Dusty Mauve', '#BC9CB0']
			]
		},
		{
			group: 'Reds & deep shades',
			colors: [
				['Cherry Red', '#B00020'], ['Classic Red', '#C8102E'], ['Raspberry', '#9E1F4F'],
				['Burgundy', '#6D1A2C'], ['Plum', '#5E2B4A'], ['Chocolate', '#7B4B36']
			]
		},
		{
			group: 'Nudes & neutrals',
			colors: [
				['Nude', '#D9B8A0'], ['Soft Beige', '#E8CFC1'], ['Milky White', '#F4EFE8'],
				['Chrome Silver', '#C0C4CA'], ['Gold Shimmer', '#C9A227']
			]
		}
	];
	var FASHION_PALETTE = [
		{ group: 'Neutrals', colors: [
			['Black', '#111111'], ['White', '#FAFAFA'], ['Cream', '#F3EBDD'], ['Beige', '#D9C3A5'],
			['Heather Gray', '#B8B8B8'], ['Chocolate Brown', '#5B3A29']
		] },
		{ group: 'Soft & pastel', colors: [
			['Baby Pink', '#F4C2C2'], ['Lilac', '#C8A2C8'], ['Baby Blue', '#A7C7E7'],
			['Sage Green', '#9CAF88'], ['Butter Yellow', '#F3E5AB'], ['Peach', '#FFCBA4']
		] },
		{ group: 'Bold', colors: [
			['Hot Pink', '#E91E63'], ['Cherry Red', '#B00020'], ['Burgundy', '#6D1A2C'],
			['Cobalt', '#1E4FD8'], ['Navy', '#14213D'], ['Denim Blue', '#5A7FA8'], ['Olive', '#6B6A3A']
		] }
	];


	function rgb(hex) {
		var h = normalizeHex(hex) || '#000000';
		return [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
	}

	/* Exact palette hit, else the closest shade in it, else "Custom color".
	 * "Deep raspberry" reads better in a prompt than a bare hex code. */
	/* Shades you starred, shown first in every picker. */
	var FAV_KEY = 'spg_fav_colors_v1';

	function favColors() {
		var list = lsGet(FAV_KEY, []);
		return (Array.isArray(list) ? list : []).map(normalizeHex).filter(Boolean).slice(0, 24);
	}

	function favToggle(hex) {
		var h = normalizeHex(hex);
		if (!h) return false;
		var list = favColors();
		var at = list.indexOf(h);
		if (at > -1) list.splice(at, 1);
		else list.unshift(h);
		lsSet(FAV_KEY, list);
		return at === -1;
	}

	function colorName(hex, palette, exactOnly) {
		palette = palette || PALETTE;
		var want = rgb(hex);
		var best = null;
		var bestD = Infinity;
		for (var i = 0; i < palette.length; i++) {
			for (var j = 0; j < palette[i].colors.length; j++) {
				var c = palette[i].colors[j];
				if (c[1] === hex) return c[0];
				var o = rgb(c[1]);
				var d = (want[0] - o[0]) * (want[0] - o[0]) * 0.3 +
					(want[1] - o[1]) * (want[1] - o[1]) * 0.59 +
					(want[2] - o[2]) * (want[2] - o[2]) * 0.11;
				if (d < bestD) { bestD = d; best = c[0]; }
			}
		}
		if (exactOnly) return 'Custom color';
		/* Roughly "you would call it the same color". */
		return bestD < 900 ? best : 'Custom color';
	}

	/* ------------------------------------------------------------------ *
	 * UI building blocks (HTML strings)
	 * ------------------------------------------------------------------ */

	function card(title, body, headRight) {
		return '<fieldset class="spg-card"><legend><span>' + esc(title) + '</span>' + (headRight || '') + '</legend>' + body + '</fieldset>';
	}

	function seg(name, value, options) {
		return '<div class="spg-seg" role="radiogroup">' + options.map(function (o) {
			return '<label><input type="radio" name="' + name + '" value="' + esc(o[0]) + '"' + (value === o[0] ? ' checked' : '') + '>' +
				'<span>' + esc(o[1]) + (o[2] ? ' <small>' + esc(o[2]) + '</small>' : '') + '</span></label>';
		}).join('') + '</div>';
	}

	function choices(name, value, options) {
		return '<div class="spg-choices spg-choices-' + options.length + '">' + options.map(function (o) {
			return '<label class="spg-choice"><input type="radio" name="' + name + '" value="' + esc(o[0]) + '"' + (value === o[0] ? ' checked' : '') + '>' +
				'<span><strong>' + esc(o[1]) + '</strong><em>' + esc(o[2]) + '</em></span></label>';
		}).join('') + '</div>';
	}

	function yesNo(name, value) {
		return '<div class="spg-yn">' +
			'<label><input type="radio" name="' + name + '" value="false" data-bool' + (!value ? ' checked' : '') + '><span>No</span></label>' +
			'<label><input type="radio" name="' + name + '" value="true" data-bool' + (value ? ' checked' : '') + '><span>Yes</span></label>' +
			'</div>';
	}

	function textInput(name, value, placeholder) {
		return '<input type="text" name="' + name + '" value="' + esc(value) + '" placeholder="' + esc(placeholder || '') + '">';
	}

	function textArea(name, value, rows, placeholder) {
		return '<textarea name="' + name + '" rows="' + rows + '" placeholder="' + esc(placeholder || '') + '">' + esc(value) + '</textarea>';
	}

	function check(name, value, label) {
		return '<label class="spg-check"><input type="checkbox" name="' + name + '"' + (value ? ' checked' : '') + '><span>' + label + '</span></label>';
	}

	/* Text box with a fixed prefix (e.g. "A glimpse of") and a one-click clear (×) button. */
	function prefixField(name, value, prefix, placeholder, suffix) {
		return '<div class="spg-prefix">' +
			'<span>' + esc(prefix) + '</span>' +
			textInput(name, value, placeholder) +
			'<button type="button" class="spg-clear" data-clear="' + name + '" title="Erase" aria-label="Erase"' + (value ? '' : ' hidden') + '>×</button>' +
			'</div>' +
			(suffix ? '<p class="spg-hint spg-suffix-note">Always ends with <strong>“…' + esc(suffix) + '”</strong></p>' : '');
	}

	/* Drop a pasted "A glimpse of" so the fixed prefix isn't doubled. */
	function stripGlimpse(t) {
		return String(t || '').replace(/^\s*a glimpse of\s+/i, '').trim();
	}

	function sub(label) { return '<span class="spg-sub">' + esc(label) + '</span>'; }

	function showIf(cond, html) { return '<div data-show-if="' + cond + '">' + html + '</div>'; }

	function locked(label, text) {
		return '<p class="spg-locked"><span class="spg-lock">' + esc(label) + '</span>' + esc(text) + '</p>';
	}

	/* Color picker bound to state[key]. Several can live on one screen (lipstick, blush, outfit, nails). */
	function swatchRow(colors) {
		return '<div class="spg-swatches">' + colors.map(function (c) {
			var title = c[0] + ' ' + c[1];
			return '<button type="button" class="spg-swatch" data-swatch="' + c[1] + '" style="background-color:' + c[1] + ' !important" title="' + esc(title) + '" aria-label="' + esc(title) + '"></button>';
		}).join('') + '</div>';
	}

	/* Color picker bound to state[key]. Starred shades come first; the rest of
	 * the palette folds away so a card with three pickers stays readable. */
	function colorPicker(s, fallback, palette, key, label) {
		key = key || 'color';
		var pal = palette || PALETTE;
		var hex = normalizeHex(s[key]) || fallback;
		var favs = favColors();
		var fav = favs.length
			? '<p class="spg-swatch-group">My colors</p>' +
				swatchRow(favs.map(function (h) { return [colorName(h, pal), h]; }))
			: '';
		var first = pal[0]
			? '<p class="spg-swatch-group">' + esc(pal[0].group) + '</p>' + swatchRow(pal[0].colors)
			: '';
		var rest = pal.slice(1).map(function (g) {
			return '<p class="spg-swatch-group">' + esc(g.group) + '</p>' + swatchRow(g.colors);
		}).join('');
		if (rest) {
			rest = '<details class="spg-more-colors"><summary>More colors</summary>' + rest + '</details>';
		}
		return '<div class="spg-color" data-color="' + key + '">' +
			sub(label || 'Nail polish color') +
			'<div class="spg-color-row">' +
			'<input type="color" data-color-picker value="' + esc(hex) + '" aria-label="Pick color">' +
			'<input type="text" name="' + key + '" data-color-text value="' + esc(s[key]) + '" maxlength="7" spellcheck="false" aria-label="Hex code">' +
			'<button type="button" class="spg-fav" data-fav title="Save this color" aria-label="Save this color">★</button>' +
			'<span class="spg-color-name" data-color-name></span>' +
			'</div>' + fav + first + rest +
			'<p class="spg-hint spg-err" data-color-err hidden>Enter a valid hex code, like #BC9CB0.</p>' +
			'</div>';
	}

	/* Dropdown. options: [value, label] pairs, or {group, items:[[value, label], …]} */
	function select(name, value, options) {
		function opt(o) {
			return '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
		}
		return '<div class="spg-select"><select name="' + name + '">' + options.map(function (o) {
			return o.group ? '<optgroup label="' + esc(o.group) + '">' + o.items.map(opt).join('') + '</optgroup>' : opt(o);
		}).join('') + '</select></div>';
	}

	/* Yes/No accessory card with description + "match reference image". */
	function jewelryCard(s, key, label, placeholder, matchLabel) {
		return card(label,
			showIf(key + '=true',
				textInput(key + 'Text', s[key + 'Text'], placeholder) +
				check(key + 'Match', s[key + 'Match'], matchLabel)) +
			showIf(key + '=false', '<p class="spg-hint">No ' + esc(label.toLowerCase()) + '.</p>'),
			yesNo(key, s[key]));
	}

	function matchRef(what, single) {
		return 'Match the ' + what + ' exactly to the ' + (single ? 'one' : 'ones') + ' shown in the reference image (same design, material, color, size and placement).';
	}

	function textTools(target) {
		return '<div class="spg-actions">' +
			'<button type="button" class="spg-btn spg-btn-primary" data-tt="paste" data-target="' + target + '">Erase &amp; paste new</button>' +
			'<button type="button" class="spg-btn" data-tt="clear" data-target="' + target + '">Erase</button>' +
			'<button type="button" class="spg-btn" data-tt="restore" data-target="' + target + '">Restore default</button>' +
			'</div><p class="spg-hint" data-tt-msg="' + target + '"></p>';
	}

	function chips(examples) {
		return '<div class="spg-chips">' + examples.map(function (ex) {
			return '<button type="button" class="spg-chip" data-ai-example="' + esc(ex) + '">' + esc(ex) + '</button>';
		}).join('') + '</div>';
	}

	/* Compact AI refine row under a field: refines the field's own text. */
	function aiInline(kind, target, examples) {
		var st = aiStatus();
		var dis = st.ok ? '' : ' disabled';
		return '<div class="spg-ai-inline" data-ai data-kind="' + kind + '" data-target="' + target + '">' +
			(examples && examples.length ? chips(examples) : '') +
			'<div class="spg-actions">' +
			'<button type="button" class="spg-btn spg-btn-ai" data-ai-run' + dis + '>✦ Refine with AI</button>' +
			'<button type="button" class="spg-btn" data-ai-variants' + dis + '>✦ 3 options</button>' +
			'<button type="button" class="spg-btn" data-ai-undo hidden>Undo</button>' +
			'</div>' +
			'<div class="spg-ai-options" data-ai-options hidden></div>' +
			'<p class="spg-hint" data-ai-msg>' + st.note + '</p>' +
			'</div>';
	}

	/* Full AI panel with its own idea box that writes into a target field. */
	function aiPanel(kind, target, ideaName, ideaValue, examples, lead, placeholder, title, runLabel) {
		var st = aiStatus();
		var dis = st.ok ? '' : ' disabled';
		return '<div class="spg-ai" data-ai data-kind="' + kind + '" data-target="' + target + '" data-idea="' + ideaName + '">' +
			'<div class="spg-ai-head">' +
			'<span class="spg-ai-badge">AI</span>' +
			'<span class="spg-ai-title">' + esc(title || 'Pose assistant') + '</span>' +
			'<span class="spg-ai-model">Groq · ' + esc(CONFIG.model || 'openai/gpt-oss-120b') + '</span>' +
			'</div>' +
			'<p class="spg-ai-lead">' + esc(lead) + '</p>' +
			'<textarea name="' + ideaName + '" rows="3" placeholder="' + esc(placeholder) + '"' + dis + '>' + esc(ideaValue) + '</textarea>' +
			chips(examples) +
			'<div class="spg-actions">' +
			'<button type="button" class="spg-btn spg-btn-primary" data-ai-run' + dis + '>' + esc(runLabel || '✦ Refine pose with AI') + '</button>' +
			'<button type="button" class="spg-btn" data-ai-variants' + dis + '>✦ 3 options</button>' +
			'<button type="button" class="spg-btn" data-ai-undo hidden>Undo</button>' +
			'</div>' +
			'<div class="spg-ai-options" data-ai-options hidden></div>' +
			'<p class="spg-hint" data-ai-msg>' + st.note + '</p>' +
			'</div>';
	}

	/* Saved library block (poses or setups). */
	function libBlock(kind, title, saveLabel, placeholder) {
		return '<div class="spg-saved" data-lib="' + kind + '">' +
			'<div class="spg-saved-head">' +
			'<span class="spg-saved-title">' + esc(title) + ' <span class="spg-saved-count" data-lib-count></span></span>' +
			'<button type="button" class="spg-btn" data-lib-open>★ ' + esc(saveLabel) + '</button>' +
			'</div>' +
			'<div class="spg-save-form" data-lib-form hidden>' +
			'<input type="text" data-lib-name maxlength="80" placeholder="' + esc(placeholder) + '">' +
			'<button type="button" class="spg-btn spg-btn-primary" data-lib-save>Save</button>' +
			'<button type="button" class="spg-btn" data-lib-cancel>Cancel</button>' +
			'</div>' +
			'<div class="spg-actions spg-lib-io">' +
			'<button type="button" class="spg-btn spg-btn-mini" data-lib-export>⤓ Export</button>' +
			'<button type="button" class="spg-btn spg-btn-mini" data-lib-import>⤒ Import</button>' +
			'<input type="file" accept="application/json,.json" data-lib-file hidden>' +
			'</div>' +
			'<input type="text" class="spg-saved-filter" data-lib-filter placeholder="Search…" hidden>' +
			'<ul class="spg-saved-list" data-lib-list><li class="spg-saved-empty">Loading…</li></ul>' +
			'<p class="spg-hint" data-lib-msg></p>' +
			'</div>';
	}

	/* ------------------------------------------------------------------ *
	 * One-click erase (×) on every text box
	 *
	 * Runs after each render and puts a small × inside every text field and
	 * textarea of the open generator, whichever tool it is (Sole, Hand, Feet,
	 * Selfie …). Skipped for boxes that already carry their own × (the prefix
	 * fields), for the hex code box (it must always hold a valid color) and for
	 * disabled fields.
	 * ------------------------------------------------------------------ */

	function canErase(el) {
		if (el.disabled || el.readOnly) return false;
		if (el.hasAttribute('data-color-text')) return false;
		if (el.closest('.spg-prefix') || el.closest('.spg-box')) return false;
		return true;
	}

	function addEraseButtons(scope) {
		scope.querySelectorAll('input[type="text"], input[type="search"], textarea').forEach(function (el) {
			if (!canErase(el)) return;

			var box = document.createElement('div');
			box.className = 'spg-box' + (el.tagName === 'TEXTAREA' ? ' spg-box-area' : '');
			el.parentNode.insertBefore(box, el);
			box.appendChild(el);

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'spg-erase';
			btn.title = 'Erase this box';
			btn.setAttribute('aria-label', 'Erase this box');
			btn.textContent = '×';
			btn.hidden = !el.value;
			box.appendChild(btn);

			btn.addEventListener('click', function () {
				el.value = '';
				btn.hidden = true;
				/* Let the normal listeners (state, preview, filters) do their work. */
				el.dispatchEvent(new Event('input', { bubbles: true }));
				el.dispatchEvent(new Event('change', { bubbles: true }));
				el.focus();
			});
			el.addEventListener('input', function () { btn.hidden = !el.value; });
		});
	}

	/* Keep every × in step with text set from elsewhere (AI, saved poses, reset). */
	function syncEraseButtons(scope) {
		scope.querySelectorAll('.spg-box').forEach(function (box) {
			var el = box.querySelector('input, textarea');
			var btn = box.querySelector('.spg-erase');
			if (el && btn) btn.hidden = !el.value;
		});
	}

	/* ================================================================== *
	 * SOLE generator
	 * ================================================================== */

	var SOLE_PART_1 = [
		'Part 1: Technical Reasoning & Camera Data (Keep Static)',
		'Technical Reasoning Instructions (The "Sole Realism Engine"):',
		'',
		'* Anatomy & Pressure Physics: Calculate skeletal structure of the metatarsals and phalanges. Crucial: Simulate foot-to-surface pressure. The skin on the pads, ball, and heel must show realistic, soft compression and flattened texture where making contact with the surface. The skin must look exactly like real female human skin — soft, natural, detailed, and lifelike, with authentic pores, texture, and tonal variation. Avoid any plastic, waxy, CGI, overly smooth, or unnatural appearance. ',
		'* Dermal Light Physics (Advanced SSS): Simulate deep Subsurface Scattering specifically for foot soles. Light must penetrate the soft dermal layers, creating the distinct, healthy "reddish-pink flush (erythema)" indicating active blood flow and warmth beneath the surface. This flush must appear soft and organic, not like surface paint.',
		'* Keratin Material Science (The Underside): The visible underside of the extremely long protruding toenails must be rendered as matte, opaque, and fibrous natural keratin. It must show micro-ridges and a clean, healthy hyponychium (the seal where the nail bed meets the skin).',
		'* High-Frequency Texture Mapping: Disable all smoothing and beauty filters. Prioritize raw detail. Treat fine dermatoglyphics (footprint lines), sweat pores, and the velvet-like texture of callus-free skin as essential.',
		'* Zero-Defect Constraint: Absolutely zero thickened skin, dryness, cracking, or yellowing. The soles must appear uniformly soft, plump, and healthy.',
		'',
		'Camera & Sensor Data (The Macro Clarity Set):',
		'',
		'* Device: Simulated Phase One IQ4 150MP Medium Format.',
		'* Lens: Rodenstock HR Digaron-W 90mm f/5.6 Macro (Zero distortion, ultra-sharp close-range).',
		'* Aperture: f/22 (Narrow aperture for deep depth of field, ensuring both the sole surface and the extended nail tips are in sharp focus).',
		'* Lighting: Soft, diffused studio lighting (to mimic natural everyday light) that highlights texture without harsh shadows.',
		'* Color Science: Uncompressed RAW (.IIQ), SOOC (Straight Out Of Camera), flat neutral profile, zero post-processing.'
	].join('\n');

	var SOLE_PART_2_HEAD = [
		'Part 2: Subject, Pose & Composition (Variables)',
		'',
		'* [CRITICAL GEOMETRY LOCK]: You must reference the provided images. The generation must preserve the EXACT extreme length, thickness, and natural curvature shape of the toenails as seen from the underside. They must remain exceptionally long and un-shortened. This is a non-negotiable identity feature. The nails should appear highly detailed, with no shaky edges or motion blur. Every nail edge needs to look stable, sharp, and crystal clear.',
		'',
		'',
		'Perspective & POV: Macro close-up, eye-level with the soles of the feet.',
		' Poses/Compositions:'
	].join('\n');

	var SOLE_VIBE = [
		'Vibe & Aesthetic:',
		'',
		'* Ultra-realistic documentary photography. "Tactile realism." Crystal clear, zero grain, high definition. Colors are organic, true-to-life, and slightly flat (RAW aesthetic), not overly saturated.'
	].join('\n');

	var SOLE_NAIL_A = 'Completely Natural/Bare. The underside of the extremely long protruding nails is bare. Render the raw, fibrous, matte texture of the natural keratin, showing the distinct transition at the hyponychium.';

	function soleNailB(hex) {
		return 'The top surface of the long nails is painted Deep Glossy nail polish (Nail polish color: ' + hex +
			'), but the underside visible to the camera remains bare with a refraction of ' + hex + ' color';
	}

	/* ------------------------------------------------------------------ *
	 * Anatomy & physics lock
	 *
	 * Added to POSE DETAIL for every pose, whether it comes from the pose
	 * library, the AI assistant or your own typing. This is what keeps the
	 * feet on the reference anatomy no matter how the pose changes.
	 * ------------------------------------------------------------------ */

	var SOLE_ANATOMY_LOCK = [
		'Use the provided image of my soles as the primary anatomical reference. Preserve the reference anatomy accurately, including the natural proportions and structure of the legs, ankles, feet, soles, toes, and toenails.',
		'',
		'Maintain anatomically correct relationships between the bones, joints, tendons, muscles, toes, and toenails. The toenails should follow the natural curvature, thickness, growth direction, and attachment of the reference. Preserve realistic ankle structure, heel shape, arch curvature, toe alignment, and overall foot proportions without distortion or anatomical exaggeration.',
		'',
		'Apply realistic physical behavior throughout the scene. Foot-to-surface contact, weight distribution, pressure, skin compression, soft-tissue deformation, toe positioning, and joint angles should follow real-world biomechanics. Areas bearing weight should show appropriate compression and subtle changes in skin texture and shape, while unsupported areas should retain their natural form.',
		'',
		'The soles, skin, toenails, and surrounding anatomy must remain consistent with the reference image throughout the generation. Avoid warped toes, duplicated anatomy, unnatural joints, floating feet, inconsistent nail placement, unrealistic flexibility, or proportions that contradict the reference.'
	].join('\n');

	/* Always sits between the pose and the anatomy lock. */
	var SOLE_NEW_POSE_LOCK = 'Build the pose described above as a completely new pose. Do not copy the pose, the leg arrangement, the camera angle or the framing of the reference image — the reference images are used only as the anatomical reference for my feet, legs and toenails.';

	/* ------------------------------------------------------------------ *
	 * Pose library — 50 ready poses
	 *
	 * Pose only: body position, which leg is where, how the soles face the
	 * camera and what the toes do. Nothing about nails, clothing, jewelry,
	 * surface, background or camera gear — those have their own fields.
	 * The anatomy lock above is added to every one of them automatically.
	 * ------------------------------------------------------------------ */

	var SOLE_POSE_GROUPS = [
		{ group: 'On my back — both soles to the camera', items: [
			['p01', '1 · Both soles raised, one slightly higher'],
			['p02', '2 · One sole closer to the camera'],
			['p03', '3 · One foot high, one foot low'],
			['p04', '4 · Both soles touching side by side'],
			['p05', '5 · Small gap between both soles'],
			['p08', '8 · Feet forming a soft V'],
			['p09', '9 · Feet angled inward'],
			['p10', '10 · One sole turned slightly sideways'],
			['p12', '12 · One sole above the other'],
			['p13', '13 · Feet gently overlapping'],
			['p16', '16 · One sole very close, one distant'],
			['p28', '28 · Side by side, seen from below'],
			['p44', '44 · Both feet, one toe line higher'],
			['p45', '45 · Relaxed asymmetrical double sole'],
			['p47', '47 · Both soles, toes pointing inward'],
			['p48', '48 · Feet separated vertically'],
			['p49', '49 · One foot tucked behind the other'],
			['p50', '50 · Relaxed spontaneous pose']
		] },
		{ group: 'Crossed & overlapping', items: [
			['p06', '6 · Crossed ankles, both soles visible'],
			['p07', '7 · One sole completely in front of the other'],
			['p11', '11 · One foot horizontally across the other'],
			['p19', '19 · One foot crossing diagonally over the other'],
			['p20', '20 · Both feet crossed in opposite directions'],
			['p21', '21 · One foot resting across the opposite leg'],
			['p39', '39 · Legs extended with crossed feet'],
			['p41', '41 · Close-up crossed sole']
		] },
		{ group: 'Sole to sole', items: [
			['p17', '17 · Soles facing each other'],
			['p18', '18 · Sole-to-sole pose'],
			['p24', '24 · Feet creating an arch']
		] },
		{ group: 'One foot featured', items: [
			['p14', '14 · One foot pointing upward'],
			['p15', '15 · One sole angled toward the ceiling'],
			['p22', '22 · Feet close to my face'],
			['p23', '23 · One sole beside my body'],
			['p25', '25 · One sole with toes slightly curled'],
			['p26', '26 · Pointed foot, sole partly visible'],
			['p27', '27 · Strong diagonal sole'],
			['p40', '40 · One foot emerging from beneath the legs'],
			['p46', '46 · One sole turned toward the inner side']
		] },
		{ group: 'Lying on my stomach', items: [
			['p33', '33 · Both soles toward the camera'],
			['p34', '34 · One sole higher'],
			['p35', '35 · Crossed lower legs'],
			['p36', '36 · One leg horizontal across the other']
		] },
		{ group: 'On my side & legs extended', items: [
			['p37', '37 · Side-lying single sole'],
			['p38', '38 · Side-lying crossed ankles']
		] },
		{ group: 'Wall & edge of the bed', items: [
			['p29', '29 · Feet raised against a wall'],
			['p30', '30 · One foot touching the wall'],
			['p31', '31 · Feet hanging over the edge'],
			['p32', '32 · One foot hanging lower']
		] },
		{ group: 'Filling the frame', items: [
			['p42', '42 · Both soles filling the frame'],
			['p43', '43 · One sole dominating the frame']
		] },
		{ group: 'Other', items: [
			['', 'No preset pose — I write my own']
		] }
	];

	var SOLE_POSE_TEXT = {
		p01: 'I am lying comfortably on my back with both knees bent upward and both lower legs raised toward the camera. I hold my feet close together, with my right foot lifted slightly higher and set a little farther back than my left foot. Both soles face the camera almost straight on, heels low in the frame and toes pointing upward. My ankles stay relaxed and naturally in line with my lower legs, and the toes of both feet are gently spread.',
		p02: 'I am lying on my back with both knees bent and both feet raised toward the camera. I push my left foot much closer to the lens while my right foot stays farther behind it. The near left sole fills the foreground and reads noticeably larger, while the whole right sole stays visible behind it and slightly higher. Both feet face the camera without twisting the legs, and the toes of both feet stay relaxed and separated.',
		p03: 'I am lying on my back with both knees bent upward. I raise my right lower leg higher while my left lower leg stays lower and farther from the camera. My right sole faces the camera almost straight on; my left sole sits lower in the frame and is fully visible as well. The two feet form a natural diagonal across the frame rather than a matched pair, with the toes of both feet softly relaxed.',
		p04: 'I am lying on my back with both knees bent and both feet raised toward the camera. I bring my feet together side by side until the inner edges of my soles touch lightly. Both soles face the camera straight on, the heels close together near the bottom of the frame and the toes fanning slightly apart at the top. My legs stay relaxed and evenly raised, with only the smallest natural difference in height between the two feet.',
		p05: 'I am lying on my back with both knees raised and both feet lifted toward the camera. I hold my feet almost parallel, leaving a narrow gap of a few centimetres between the two soles. Both soles face the camera equally, but each foot carries a slightly different natural toe angle so the pose does not look mechanically symmetrical. My heels sit low in the frame and my toes point upward, gently spread.',
		p06: 'I am lying on my back with both knees bent upward. I cross my lower legs loosely at the ankles, my left foot resting slightly in front of my right. I rotate both feet outward at the ankle joints so both soles stay turned toward the camera, the front sole overlapping the lower inner edge of the back sole. The crossing happens only at the ankles, and both legs keep their correct left and right anatomy.',
		p07: 'I am lying on my back with both legs raised toward the camera. I bring my left foot directly in front of my right foot so the left sole becomes the main foreground subject, seen completely from heel to toes. My right sole stays visible behind it, a little higher and smaller because it is farther away. Both heels stay correctly joined to their ankles and the toes of both feet point naturally upward.',
		p08: 'I am lying on my back with both knees bent and my feet raised toward the camera. I separate my feet and rotate them outward at the ankles so the two soles open into a soft V shape. The inner edges of my feet angle toward each other near the heels while the toes spread apart at the top of the frame. Both soles stay clearly visible to the camera, with the toes relaxed rather than tensed.',
		p09: 'I am lying on my back with both knees raised and both feet lifted toward the camera. I rotate both feet inward at the ankles so the toes tilt toward each other while the heels stay apart. The soles remain turned toward the camera, showing their full shape, and the inward tilt reads as a playful mirrored angle. The movement comes only from my ankles, and my knees and lower legs stay naturally aligned.',
		p10: 'I am lying on my back with both legs raised toward the camera. My left sole faces the camera almost straight on, while my right foot is rotated outward at the ankle so the camera sees more of its outer edge and the curve of its arch. Both legs stay naturally aligned through the knees and ankles, and the toes of both feet stay relaxed and slightly separated.',
		p11: 'I am lying on my back with both knees bent. I lay my left lower leg diagonally across the front of my right lower leg, so the two shins form a loose X. My left foot sits higher and is rotated outward so its sole faces the camera, and my right sole stays visible underneath and slightly behind it. Both legs keep correct left and right anatomy even where they overlap.',
		p12: 'I am lying on my back with both lower legs raised almost vertically. I hold my right foot clearly above my left foot, one over the other rather than side by side. Both soles face the camera; the upper sole reads slightly smaller because it is farther away, and the lower sole sits larger in the foreground. My ankles stay relaxed and the toes of both feet point upward, softly spread.',
		p13: 'I am lying on my back with both knees bent and my feet lifted toward the camera. I move my feet close enough that the upper part of one sole overlaps the lower part of the other. Both soles stay recognisable and readable, the front foot slightly nearer the lens and the rear foot partly visible behind it. The overlap comes from the natural distance between my feet, not from bending either foot.',
		p14: 'I am lying on my back with both knees bent. I extend my right lower leg upward and let my right foot point naturally toward the ceiling while its sole stays turned toward the camera. My left leg stays bent lower and farther behind, its foot partly visible in the frame. The toes of my right foot stay relaxed and separated, and my right ankle keeps a natural, unforced angle.',
		p15: 'I am lying on my back with one knee raised higher than the other. I lift my right foot so the heel drops slightly downward and the toes rise upward, exposing the whole sole to the camera at a clear diagonal. My left foot stays lower and relaxed beside it, partly in frame. The angle comes from my ankle and knee, and my toes stay naturally spread rather than curled.',
		p16: 'I am lying on my back and extend both feet toward the camera. I bring my left foot much closer to the lens than my right, so the left sole fills most of the foreground while the right sole reads clearly smaller in the background. Both feet stay naturally connected through their ankles and lower legs, and the difference in size comes from real distance rather than from changing either foot.',
		p17: 'I am lying on my back with both knees drawn up toward my chest. I raise both feet and rotate them inward at the ankles so the two soles turn toward each other rather than fully toward the camera. The inner edges of the soles almost meet, leaving a small open gap between them, and the camera still sees most of both soles at an angle. My toes stay relaxed and gently spread.',
		p18: 'I am lying on my back with both knees raised. I bring my feet together until the two soles rest flat against each other, heel to heel and ball to ball. My heels point down toward my ankles and my toes point upward, forming a clean symmetrical sole-to-sole shape. The camera sees the outer edges of both feet and the line where the soles meet, with the skin softly compressed where they press together.',
		p19: 'I am lying on my back with both legs raised. I cross my right lower leg diagonally over my left lower leg, so my right foot sits higher and slightly to one side with its sole turned toward the camera. My left sole stays visible underneath, lower in the frame and slightly farther away. The crossing happens between the knees and the ankles only, and both legs keep correct left and right anatomy.',
		p20: 'I am lying on my back with my knees bent and both lower legs raised. I cross my ankles loosely and let the two feet settle at different angles: one sole faces the camera almost straight on, the other is rotated sideways at the ankle so the camera sees its edge and part of the arch. The result is deliberately asymmetrical, and my toes stay relaxed on both feet.',
		p21: 'I am lying on my back with my left knee bent upward. I lay my right foot across the lower part of my left shin and rotate my right foot outward so its whole sole faces the camera, with the skin softly compressed where it rests on my leg. My left foot stays lower and relaxed behind it, partly visible. Both legs keep their natural left and right anatomy at the point of contact.',
		p22: 'I am lying on my back with both knees bent strongly toward my chest. I raise both feet up toward my face and hold them apart on either side of the space above my body. Both soles face the camera, framing the space between them. My knees stay wide enough that the feet do not touch each other, my ankles stay relaxed, and the toes of both feet are gently spread.',
		p23: 'I am lying on my back with one knee raised high. I bring that foot up beside my upper body while my other leg stays bent lower and farther away. I rotate the raised foot naturally at the ankle so the camera sees its complete sole from heel to toes. The second foot stays partly visible in the background, lower in the frame, and both feet keep relaxed, naturally separated toes.',
		p24: 'I am lying on my back with both legs raised and my knees bent. I bring my feet inward until the toes of both feet almost meet while the heels stay clearly apart. Both soles angle inward and slightly toward the camera, so the gap between the two feet forms a soft arch shape. The rotation comes from my ankles, and my toes stay relaxed rather than pointed or curled.',
		p25: 'I am lying on my back with one leg raised toward the camera. I let my ankle relax and curl the toes of that foot gently inward, while the sole stays fully visible from heel to toe tips. The curl is soft and natural, creating light creases across the ball of the foot. My other leg stays bent lower and mostly in the background, its foot only partly in frame.',
		p26: 'I am lying on my back with one leg extended upward. I point that foot away from the ankle, the way a dancer would, while still keeping enough of the sole turned toward the camera to show the heel, the arch and the line of the toes. The point comes from the ankle; the lower leg stays straight and naturally aligned. My other leg stays lower and farther back in the frame.',
		p27: 'I am lying on my back with one knee bent outward. I raise that foot diagonally across my body and rotate it slightly inward at the ankle, so the camera sees the whole sole at an angle rather than flat on: the heel, the arch, the ball of the foot and all five toes are each visible in sequence. My other leg stays lower and relaxed, partly visible behind the raised foot.',
		p28: 'I am lying on my back with both knees bent and both feet raised above my body. I keep the two feet beside each other with a small gap between them. The camera looks up from below and slightly ahead, so both soles point down toward the lens with the toes visible along the upper edge of each sole. My ankles stay relaxed and my toes are softly spread.',
		p29: 'I am lying on my back close to a vertical wall. I raise both bent legs until my feet come near the wall surface, keeping my lower legs almost upright. I rotate both feet slightly outward at the ankles so the camera still sees both soles rather than only the edges of my feet. My toes stay relaxed, and my legs keep a natural bend at the knees rather than locking straight.',
		p30: 'I am lying on my back with both knees bent upward. I extend one leg farther until that sole presses gently flat against the wall, the skin softly compressing where it makes contact, while my second leg stays bent beside it. The foot on the wall faces mostly toward the camera and the second sole stays partly visible behind it. My toes stay relaxed and naturally spread on both feet.',
		p31: 'I am lying on my back near the edge of a bed with both knees bent. I lift both feet out past the edge and let my lower legs rise toward the camera, with nothing beneath my feet. Both soles face outward toward the lens, one foot slightly closer and lower than the other. My ankles stay relaxed, my toes are gently spread, and my calves rest lightly against the edge.',
		p32: 'I am lying on my back at the edge of a bed. I raise one foot toward the camera while the other foot hangs lower and farther away, unsupported. The raised sole is fully visible from heel to toes; the lower foot sits behind it and adds depth to the frame. My ankles stay loose, the hanging foot relaxes naturally downward, and both sets of toes stay softly separated.',
		p33: 'I am lying flat on my stomach with my chest and thighs resting on the surface. I bend both knees so both lower legs rise into the air behind me, and I rotate only at the ankle joints so both soles turn back toward the camera. My heels stay joined to my ankles and my toes stay at the far end of each foot. The two feet sit at slightly different heights, with relaxed toes.',
		p34: 'I am lying flat on my stomach with both knees bent behind me. I raise my right lower leg higher than my left and bring both feet back toward the camera. My right sole sits higher and slightly farther away, my left sole lower and closer to the lens. Both soles stay correctly oriented with the toes above the heels, and the turn comes only from my ankles.',
		p35: 'I am lying flat on my stomach with both thighs on the surface and both knees bent upward. I cross one lower leg loosely over the other near the ankles. Both feet rotate naturally at their ankle joints toward the camera so both soles stay visible, one partly overlapping the other. Neither leg twists or reverses; the crossing is a simple resting of one shin over the other.',
		p36: 'I am lying flat on my stomach with my knees bent behind me. I keep my left lower leg raised upward while my right lower leg crosses horizontally in front of it, forming a loose T. Each foot stays attached naturally to its own ankle, and I turn both feet outward enough that the camera sees both soles. My toes stay relaxed, and my weight rests evenly on my chest and thighs.',
		p37: 'I am lying on my side with both knees slightly bent. I bring my upper leg forward, in front of the lower one, and lift its foot toward the camera. I rotate only that foot at the ankle so its sole faces diagonally outward and is fully visible. My lower leg stays relaxed underneath, its foot only just in frame. The toes of the raised foot stay softly spread.',
		p38: 'I am lying on my side with my legs gently crossed near the ankles, the upper foot resting over the lower one. I lift the upper foot slightly and rotate it outward so its sole turns toward the camera from heel to toes. The lower foot stays underneath and only its outer edge and a part of its sole are visible. Both feet keep correct left and right anatomy.',
		p39: 'I am lying comfortably with both legs extended away from my body and my knees almost straight. I cross one ankle loosely over the other near the feet and angle both feet outward at the ankles. One sole faces the camera more fully than the other, giving a relaxed asymmetric composition. My toes stay soft and slightly spread, and neither foot is pointed or flexed hard.',
		p40: 'I am lying comfortably with my legs folded and one foot tucked underneath the opposite leg. I slide that hidden foot forward and rotate it at the ankle until its sole becomes visible beside the other leg, with the skin lightly compressed where the leg rests against it. The pose reads as relaxed and unforced rather than stretched, and the toes of the freed foot stay naturally relaxed.',
		p41: 'I bring one raised foot very close to the camera while my second foot crosses behind it at the ankle. The foreground sole is fully visible from heel to toe tips and fills much of the frame; the second sole appears partly behind it and slightly lower. My ankles and lower legs stay naturally connected outside the foreground area, and the toes of both feet stay relaxed and separated.',
		p42: 'I raise both feet directly toward the camera and bring them close together so that both soles fill most of the frame, heels near the bottom edge and toes near the top. The feet stay roughly parallel but carry small natural differences in angle and in the spacing of the toes, so the pair does not look duplicated. My ankles stay relaxed and both soles read clearly from heel to toes.',
		p43: 'I extend one leg toward the camera much farther than the other, so the near foot becomes the dominant foreground subject and shows the complete sole in sequence: heel, arch, ball of the foot and all five toes. My second foot stays farther behind, smaller and partly visible at the edge of the near foot. Both legs stay naturally connected through the ankles and knees.',
		p44: 'I raise both feet toward the camera with one foot held slightly higher than the other. The higher foot has its toes near the top of the frame while the lower foot sits underneath it, its own toes a little lower. Both soles stay fully visible and naturally aligned with their ankles, and the toes of both feet are relaxed and gently separated rather than pressed together.',
		p45: 'I am lying comfortably with both knees bent and both feet raised toward the camera. Instead of matching the two feet, I let one ankle relax slightly inward and the other slightly outward, so each sole meets the camera at its own angle. Both soles stay clearly visible from heel to toes. The asymmetry is small and anatomically natural, as if my feet simply settled that way.',
		p46: 'I raise one leg toward the camera and rotate that foot inward at the ankle, so the inner edge of the sole and the inner arch become the most prominent part of the view. The heel stays directly in line with the ankle and the toes continue to point away from the ankle in their natural order. My other leg stays lower and farther back, only partly in frame.',
		p47: 'I raise both legs and bring my feet close together. I angle both feet inward from the ankles so the toes tilt slightly toward each other while the heels stay a little apart. The soles still face the camera enough to show their complete shape from heel to toes. My knees stay comfortably bent and the toes of both feet are relaxed and softly spread.',
		p48: 'I raise both legs and hold one foot directly above the other rather than side by side. The lower foot is nearer the camera and reads larger; the upper foot sits farther back and reads smaller. Both soles face outward toward the lens, and the two feet stay clearly separated with a visible gap between them. My ankles stay relaxed and my toes gently spread.',
		p49: 'I raise both feet toward the camera and tuck my right foot slightly behind my left. The left sole stays completely exposed in front from heel to toes, while the right sole peeks out from behind its outer edge, showing about half of its width. Both feet stay naturally connected through their ankles, and the toes of both feet stay relaxed rather than pressed together.',
		p50: 'I am lying comfortably with both knees bent and both feet lifted toward the camera. I let the two feet fall into slightly different heights and slightly different angles, with both soles visible and no forced symmetry. The pose should read as a spontaneous relaxed moment rather than an arranged foot pose, with soft ankles, naturally spread toes and an easy bend in both knees.'
	};

	/* ------------------------------------------------------------------ *
	 * Wardrobe: lower-body garments visible at the ankles
	 * ------------------------------------------------------------------ */

	var LOWER_OUTFITS = [
		{ group: 'Trousers & pants', items: [
			['a pair of black pants gathered near the ankle', 'Black pants at the ankle'],
			['a pair of wide-leg trousers whose hem breaks softly over the ankle', 'Wide-leg trousers'],
			['a pair of straight-leg tailored trousers with a crisp hem at the ankle', 'Tailored straight trousers'],
			['a pair of pleated palazzo pants falling loose around the ankles', 'Palazzo pants'],
			['a pair of cropped cigarette pants ending just above the ankle', 'Cropped cigarette pants'],
			['a pair of slim-fit jeans cuffed once above the ankle', 'Cuffed jeans'],
			['a pair of relaxed boyfriend jeans bunching over the ankle', 'Boyfriend jeans'],
			['a pair of flared denim jeans sweeping down past the ankle', 'Flared jeans'],
			['a pair of soft cotton joggers with an elasticated cuff at the ankle', 'Joggers with cuffs'],
			['a pair of ribbed knit lounge pants draping over the ankle', 'Knit lounge pants'],
			['a pair of glossy leggings hugging the calf and ending at the ankle', 'Leggings'],
			['a pair of loose linen drawstring trousers rolled up near the ankle', 'Linen drawstring trousers'],
			['a pair of satin pyjama pants with a soft flared hem at the ankle', 'Satin pyjama pants'],
			['a pair of cargo pants with a drawcord pulled in at the ankle', 'Cargo pants'],
			['a pair of leather pants tapering close to the ankle', 'Leather pants']
		] },
		{ group: 'Skirts & dresses', items: [
			['the hem of a long pleated maxi skirt gathering around the ankles', 'Pleated maxi skirt'],
			['the hem of a flowing A-line maxi dress pooling near the ankles', 'A-line maxi dress'],
			['the hem of a bias-cut satin slip dress falling to the ankle', 'Satin slip dress'],
			['the hem of a tiered cotton midi skirt just above the ankle', 'Tiered midi skirt'],
			['the hem of a wrap skirt with a high side slit opening at the ankle', 'Wrap skirt with slit'],
			['the hem of a denim maxi skirt with a frayed edge near the ankle', 'Denim maxi skirt'],
			['the hem of a knitted sweater dress ending mid-calf above the ankle', 'Knit sweater dress'],
			['the hem of a layered tulle skirt brushing the ankles', 'Layered tulle skirt']
		] },
		{ group: 'Indian & traditional', items: [
			['the pleats and pallu of a saree draping down to the ankles', 'Saree'],
			['the sheer, lightly transparent pleats of a saree falling to the ankles', 'Sheer saree'],
			['the hem of a lehenga skirt flaring wide around the ankles', 'Lehenga'],
			['the hem of a churidar fitted in fine gathers at the ankle', 'Churidar'],
			['the hem of a straight-cut salwar with a plain band at the ankle', 'Salwar'],
			['the hem of a patiala salwar falling in deep pleats at the ankle', 'Patiala salwar'],
			['the hem of an anarkali kurta sweeping around the ankles', 'Anarkali kurta'],
			['the hem of a cotton palazzo suit ending at the ankle', 'Palazzo suit'],
			['the hem of a sharara flaring dramatically around the ankles', 'Sharara'],
			['the folded hem of a cotton lungi resting near the ankles', 'Lungi']
		] },
		{ group: 'Short & sporty', items: [
			['a pair of denim shorts, the legs bare down to the ankles', 'Denim shorts'],
			['a pair of soft jersey lounge shorts, the legs bare to the ankles', 'Lounge shorts'],
			['a pair of athletic running shorts, the legs bare to the ankles', 'Running shorts'],
			['a pair of cycling shorts ending mid-thigh, the legs bare to the ankles', 'Cycling shorts'],
			['a pair of cotton boxers, the legs bare down to the ankles', 'Cotton boxers']
		] },
		{ group: 'Legwear', items: [
			['a pair of sheer nylon stockings pushed down and bunched at the ankle', 'Stockings at the ankle'],
			['a pair of ribbed cotton socks rolled down around the ankle', 'Rolled-down socks'],
			['a pair of slouchy knitted leg warmers bunched above the ankle', 'Leg warmers']
		] },
		{ group: 'Other', items: [
			['', 'Nothing visible — bare legs'],
			['custom', 'Custom… (type your own)']
		] }
	];

	/* ------------------------------------------------------------------ *
	 * Camera & framing
	 *
	 * Every generator gets the same three dropdowns — where the camera is,
	 * how close it is, and where the subject sits in the frame — plus a free
	 * text note. The option wording is different per generator because the
	 * subject is different (soles, a hand, feet from above, a selfie).
	 * ------------------------------------------------------------------ */

	/* pairs: [lead, phrase] — empty phrases are skipped. */
	function cameraLineFrom(pairs, note) {
		var parts = [];
		pairs.forEach(function (p) {
			if (p[1]) parts.push(p[0] + ' ' + p[1]);
		});
		if (note && note.trim()) parts.push(note.trim());
		if (!parts.length) return '';
		return parts.map(function (p) { return cap(sentence(p)); }).join(' ');
	}
	/* The three dropdowns + note + reset, as one card body. Labels are per tool. */
	function cameraFields(s, lists, labels) {
		return '<div class="spg-field">' + sub(labels[0]) + select('camHeight', s.camHeight, selectGroups(lists[0])) + '</div>' +
			'<div class="spg-field">' + sub(labels[1]) + select('camCrop', s.camCrop, selectGroups(lists[1])) + '</div>' +
			'<div class="spg-field">' + sub(labels[2]) + select('camPlacement', s.camPlacement, selectGroups(lists[2])) + '</div>' +
			'<div class="spg-field">' + sub('Anything else about the camera (optional)') +
			textInput('camNote', s.camNote, labels[3]) + '</div>' +
			camReset();
	}

	/* Every camera field of every generator, so one button can clear them all. */
	var CAM_KEYS = ['camHeight', 'camHeightThird', 'camCrop', 'camPlacement', 'camNote'];

	function camReset() {
		return '<div class="spg-actions spg-cam-reset">' +
			'<button type="button" class="spg-btn" data-reset-cam>↺ Reset camera</button>' +
			'</div><p class="spg-hint" data-reset-cam-msg></p>';
	}

	/* Row of quick-fill buttons. tool.presets[id] gets the state and changes it. */
	function presetRow(items) {
		return '<div class="spg-chips spg-presets">' + items.map(function (i) {
			return '<button type="button" class="spg-chip" data-preset="' + esc(i[0]) + '">' + esc(i[1]) + '</button>';
		}).join('') + '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Metals, stones and finishes — the palette behind every jewelry color
	 * ------------------------------------------------------------------ */

	var JEWEL_PALETTE = [
		{ group: 'Metals', colors: [
			['Yellow Gold', '#D4AF37'], ['Rose Gold', '#B76E79'], ['White Gold', '#E8E8E6'],
			['Silver', '#C0C4CA'], ['Oxidised Silver', '#6E7275'], ['Platinum', '#E5E4E2'],
			['Copper', '#B06A3B'], ['Brass', '#B5A642'], ['Gunmetal', '#2E3236']
		] },
		{ group: 'Stones & pearls', colors: [
			['Pearl White', '#F4EFE8'], ['Ruby Red', '#9B111E'], ['Emerald', '#1F6F4A'],
			['Sapphire Blue', '#0B2A8C'], ['Amethyst', '#7B4B94'], ['Turquoise', '#3AAFA9'],
			['Onyx Black', '#111111'], ['Opal', '#D9E4E8']
		] },
		{ group: 'Plastic, acrylic & enamel', colors: [
			['Hot Pink', '#E91E63'], ['Baby Pink', '#F8BBD0'], ['Coral', '#FF6F61'],
			['Butter Yellow', '#F3E5AB'], ['Mint', '#98E2C6'], ['Baby Blue', '#A7C7E7'],
			['Lilac', '#C8A2C8'], ['Cherry Red', '#B00020'], ['Ivory', '#FAF0E1'],
			['Matte Black', '#1A1A1A'], ['Clear', '#EDF2F5']
		] }
	];

	/* One accessory slot: pick a piece from the list, then color it. */
	function accessorySlot(s, n, label, list, fallback) {
		var pick = 'acc' + n + 'Pick';
		var on = 'acc' + n + 'ColorOn';
		var col = 'acc' + n + 'Color';
		return '<div class="spg-acc">' +
			sub(label) +
			select(pick, s[pick], selectGroups(list)) +
			showIf(pick + '=custom',
				'<div class="spg-field">' + textInput('acc' + n + 'Custom', s['acc' + n + 'Custom'], 'e.g. a chunky resin cuff with a marbled pattern') + '</div>') +
			showIf(pick + '!=',
				check(on, s[on], 'Give it a color / metal') +
				showIf(on + '=true', colorPicker(s, fallback, JEWEL_PALETTE, col, 'Color / metal'))) +
			'</div>';
	}

	function accessoryPhrase(s, n, fallback) {
		return pickedPhrase(s, {
			pick: 'acc' + n + 'Pick', custom: 'acc' + n + 'Custom',
			colorOn: 'acc' + n + 'ColorOn', color: 'acc' + n + 'Color'
		}, { palette: JEWEL_PALETTE, fallback: fallback });
	}

	/* ------------------------------------------------------------------ *
	 * Hand accessories
	 * ------------------------------------------------------------------ */

	var HAND_ACCESSORIES = [
		{ group: 'Rings', items: [
			['a thin plain band on the ring finger', 'Thin band, ring finger'],
			['a slim stacking ring set of three bands on the middle finger', 'Stacked bands'],
			['a solitaire ring with a single round stone on the ring finger', 'Solitaire ring'],
			['a chunky signet ring on the index finger', 'Signet ring'],
			['a vintage cocktail ring with a large stone on the middle finger', 'Cocktail ring'],
			['an eternity band set with tiny stones all around', 'Eternity band'],
			['a twisted rope ring on the index finger', 'Twisted rope ring'],
			['an open-ended wrap ring on the middle finger', 'Open wrap ring'],
			['a minimal midi ring above the knuckle of the index finger', 'Midi ring'],
			['a delicate nail ring hugging the tip of the ring finger', 'Nail ring'],
			['a thumb ring with a flat brushed face', 'Thumb ring'],
			['a pair of matching rings on the index and ring fingers', 'Two matching rings']
		] },
		{ group: 'Bracelets & bangles', items: [
			['a delicate chain bracelet resting on the wrist bone', 'Delicate chain bracelet'],
			['a fine curb-chain bracelet with a small oval tag', 'Chain bracelet with tag'],
			['a thin bangle sitting loose on the wrist', 'Thin bangle'],
			['a stack of four thin bangles that shift on the wrist', 'Stacked bangles'],
			['a set of glass bangles covering the lower forearm', 'Glass bangles'],
			['a wide cuff bracelet with a brushed finish', 'Wide cuff'],
			['a beaded stretch bracelet of small round beads', 'Beaded bracelet'],
			['a braided leather cord bracelet', 'Leather cord bracelet'],
			['a tennis bracelet set with a continuous line of small stones', 'Tennis bracelet'],
			['a charm bracelet with three small charms', 'Charm bracelet'],
			['a friendship thread tied around the wrist', 'Thread bracelet'],
			['a kada, a solid broad bangle, on the wrist', 'Kada']
		] },
		{ group: 'Watches', items: [
			['a slim round watch with a leather strap', 'Slim leather watch'],
			['a small square watch on a mesh bracelet strap', 'Square mesh watch'],
			['a chunky sports watch with a rubber strap', 'Sports watch'],
			['a vintage watch on a thin link bracelet', 'Vintage link watch'],
			['a smartwatch with a woven fabric band', 'Smartwatch']
		] },
		{ group: 'Hand chains & other', items: [
			['a hand harness chain running from the wrist to the middle finger', 'Hand harness chain'],
			['a haath phool with fine chains from the bracelet to the fingers', 'Haath phool'],
			['an anklet-style fine chain worn loose on the wrist', 'Fine loose chain'],
			['a thin ankle-length chain wrapped twice around the wrist', 'Double-wrapped chain'],
			['a scrunchie pushed onto the wrist', 'Scrunchie on the wrist'],
			['a fine bracelet-watch pairing worn together on one wrist', 'Bracelet and watch together'],
			['fresh henna-free bare skin with no jewelry on this wrist', 'Nothing on this wrist']
		] },
		{ group: 'Other', items: [
			['', 'Nothing in this slot'],
			['custom', 'Custom… (type your own)']
		] }
	];

	/* ------------------------------------------------------------------ *
	 * Earrings for the selfie
	 * ------------------------------------------------------------------ */

	var EARRINGS = [
		{ group: 'Studs', items: [
			['small round stud earrings', 'Small round studs'],
			['tiny solitaire stud earrings with a single stone', 'Solitaire studs'],
			['pearl stud earrings', 'Pearl studs'],
			['star-shaped stud earrings', 'Star studs'],
			['heart-shaped stud earrings', 'Heart studs'],
			['flat geometric square stud earrings', 'Square studs'],
			['a stacked set of three studs up each ear', 'Stacked studs']
		] },
		{ group: 'Hoops', items: [
			['small huggie hoop earrings close to the lobe', 'Small huggie hoops'],
			['classic medium hoop earrings', 'Medium hoops'],
			['large thin statement hoop earrings', 'Large thin hoops'],
			['chunky thick hoop earrings', 'Chunky hoops'],
			['textured twisted hoop earrings', 'Twisted hoops'],
			['hoop earrings with tiny beads threaded on them', 'Beaded hoops']
		] },
		{ group: 'Drops & danglers', items: [
			['delicate threader drop earrings', 'Threader drops'],
			['teardrop earrings with a single smooth stone', 'Teardrop earrings'],
			['long linear bar drop earrings', 'Linear bar drops'],
			['tassel earrings with fine swinging strands', 'Tassel earrings'],
			['chandelier earrings with layered tiers', 'Chandelier earrings'],
			['feather-light leaf-shaped dangling earrings', 'Leaf danglers'],
			['a pair of mismatched asymmetric drop earrings', 'Mismatched drops']
		] },
		{ group: 'Indian & traditional', items: [
			['jhumka earrings with a domed bell and tiny drops', 'Jhumkas'],
			['oversized statement jhumka earrings', 'Large jhumkas'],
			['chandbali crescent-shaped earrings', 'Chandbali'],
			['ear cuffs with a fine chain running up to the helix', 'Ear cuff with chain'],
			['kundan stone-set earrings', 'Kundan earrings'],
			['a single ear chain linking the lobe to the ear cuff', 'Ear chain']
		] },
		{ group: 'Other', items: [
			['', 'None visible'],
			['match', 'Exactly the earrings in my reference image'],
			['custom', 'Custom… (type your own)']
		] }
	];

	/* Materials. The last group takes a color, because those are dyed, not metal. */
	var EARRING_MATERIALS = [
		{ group: 'Precious metals', items: [
			['yellow gold', 'Yellow gold'],
			['white gold', 'White gold'],
			['rose gold', 'Rose gold'],
			['sterling silver', 'Sterling silver'],
			['oxidised silver', 'Oxidised silver'],
			['platinum', 'Platinum']
		] },
		{ group: 'Other metals', items: [
			['brushed stainless steel', 'Stainless steel'],
			['antique brass', 'Brass'],
			['copper', 'Copper'],
			['gunmetal', 'Gunmetal']
		] },
		{ group: 'Stones & natural', items: [
			['freshwater pearl', 'Pearl'],
			['mother of pearl', 'Mother of pearl'],
			['cut glass crystal', 'Glass crystal'],
			['polished wood', 'Wood']
		] },
		{ group: 'Colored materials (pick a color)', items: [
			['acrylic', 'Acrylic'],
			['plastic', 'Plastic'],
			['resin', 'Resin'],
			['enamel', 'Enamel'],
			['beaded', 'Beads']
		] },
		{ group: 'Other', items: [
			['', 'Let the image model decide']
		] }
	];

	var EARRING_COLORED = 'earringMaterial=acrylic|earringMaterial=plastic|earringMaterial=resin|earringMaterial=enamel|earringMaterial=beaded';

	/* "Small round studs in yellow gold." */
	function earringPhrase(s) {
		if (!s.earrings) return '';
		var base = s.earringPick;
		if (base === 'match') return '';
		if (base === 'custom') base = String(s.earringsText || '').trim().replace(/[.\s]+$/, '');
		if (!base) return '';
		var mat = s.earringMaterial;
		if (!mat) return base;
		if (/^(acrylic|plastic|resin|enamel|beaded)$/.test(mat)) {
			return withColor(base, s.earringColor, JEWEL_PALETTE, '#E91E63') + ' ' + (mat === 'beaded' ? 'beads' : mat);
		}
		return base + ' in ' + mat;
	}

	var CAM_LET_MASTER = { group: 'Other', items: [['', 'Let the master prompt decide']] };

	/* ---- SOLE: macro of the soles ---- */
	var SOLE_CAM_ANGLES = [
		{ group: 'Level with the soles', items: [
			['straight on, exactly level with the soles', 'Straight on, level with the soles'],
			['off to the left of the feet at a three-quarter angle', 'Three-quarter from the left'],
			['off to the right of the feet at a three-quarter angle', 'Three-quarter from the right']
		] },
		{ group: 'Above', items: [
			['slightly above the soles, angled a little down at them', 'Slightly above, angled down'],
			['high above the feet, looking down onto the soles', 'High above, looking down']
		] },
		{ group: 'Below & behind', items: [
			['low near the floor, angled slightly up at the soles', 'Low, angled up'],
			['directly behind the soles, looking along the length of the feet', 'Behind the soles, looking along']
		] },
		CAM_LET_MASTER
	];
	var SOLE_CAM_CROPS = [
		{ group: 'Close', items: [
			['an extreme macro on the toes and the long toenails only', 'Extreme macro on the toes'],
			['a tight macro in which both soles fill the whole frame', 'Both soles fill the frame']
		] },
		{ group: 'Wider', items: [
			['a close shot of the soles with the ankles just inside the frame', 'Soles and ankles'],
			['a wider shot that also shows the calves and some of the surface', 'Wider: calves and surface']
		] },
		CAM_LET_MASTER
	];
	var SOLE_CAM_PLACEMENTS = [
		{ group: 'Vertical', items: [
			['in the middle of the frame, with clear space above and below them', 'Middle, space above and below'],
			['low in the frame, with the surface and background filling the space above', 'Low in the frame'],
			['high in the frame, with the surface filling the space below', 'High in the frame'],
			['filling the frame from edge to edge', 'Filling the frame']
		] },
		{ group: 'Entering the frame', items: [
			['entering the frame from the right edge', 'Entering from the right'],
			['entering the frame from the left edge', 'Entering from the left'],
			['entering the frame from the bottom edge', 'Entering from the bottom']
		] },
		CAM_LET_MASTER
	];

	/* ---- HAND ---- */
	var HAND_CAM_ANGLES = [
		{ group: 'Above the hand', items: [
			['directly above the hand, looking straight down at it', 'Straight down (flat lay)'],
			['above the hand at a three-quarter angle, looking down at it', 'Three-quarter from above'],
			['just over my shoulder, looking down past my arm', 'Over my shoulder']
		] },
		{ group: 'Level with the hand', items: [
			['level with the hand, straight on', 'Level with the hand'],
			['down close to the surface, almost at table level', 'Almost at table level']
		] },
		{ group: 'Below the hand', items: [
			['slightly below the hand, angled a little up at it', 'Slightly below, angled up']
		] },
		CAM_LET_MASTER
	];
	var HAND_CAM_CROPS = [
		{ group: 'Close', items: [
			['an extreme macro on the fingertips and the long nails', 'Extreme macro on the nails'],
			['a close macro in which the hand fills most of the frame', 'Hand fills the frame']
		] },
		{ group: 'Wider', items: [
			['a shot of the whole hand together with part of the forearm', 'Hand and forearm'],
			['a wider shot showing the hand together with its surroundings', 'Wider: hand and surroundings']
		] },
		CAM_LET_MASTER
	];
	var HAND_CAM_PLACEMENTS = [
		{ group: 'Vertical', items: [
			['in the middle of the frame, with clear space around it', 'Middle, space around it'],
			['in the lower half of the frame, with space above it', 'Lower half, space above'],
			['in the upper half of the frame, with space below it', 'Upper half, space below'],
			['filling the frame from edge to edge', 'Filling the frame']
		] },
		{ group: 'Horizontal', items: [
			['off to the left of the frame', 'Off to the left'],
			['off to the right of the frame', 'Off to the right'],
			['running across the frame diagonally', 'Diagonally across the frame']
		] },
		CAM_LET_MASTER
	];

	/* ---- FEET: standing, looking down at my own feet ---- */
	var FEET_CAM_ANGLES = [
		{ group: 'How steep the look down is', items: [
			['aimed straight down at my feet, a steep downward angle', 'Steep, straight down'],
			['aimed down but tilted a little forward, so more of the floor ahead is visible', 'Tilted forward, more floor'],
			['aimed down from higher up my body, so my feet look further away', 'Held higher, feet further away'],
			['aimed down from lower, nearer my waist, so my feet look closer', 'Held lower, feet closer']
		] },
		CAM_LET_MASTER
	];
	var FEET_CAM_CROPS = [
		{ group: 'Close', items: [
			['an extreme macro on the toes and the long toenails', 'Extreme macro on the toes'],
			['a close shot in which both feet fill most of the frame', 'Feet fill the frame']
		] },
		{ group: 'Wider', items: [
			['a shot of both feet with a ring of floor around them', 'Feet with floor around them'],
			['a wider shot that also shows more of the room around my feet', 'Wider: more of the room']
		] },
		CAM_LET_MASTER
	];
	var FEET_CAM_PLACEMENTS = [
		{ group: 'Vertical', items: [
			['in the middle of the frame, with floor visible above and below them', 'Middle, floor above and below'],
			['low in the frame, with a lot of floor above them', 'Low, floor above them'],
			['high in the frame, close to the top edge', 'High in the frame']
		] },
		{ group: 'Horizontal', items: [
			['off to one side of the frame, with floor filling the other side', 'Off to one side']
		] },
		CAM_LET_MASTER
	];

	/* ---- FEET carousel, 3rd person: a second person shoots my feet ---- */
	var FEET_CAM_ANGLES_THIRD = [
		{ group: 'Low', items: [
			['down at floor level, looking across at my feet', 'At floor level'],
			['low, around knee height, angled down at my feet', 'Knee height, angled down']
		] },
		{ group: 'Higher', items: [
			['at hip height, angled down at my feet', 'Hip height, angled down'],
			['at standing height, looking straight down at my feet', 'Standing, looking down']
		] },
		{ group: 'From the side', items: [
			['off to one side of my feet at a three-quarter angle', 'Three-quarter from the side'],
			['in front of my feet, looking straight at the toes', 'In front, facing the toes']
		] },
		CAM_LET_MASTER
	];

	/* One builder for every "pick a thing, then color it" field in the app:
	 * outfits, clothing, hand accessories and earrings all come through here. */
	function pickedPhrase(s, keys, o) {
		o = o || {};
		var base = s[keys.pick];
		if (base === 'custom') {
			base = String(s[keys.custom] || '');
			if (o.clean) base = o.clean(base);
			base = base.trim().replace(/[.\s]+$/, '');
		}
		if (!base) return '';
		if (!keys.colorOn || !s[keys.colorOn]) return base;
		return withColor(base, s[keys.color], o.palette, o.fallback);
	}

	function withColor(base, hexIn, palette, fallback) {
		var hex = normalizeHex(hexIn) || fallback || '#111111';
		var name = colorName(hex, palette || FASHION_PALETTE);
		return base + ' in ' + (name === 'Custom color' ? 'the color ' + hex : name.toLowerCase() + ' (' + hex + ')');
	}

	/* "A glimpse of …" — the garment, plus its color when one is applied. */
	function soleOutfitPhrase(s) {
		return pickedPhrase(s, { pick: 'outfitPick', custom: 'outfitCustom', colorOn: 'outfitColorOn', color: 'outfitColor' },
			{ palette: FASHION_PALETTE, fallback: '#111111', clean: stripGlimpse });
	}

	/* The pose text, then the two locks that travel with every pose. */
	function solePoseBlock(s) {
		var parts = [];
		if (s.pose.trim()) parts.push(s.pose.trim());
		parts.push(SOLE_NEW_POSE_LOCK);
		parts.push(SOLE_ANATOMY_LOCK);
		return parts.join('\n\n');
	}

	var SOLE = {
		id: 'sole',
		title: 'Sole',
		desc: 'Macro sole shot, long nails, Gemini-ready',
		palettes: { color: PALETTE, outfitColor: FASHION_PALETTE },
		icon: '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="currentColor">' +
			'<path d="M26 22c7 0 11 6 11 15 0 10-4 21-11 21s-10-7-10-15c0-5 2-9 3-13 1-5 2-8 7-8z"/>' +
			'<ellipse cx="22" cy="12" rx="5" ry="6"/><ellipse cx="32" cy="11" rx="3.4" ry="4"/>' +
			'<ellipse cx="39" cy="14" rx="3" ry="3.5"/><ellipse cx="44" cy="19" rx="2.6" ry="3"/>' +
			'<ellipse cx="47" cy="25" rx="2.2" ry="2.6"/></g></svg>',
		defaults: {
			ratio: '4:5',
			camHeight: '',
			camCrop: '',
			camPlacement: '',
			camNote: '',
			posePick: '',
			pose: '',
			surface: 'A laminated tiled floor. Clean.',
			background: '',
			nail: 'B',
			color: '#0B2A8C',
			outfitPick: 'a pair of wide-leg trousers whose hem breaks softly over the ankle',
			outfitCustom: '',
			outfitColorOn: true,
			outfitColor: '#111111',
			anklet: false,
			ankletText: '',
			ring: false,
			ringText: '',
			aiIdea: ''
		},
		libTargets: { sole_pose: 'pose' },
		clean: { outfitCustom: stripGlimpse },

		/* Picking a ready pose drops its text into the pose box, where it can be edited. */
		onSelect: {
			posePick: function (s, form) {
				if (!s.posePick || !SOLE_POSE_TEXT[s.posePick]) return;
				s.pose = SOLE_POSE_TEXT[s.posePick];
				if (form.elements.pose) form.elements.pose.value = s.pose;
			}
		},

		form: function (s) {
			return '' +
				card('Aspect ratio', seg('ratio', s.ratio, [['4:5', '4:5', 'default'], ['9:16', '9:16']])) +
				card('Camera & framing',
					cameraFields(s, [SOLE_CAM_ANGLES, SOLE_CAM_CROPS, SOLE_CAM_PLACEMENTS], [
						'Where the camera is', 'How close the shot is', 'Where the feet sit in the frame',
						'e.g. rotated slightly so the frame is not perfectly level'
					]) +
					'<p class="spg-hint">Left on “let the master prompt decide”, the fixed macro close-up at sole level is used.</p>') +
				card('Pose detail',
					'<div class="spg-field">' + sub('Pick a ready pose (50 poses)') +
					select('posePick', s.posePick, selectGroups(SOLE_POSE_GROUPS)) +
					'<p class="spg-hint">Picking one writes it into the box below. Edit it, refine it with AI, or leave it exactly as it is.</p></div>' +
					sub('Pose text') +
					textArea('pose', s.pose, 8, 'Pick a ready pose above, write your own, or leave this empty to send the anatomy reference only') +
					textTools('pose') +
					locked('Always added after the pose', SOLE_NEW_POSE_LOCK) +
					'<details class="spg-lockbox"><summary>Anatomy &amp; physics reference — always added to every pose</summary>' +
					'<pre class="spg-lockpre">' + esc(SOLE_ANATOMY_LOCK) + '</pre></details>' +
					libBlock('sole_pose', 'Saved poses', 'Save this pose', 'Name this pose, e.g. Crossed ankles, right side') +
					aiPanel('sole_pose', 'pose', 'aiIdea', s.aiIdea, [
						'Lying on my stomach, knees bent, soles up to the ceiling, toes gently spread',
						'Sitting on the floor, legs straight toward camera, soles facing lens, left foot slightly higher',
						'Lying on my back, feet stacked, right foot on top, toes slightly curled, from the left side'
					], 'Describe the pose in your own words. The AI writes a precise, Gemini-ready pose into the box above. Leave this empty to polish the current pose instead.',
					'e.g. lying on my stomach, soles up, right foot crossed over left, toes relaxed, feet enter from the right')) +
				card('Surface option', textInput('surface', s.surface, 'e.g. A laminated tiled floor. Clean.')) +
				card('Background',
					textArea('background', s.background, 3, 'Describe the background (optional)') +
					aiInline('sole_background', 'background', [
						'A plain bedroom floor in the afternoon',
						'An old balcony floor with a little dust and sunlight',
						'A hotel bed with rumpled white sheets'
					]) +
					locked('Always added after', BACKGROUND_LOCK)) +
				card('Nail option',
					choices('nail', s.nail, [['A', 'Option A', 'Completely natural / bare'], ['B', 'Option B', 'Glossy polish on top, bare underside']]) +
					showIf('nail=B', colorPicker(s, '#0B2A8C', PALETTE, 'color', 'Nail polish color'))) +
				card('Wardrobe / accessories',
					sub('Outfit — lower body, visible at the ankles') +
					select('outfitPick', s.outfitPick, selectGroups(LOWER_OUTFITS)) +
					showIf('outfitPick=custom',
						'<div class="spg-field">' +
						prefixField('outfitCustom', s.outfitCustom, 'A glimpse of', 'e.g. a black pant near the ankle') +
						'</div>') +
					showIf('outfitPick!=',
						check('outfitColorOn', s.outfitColorOn, 'Apply a color to the outfit') +
						showIf('outfitColorOn=true', colorPicker(s, '#111111', FASHION_PALETTE, 'outfitColor', 'Outfit color'))) +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Anklet') + yesNo('anklet', s.anklet) + '</div>' +
					showIf('anklet=true', textInput('ankletText', s.ankletText, 'e.g. a thin gold chain anklet on the left ankle')) + '</div>' +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Ring') + yesNo('ring', s.ring) + '</div>' +
					showIf('ring=true', textInput('ringText', s.ringText, 'e.g. a silver toe ring on the second toe')) + '</div>');
		},

		build: function (s) {
			var hex = normalizeHex(s.color) || '#0B2A8C';
			var nail = s.nail === 'A' ? SOLE_NAIL_A : soleNailB(hex);
			var bg = s.background.trim();
			var camera = cameraLineFrom([
				['The camera is', s.camHeight],
				['The shot is framed as', s.camCrop],
				['The feet sit', s.camPlacement]
			], s.camNote);
			var head = SOLE_PART_2_HEAD;
			if (camera) {
				head = head.replace('Perspective & POV: Macro close-up, eye-level with the soles of the feet.',
					'Perspective & POV: Macro close-up' + (s.camHeight ? '. ' : ', eye-level with the soles of the feet. ') + camera);
			}
			var outfit = soleOutfitPhrase(s);
			var wardrobe = [outfit
				? '* A glimpse of ' + outfit
				: '* No clothing is visible in the frame; my legs are bare down to the ankles.'];
			if (s.anklet) wardrobe.push('* Anklet: ' + (s.ankletText.trim() || 'a delicate anklet'));
			if (s.ring) wardrobe.push('* Ring: ' + (s.ringText.trim() || 'a delicate toe ring'));

			return [
				SOLE_PART_1, '', '', '',
				head,
				' POSE DETAIL: ' + solePoseBlock(s),
				'',
				'Surface Option : ' + s.surface.trim(),
				'Background:   ' + (bg ? bg + ' ' : '') + BACKGROUND_LOCK,
				'',
				'Nail Option : ' + nail,
				'Required Wardrobe/Accessories  ',
				'',
				wardrobe.join('\n'),
				'',
				SOLE_VIBE,
				'',
				'Ratio ' + s.ratio
			].join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (!s.pose.trim()) w.push('No pose written — only the anatomy and physics reference is sent. Pick a ready pose or write one.');
			if (s.outfitPick === 'custom' && !s.outfitCustom.trim()) w.push('Custom outfit is empty.');
			return w;
		},

		context: function (s, kind) {
			return {
				'Camera': cameraLineFrom([
					['The camera is', s.camHeight],
					['The shot is framed as', s.camCrop],
					['The feet sit', s.camPlacement]
				], s.camNote),
				'Pose': kind === 'sole_pose' ? '' : s.pose,
				'Surface': s.surface,
				'Background': kind === 'sole_background' ? '' : s.background,
				'Wardrobe': soleOutfitPhrase(s) ? 'A glimpse of ' + soleOutfitPhrase(s) : 'Bare legs',
				'Anklet': s.anklet ? (s.ankletText || 'yes') : '',
				'Ring': s.ring ? (s.ringText || 'yes') : '',
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			return [s.ratio, s.nail === 'A' ? 'Natural nails' : 'Polish ' + s.color,
				shortLabel(SOLE_POSE_GROUPS, s.posePick, ''),
				camSummary(s, SOLE_CAM_ANGLES, SOLE_CAM_CROPS, SOLE_CAM_PLACEMENTS), s.surface].filter(Boolean).join(' · ');
		}
	};

	/* ================================================================== *
	 * HAND generator
	 * ================================================================== */

	var HAND_PART_1 = [
		'Technical Reasoning Instructions (The "Realism Engine")',
		'',
		'Reference Isolation Protocol: The provided reference images are strictly for extracting the exact biological hand structure, fingernail shape, and fingernail length. DO NOT copy the pose, background, or hand orientation from the reference. The new generation must feature a completely fresh pose based solely on the text instructions below.',
		'',
		'Anatomy & Tendon Physics: Calculate skeletal structure of the hand (phalanges, metacarpals). CRITICAL: Ensure exactly five (5) digits. Enforce a distinctly young, feminine, elegant, and attractive bone structure with slender fingers and minimal joint definition or skeletal prominent. Simulate soft extensor tendon tension across the back of the hand and very delicate, soft knuckle wrinkling based on the specific pose. Dynamically render the exact feminine hand of a young woman requested below (Left or Right) with precise anatomical accuracy, strictly avoiding thick, coarse, or masculine proportions, ignoring the hand pose shown in the reference.',
		'',
		'Light Physics (Advanced SSS): Simulate deep Subsurface Scattering within human tissue. Light must penetrate the skin of the fingers and hand, revealing subtle, very faint youthful vascularity. Crucially, simulate the translucent nature of natural keratin. Intense light must make the free edges of the extremely long nails glow slightly, highlighting their thickness and structure against the more opaque nail bed.',
		'',
		'Texture Mapping Physics: Disable all smoothing algorithms and AI beauty filters. Prioritize "High-Frequency Texture Rendering." Treat skin pores, fingerprint ridges, extremely fine (almost invisible) vellus hairs on the back of the hand, organic cuticles, and natural keratin ridges on the nails as essential details that must be resolved with 100% clarity. The nails and skin should appear highly detailed, with no shaky edges or motion blur. Maintain soft, taut, and flawless (youthful) skin textures to prevent the realism engine from outputting overly aged or rough masculine skin. Every nail and finger edge needs to look stable, sharp, and crystal clear.',
		'',
		'Image Generation Prompt',
		'',
		'1. Camera & Sensor Data (The Photorealistic Macro Set)',
		'',
		'* Device: Simulated shot on an iPhone 15 Pro.',
		'* Lens: Macro Mode enabled, 24mm (Wide), creating slight, natural perspective distortion (foreshortening) where elements closest to the lens appear slightly larger.',
		'* Settings: ISO 600 to 800.',
		'* Sensor Physics: Enforce an unfiltered, photorealistic protocol. Introduce visible luminance noise in the shadow areas between fingers and subtle chromatic aberration on high-contrast edges. Allow specular highlights on the shiny nails to blow out naturally. Absolutely zero airbrushed AI aesthetic.'
	].join('\n');

	var HAND_GEOMETRY = '* [CRITICAL GEOMETRY LOCK]: The core subject is the hand of a very young, highly attractive, and elegantly feminine woman, defined by the specific, extreme length, natural growth direction, C-curve, and structural apex of the fingernails as shown in the reference images. This specific morphology is non-negotiable and must be preserved flawlessly. The nails are made of natural keratin. The hand must have the plumpness and softness characteristic of youth.';

	var HAND_SELECTION = {
		right: '* Hand Selection & Anatomy Lock (RIGHT HAND STRICT): Render the subject\'s RIGHT hand ONLY. Engine Override: Calculate asymmetrical right-hand skeletal rendering. If showing the dorsal side (back of the hand), the thumb MUST be positioned on the left side of the hand geometry. If showing the palm, the thumb MUST be on the right side. Calculate the correct thumb placement and palm direction for this specific choice.',
		left: '* Hand Selection & Anatomy Lock (LEFT HAND STRICT): Render the subject\'s LEFT hand ONLY. Engine Override: Calculate asymmetrical left-hand skeletal rendering. If showing the dorsal side (back of the hand), the thumb MUST be positioned on the right side of the hand geometry. If showing the palm, the thumb MUST be on the left side. Calculate the correct thumb placement and palm direction for this specific choice.',
		both: '* Hand Selection & Anatomy Lock (BOTH HANDS STRICT): Render BOTH of the subject\'s hands: exactly one LEFT hand and one RIGHT hand, each with exactly five (5) digits. Engine Override: Calculate mirrored, asymmetrical left-hand and right-hand skeletal rendering; never render the same hand twice. For a hand showing the dorsal side (back of the hand), the right hand\'s thumb MUST be on its left side and the left hand\'s thumb MUST be on its right side. For a hand showing the palm, the right hand\'s thumb MUST be on its right side and the left hand\'s thumb MUST be on its left side. Calculate the correct thumb placement and palm direction for this specific choice.'
	};

	var HAND_PERSPECTIVE = {
		third: '* Perspective (External Observer Protocol): The camera is held by a second person looking AT the subject. Compositional Lock: The subject\'s wrist and arm MUST enter the frame from the TOP or SIDE edges of the composition, extending inward toward the center. The arm must NEVER originate from the bottom edge of the frame.',
		first: '* Perspective (True 1st-Person POV Protocol): The camera sees exactly what the subject sees when looking DOWN at her own hand; there is no second person. Compositional Lock: The subject\'s wrist and forearm MUST enter the frame from the BOTTOM edge (or a bottom corner) of the composition, extending upward toward the center with natural foreshortening. The arm must NEVER originate from the top edge of the frame.'
	};

	var HAND_BIO = [
		'3. Biological Realism (4K Detail)',
		'',
		'* Cuticles: Maintained and perfect. Show freshly oiled cuticles with a distinct, clean ridge where the proximal nail fold meets the nail plate.',
		'* Skin Texture: Ultra-realistic and raw, yet strictly youthful and elegantly feminine. Visible fine fingerprints on pad sides, very soft and minimal knuckle wrinkling typical of young hands, and extremely subtle, faint blue vascularity visible through the healthy, taut thin skin. The hands must possess a highly attractive, youthful feminine structure with slender fingers. The skin must look exactly like real young female human skin—soft, natural, taut, and lifelike with very fine, microscopic pores and tonal variation, specifically avoiding heavy, coarse, masculine skin textures, deep wrinkles, roughness, or signs of aging. Avoid any plastic, waxy, CGI, overly smooth, or unnatural appearance; maintain photorealism without aging or masculinizing the hand.'
	].join('\n');

	var HAND_NAIL_A = '* Nail Finish: Option A (Natural).\n* Option A (Natural): Bare keratin, buffed to a natural sheen. Show varying transparency at the long free edges and distinct lunulae at the base.';

	function handNailB(hex, design, matchBottle) {
		design = sentence(design);
		return '* Nail Finish: Option B (Polished).\n' +
			'* Option B (Polished): Nail polish color: ' + hex + '. ' +
			(design ? design + ' ' : '') +
			'The polish must look hand-applied, extending all the way to the tip of the long nail, with hyper-realistic flaws like a tiny speck of dust or slight unevenness in the specular reflection. It should look polished on natural nails, not like acrylics or thick nail extensions. Keep the nail structure slim, realistic, and soft without heavy buildup.' +
			(matchBottle ? ' Match the nail polish color exactly to the polish bottle shown in the reference image by extracting its real shade accurately.' : '');
	}

	var ACC_MATCH = 'Match these accessories exactly to the ones shown in the reference image (same design, material, color, size and placement). This is the only exception to the Reference Isolation Protocol.';

	var HAND_LABELS = {
		hand: { right: 'Right hand only', left: 'Left hand only', both: 'Both hands' },
		perspective: { third: '3rd-person (someone else holds the camera; arm enters from the top or side edges)', first: 'True 1st-person POV looking down (forearm enters from the bottom edge)' }
	};

	/* Quick-choose poses: which hand or hands I am showing. */
	var HAND_POSE_PRESETS = {
		both: 'I am showing both of my hands. I hold them up side by side toward the camera at the same height, the backs of both hands to the lens and the palms facing away from me. My fingers are relaxed and gently spread so that all ten long nails are fully visible, uncropped and clearly separated, with the thumbs angled naturally inward and both wrists softly straight.',
		left: 'I am showing my left hand. I raise it toward the camera with the back of the hand to the lens and the palm facing away from me. My fingers are relaxed and gently spread so all five long nails are fully visible, uncropped and clearly separated, the thumb resting naturally to the side and the wrist softly straight.',
		right: 'I am showing my right hand. I raise it toward the camera with the back of the hand to the lens and the palm facing away from me. My fingers are relaxed and gently spread so all five long nails are fully visible, uncropped and clearly separated, the thumb resting naturally to the side and the wrist softly straight.'
	};

	/* ------------------------------------------------------------------ *
	 * Hand pose library — 50 ready poses
	 *
	 * The hand and nothing else: which side faces the camera, what every
	 * finger and the thumb do, the wrist angle and where the nails point.
	 * No objects, no surfaces, no background, no jewelry — those are their
	 * own fields, and the pose must work wherever you put the hand.
	 * ------------------------------------------------------------------ */

	var HAND_POSE_GROUPS = [
		{ group: 'Back of the hand', items: [
			['h01', '1 · Flat, fingers together'],
			['h02', '2 · Flat, fingers elegantly spread'],
			['h03', '3 · Softly arched, fingers draped down'],
			['h04', '4 · Three-quarter turn, thumb forward'],
			['h05', '5 · Tilted so the nails lead'],
			['h06', '6 · Fingers stepped into a fan'],
			['h07', '7 · Wrist dropped, fingers trailing'],
			['h08', '8 · Fingers lifted, knuckles low'],
			['h09', '9 · Hand rolled onto its outer edge'],
			['h10', '10 · Hand rolled onto its thumb edge']
		] },
		{ group: 'Palm', items: [
			['h11', '11 · Open palm, fingers together'],
			['h12', '12 · Open palm, fingers spread'],
			['h13', '13 · Cupped palm, fingers curled inward'],
			['h14', '14 · Palm up, fingers falling open'],
			['h15', '15 · Palm angled, seen from the side'],
			['h16', '16 · Palm forward, fingers upright'],
			['h17', '17 · Palm tilted, nails curling over the edge'],
			['h18', '18 · Shallow scoop, fingers barely bent']
		] },
		{ group: 'Relaxed & falling', items: [
			['h19', '19 · Wrist limp, fingers hanging'],
			['h20', '20 · Loose fist, nails resting on the palm'],
			['h21', '21 · Half-closed, fingers staggered'],
			['h22', '22 · Fingers curled, index a little straighter'],
			['h23', '23 · Hand falling backward from the wrist'],
			['h24', '24 · Fingers gently collapsed inward'],
			['h25', '25 · Relaxed claw, fingers evenly bent'],
			['h26', '26 · Weightless, every joint soft']
		] },
		{ group: 'Fingers & gestures', items: [
			['h27', '27 · Index extended, the rest curled'],
			['h28', '28 · Index and middle extended together'],
			['h29', '29 · Thumb and index almost touching'],
			['h30', '30 · Thumb and middle lightly pinched'],
			['h31', '31 · Ring finger lifted alone'],
			['h32', '32 · Little finger lifted away'],
			['h33', '33 · Fingers counted off in a stagger'],
			['h34', '34 · Fingers crossed at the tips'],
			['h35', '35 · Nails fanned toward the lens'],
			['h36', '36 · Fingertips drawn into a point']
		] },
		{ group: 'Wrist & forearm', items: [
			['h37', '37 · Wrist bent sharply, hand upright'],
			['h38', '38 · Wrist rotated so the edge leads'],
			['h39', '39 · Forearm across, hand turned back'],
			['h40', '40 · Wrist arched high, fingers dropping'],
			['h41', '41 · Forearm upright, hand tipped forward'],
			['h42', '42 · Wrist turning mid-rotation']
		] },
		{ group: 'Both hands together', items: [
			['b01', '43 · Side by side, backs to the camera'],
			['b02', '44 · One in front of the other'],
			['b03', '45 · Palms together, fingers aligned'],
			['b04', '46 · Fingers laced loosely'],
			['b05', '47 · One hand cupped in the other'],
			['b06', '48 · Mirrored, wrists crossed'],
			['b07', '49 · Nails of both hands fanned together'],
			['b08', '50 · One open, one softly curled']
		] },
		{ group: 'Other', items: [
			['', 'No preset pose — I write my own']
		] }
	];

	var HAND_POSE_TEXT = {
		h01: 'I hold my hand up toward the camera with the back of the hand facing the lens and the palm turned away from me. All four fingers stay together and softly straight, with no gaps between them, and the thumb rests lightly against the side of the index finger. My wrist stays straight and relaxed, and every nail is fully visible, uncropped and pointing in the same direction as its finger.',
		h02: 'I hold my hand up with the back of the hand to the camera. I spread my four fingers into even gaps, each one a little apart from its neighbour, and hold the thumb out at a natural angle away from the index finger. The fingers stay softly straight rather than tense. All five nails are fully visible, separated from each other, and none overlaps another finger.',
		h03: 'I hold my hand up with the back of the hand to the camera and let the whole hand arch softly, like a wave. My knuckles sit at the highest point and my fingers drape gently downward from there, each finger curving a little more than the one before it. The thumb follows the same soft curve. Every nail stays visible and clearly separated at the tips.',
		h04: 'I turn my hand about three-quarters toward the camera, so the lens sees most of the back of the hand and a little of the thumb side. The thumb comes forward, nearest the camera, and the fingers step away behind it. My fingers stay softly extended and slightly apart, the wrist relaxed, and all five nails remain visible with the nearest ones reading largest.',
		h05: 'I hold my hand up and tip it forward from the wrist so the fingertips come closer to the camera than the knuckles. The back of the hand faces the lens at a slight angle. My fingers stay softly straight and a little apart, so the nails lead the shape and are the closest part of the hand, fully visible and clearly separated from one another.',
		h06: 'I hold my hand with the back to the camera and stagger the fingers into a fan: the index highest, then the middle, ring and little finger each a step lower, spread apart in even gaps. The thumb sits low and out to the side. Every finger stays softly straight, and the five nails form a clean diagonal line across the frame, each one fully visible.',
		h07: 'I let my wrist drop so the hand hangs forward and down from it, the back of the hand toward the camera. My fingers trail loosely downward, almost straight but with no tension, each one a little apart from the next. The thumb falls naturally alongside the index finger. The nails point down and stay fully visible at the lowest part of the hand.',
		h08: 'I hold my hand with the back to the camera and lift all four fingers upward from the knuckles while keeping the knuckles themselves low. The fingers angle up and slightly apart, the thumb relaxed and away to the side. The extensor tendons on the back of the hand show gently under the skin. Every nail is visible, pointing upward, and none is bent backward.',
		h09: 'I roll my hand over onto its outer edge, so the camera sees the little-finger side of the hand and the back of the hand at a steep angle. My fingers stay softly extended and slightly stepped apart, the thumb angled up and away. The nails read edge-on to three-quarters, and all five stay visible, showing their length and thickness from the side.',
		h10: 'I roll my hand onto its thumb edge, so the camera sees the index-finger side of the hand with the thumb nearest the lens. My fingers stay together and softly straight behind the thumb, each one just visible beyond the next. The wrist stays in line with the forearm. All five nails stay visible, with the thumb nail largest in the frame.',
		h11: 'I turn my hand palm-up toward the camera with my fingers together and softly straight. The palm lines, the pads of the fingers and the base of the thumb are all clearly visible. The thumb rests a little away from the palm. My nails curl over the fingertips and stay visible from the palm side, their tips clearly separated.',
		h12: 'I turn my hand palm-up toward the camera and spread my fingers into even gaps, the thumb held well away from the index finger. The palm is open and flat, with the natural creases visible. The fingers stay soft rather than rigid. Each nail shows over the top of its fingertip, fully visible and clearly separated from the others.',
		h13: 'I turn my palm toward the camera and cup it, curling all four fingers inward and drawing the thumb in toward them, as if holding air. The fingers stay evenly curved and slightly apart, so no nail presses into the palm. The nails stay fully visible inside the curve, tips separated and pointing back toward the base of the palm.',
		h14: 'I hold my hand palm-up and let the fingers fall open of their own weight, so they curve loosely upward with the little finger lowest and the index highest. The thumb drops away to the side. Nothing is held or touched. All five nails stay visible over the fingertips, their tips clearly separated and none bent.',
		h15: 'I turn my hand so the palm faces across the frame rather than at the camera, showing the palm at a steep angle together with the thumb edge. My fingers stay softly extended and slightly apart, stepping away from the camera in depth. Each nail stays visible in profile, showing its curve and its full length from the side.',
		h16: 'I raise my hand palm-forward to the camera with the fingers pointing upward and held softly straight, small even gaps between them. The thumb angles out to the side. The wrist stays straight under the palm. All five nails show above the fingertips, fully visible, separated, and pointing toward the top of the frame.',
		h17: 'I hold my hand palm toward the camera and tip it slightly backward from the wrist, so the fingertips lean away and the nails curl over the top edge of each finger. My fingers stay together with the smallest gaps. The nails are the highest part of the hand, fully visible along their whole length and clearly separated.',
		h18: 'I hold my palm toward the camera in a very shallow scoop, the fingers barely bent, as if the hand has just relaxed. The four fingers stay close together with only hairline gaps and the thumb sits softly alongside. The knuckles show almost no creasing. All five nails stay visible, tips separated and unbent.',
		h19: 'I let my wrist go completely limp so the hand hangs from it with no tension at all. The back of the hand faces the camera at a soft angle and the fingers hang downward, loosely curved and unevenly spaced. The thumb falls in toward the index finger. Every nail stays visible, pointing down, with the tips clearly separated.',
		h20: 'I close my hand into a loose fist, so slack that the fingers only half curl and never press into the palm. The back of the hand faces the camera. The nails rest above the palm without touching it, each one fully visible in the gap between the fingers and the palm, and the thumb lies softly across the side of the index finger.',
		h21: 'I half close my hand with the back toward the camera, letting each finger curl a different amount: the index barely bent, the middle a little more, the ring more again and the little finger the most. The thumb stays soft and low. The staggered fingertips keep every nail fully visible and clearly separated.',
		h22: 'I curl my fingers loosely toward the palm with the back of the hand to the camera, but leave the index finger noticeably straighter than the other three, as if it has just paused. The thumb rests gently against the middle of the index finger without pressing. All five nails stay visible and none is bent or hidden.',
		h23: 'I let my hand fall backward from the wrist, so the palm angles up and the back of the hand tips away from the camera. The fingers stay softly extended and drop back with the hand, slightly apart from each other. The tendons at the front of the wrist show gently. The nails stay fully visible, angled up and back.',
		h24: 'I let all four fingers collapse gently inward toward the palm at the same rate, keeping the hand soft and the knuckles barely creased, with the back of the hand toward the camera. The thumb curls loosely alongside. Nothing is gripped. The five nails stay fully visible in the open space inside the curl, tips clearly separated.',
		h25: 'I hold my hand in a relaxed claw shape, every finger bent evenly at each joint and spread into equal gaps, the back of the hand toward the camera and the fingertips pointing down toward the lens. The thumb mirrors the curve. Each nail is fully visible at the end of its finger, none pressed against anything.',
		h26: 'I hold my hand completely weightless, as if it were floating, with every joint soft and no muscle working. The back of the hand faces the camera. The fingers sit in a loose, uneven arrangement with slight gaps and slight curves, no two the same. All five nails stay visible, clearly separated and naturally angled.',
		h27: 'I extend my index finger straight out toward the frame while the middle, ring and little fingers curl loosely in toward the palm, and the thumb rests softly against the side of the middle finger. The back of the hand faces the camera. The extended index nail is the clearest in the frame, and the three curled nails stay visible above the palm.',
		h28: 'I extend my index and middle fingers together, softly straight and just touching along their length, while the ring and little fingers curl in toward the palm and the thumb folds gently over them. The back of the hand faces the camera. The two extended nails read as a pair, fully visible, and the curled nails stay visible behind them.',
		h29: 'I bring my thumb and index fingertips almost together, leaving a small visible gap between the two nails, while the middle, ring and little fingers curve away in a soft fan behind them. The hand is seen from the thumb side. Nothing is held in the gap. All five nails stay visible, with the thumb and index nails clearest.',
		h30: 'I bring my thumb and middle fingertip into a light pinch, the two nails just meeting without pressing, while the index finger lifts and curves above them and the ring and little fingers fall away below. The back of the hand angles toward the camera. Every nail stays fully visible and unbent, with no skin compression at the touch point.',
		h31: 'I hold my hand with the back to the camera and lift my ring finger alone, straightening it clearly while the index, middle and little fingers stay softly curled toward the palm and the thumb rests low. The lifted finger reads as the focus of the pose. Its nail is the highest and clearest in the frame; the others stay visible below.',
		h32: 'I hold my hand with the back to the camera and lift my little finger away from the others, extending it out and slightly up while the index, middle and ring fingers stay together and softly curved. The thumb sits relaxed against the index. All five nails remain visible, with the little finger nail clearly separated from the group.',
		h33: 'I hold my hand up with the back toward the camera and set the four fingers at four different heights and four different bends, as if counting something off, the index highest and straightest through to the little finger lowest and most curled. The thumb stays soft and out to the side. Each of the five nails is fully visible.',
		h34: 'I cross the tip of my middle finger over the tip of the index finger, resting one lightly on the other with no pressure, while the ring and little fingers stay softly curled and the thumb sits low. The back of the hand faces the camera. Both crossed nails stay fully visible and unbent, and the other three nails remain in view.',
		h35: 'I turn my hand so the fingertips point almost straight at the camera with the back of the hand angling away behind them, and fan the fingers into even gaps. The nails become the nearest and largest part of the frame, each one seen close to head-on, fully visible from the cuticle to the free edge, and clearly separated from its neighbours.',
		h36: 'I draw all five fingertips together into a soft point, the nails meeting in a small cluster at the centre without pressing against each other, while the knuckles stay apart and the hand forms a narrow cone. The back of the hand faces the camera. Each nail stays visible around the cluster, none bent or hidden behind another.',
		h37: 'I bend my wrist sharply so the hand stands upright at almost a right angle to the forearm, the back of the hand facing the camera and the fingers pointing straight up. The fingers stay softly straight with small even gaps, and the thumb angles out. The tendons across the back of the hand show gently. All five nails point upward and stay fully visible.',
		h38: 'I rotate my wrist so the hand turns onto its edge and the forearm reads as a clean line into it, the little-finger edge leading toward the camera. My fingers stay softly extended, stepped apart in depth. The nails are seen at a sharp three-quarter angle, showing their thickness and curve, and all five stay visible.',
		h39: 'I bring my forearm across the frame horizontally and turn the hand back toward the camera at the wrist, so the hand faces the lens while the arm runs sideways. My fingers stay softly extended and a little apart, the thumb relaxed. The wrist turn stays within a natural range, and every nail is fully visible and separated.',
		h40: 'I arch my wrist high so the back of the hand rises toward the camera and the fingers drop away below it in a long soft curve. The knuckles are the nearest point and the fingertips the furthest. The fingers stay slightly apart. All five nails remain visible at the far end of the curve, tips clearly separated.',
		h41: 'I hold my forearm upright so the hand sits at the top of the frame, then tip the hand forward from the wrist toward the camera. The back of the hand faces the lens and the fingers hang gently forward and down, slightly apart. The nails are the lowest and nearest part of the hand, fully visible and unbent.',
		h42: 'I catch my hand mid-rotation at the wrist, halfway between palm-up and palm-down, so the camera sees part of the palm, part of the thumb edge and part of the back of the hand at once. The fingers stay softly extended and a little apart. Every nail stays visible, each one at a slightly different angle to the lens.',
		b01: 'I hold both hands up side by side at the same height, the backs of both hands facing the camera and the palms turned away from me. The two hands almost touch along their inner edges, with a narrow gap between them. All fingers stay softly extended and slightly apart, so all ten nails are fully visible, clearly separated and uncropped.',
		b02: 'I hold both hands up toward the camera with one hand clearly in front of the other, the backs of both hands to the lens. The front hand reads larger and fully; the back hand sits a little higher and to one side, so its fingers and nails stay visible past the edge of the front hand. All ten nails remain visible and separated.',
		b03: 'I bring both palms together in front of me, the fingers aligned and pointing upward, the hands touching lightly along the palms and the inner edges of the fingers. The camera sees the outer edge and the backs of both hands at a slight angle. The fingers stay softly straight, and the nails of both hands stay visible along the top.',
		b04: 'I lace the fingers of both hands together loosely, so the fingers alternate and rest against each other without gripping, and the two palms stay slightly apart. The backs of both hands face the camera. Every nail stays clear of the opposite hand, fully visible and unbent, and the thumbs sit softly alongside each other.',
		b05: 'I rest one hand, palm up and softly cupped, inside the palm of the other, so the upper hand lies gently in the lower one with no pressure between them. The camera sees both palms at a slight angle. The fingers of both hands stay softly curved and a little apart, and all ten nails stay visible and clearly separated.',
		b06: 'I cross my wrists in front of me and turn both hands outward so they mirror each other, the backs of both hands facing the camera and the fingers of each hand fanning away in opposite directions. The crossing happens at the wrists only, and the forearms stay naturally aligned. All ten nails stay fully visible and separated.',
		b07: 'I bring both hands toward the camera with the fingertips leading and fan the fingers of both hands into even gaps, the two hands angled slightly toward each other. The twenty edges of the ten nails become the nearest part of the frame, each nail seen close to head-on, fully visible and clearly separated from every other.',
		b08: 'I hold both hands up with the backs to the camera, one hand open with its fingers softly extended and spread, the other loosely curled with the fingers falling toward the palm. The two hands sit at slightly different heights and angles. All ten nails stay fully visible, the open hand showing its nails in a line and the curled hand inside its curve.'
	};

	var HAND = {
		id: 'hand',
		title: 'Hand',
		desc: 'Candid iPhone macro, long nails, Gemini-ready',
		palettes: { color: PALETTE, acc1Color: JEWEL_PALETTE, acc2Color: JEWEL_PALETTE, acc3Color: JEWEL_PALETTE },
		icon: '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="currentColor">' +
			'<rect x="21" y="29" width="25" height="27" rx="9"/>' +
			'<rect x="21" y="11" width="5.6" height="24" rx="2.8"/><rect x="27.8" y="6" width="5.6" height="27" rx="2.8"/>' +
			'<rect x="34.6" y="8" width="5.6" height="25" rx="2.8"/><rect x="41" y="14" width="5.2" height="20" rx="2.6"/>' +
			'<rect x="12" y="31" width="5.6" height="18" rx="2.8" transform="rotate(-38 14.8 40)"/></g></svg>',
		defaults: {
			ratio: '4:5',
			hand: 'right',
			perspective: 'third',
			camHeight: '',
			camCrop: '',
			camPlacement: '',
			camNote: '',
			posePick: '',
			pose: 'A flirty, feminine dorsal view with fingers elegantly spread',
			nail: 'B',
			color: '#BC9CB0',
			design: '',
			matchBottle: true,
			henna: false,
			hennaText: '',
			acc1Pick: '',
			acc1Custom: '',
			acc1ColorOn: true,
			acc1Color: '#D4AF37',
			acc2Pick: '',
			acc2Custom: '',
			acc2ColorOn: true,
			acc2Color: '#C0C4CA',
			acc3Pick: '',
			acc3Custom: '',
			acc3ColorOn: true,
			acc3Color: '#B76E79',
			acc: false,
			accText: '',
			accMatch: false,
			background: ''
		},
		libTargets: { hand_pose: 'pose' },

		/* The three quick poses also move the hand selection to match. */
		presets: {
			both: function (s) { s.hand = 'both'; s.posePick = ''; s.pose = HAND_POSE_PRESETS.both; },
			left: function (s) { s.hand = 'left'; s.posePick = ''; s.pose = HAND_POSE_PRESETS.left; },
			right: function (s) { s.hand = 'right'; s.posePick = ''; s.pose = HAND_POSE_PRESETS.right; }
		},

		onSelect: {
			posePick: function (s, form) {
				if (!s.posePick || !HAND_POSE_TEXT[s.posePick]) return;
				s.pose = HAND_POSE_TEXT[s.posePick];
				if (form.elements.pose) form.elements.pose.value = s.pose;
				/* A two-handed pose needs the two-handed anatomy lock. */
				if (s.posePick.charAt(0) === 'b' && s.hand !== 'both') {
					s.hand = 'both';
					if (form.elements.hand) {
						Array.prototype.forEach.call(form.elements.hand, function (r) { r.checked = r.value === 'both'; });
					}
				}
			}
		},

		form: function (s) {
			return '' +
				card('Output ratio', seg('ratio', s.ratio, [['4:5', '4:5', 'default'], ['9:16', '9:16']])) +
				card('Hand selection',
					seg('hand', s.hand, [['right', 'Right hand'], ['left', 'Left hand'], ['both', 'Both hands']]) +
					'<p class="spg-hint">Adds the matching anatomy lock (correct thumb side for back of hand or palm).</p>') +
				card('Perspective',
					choices('perspective', s.perspective, [
						['third', '3rd person', 'Someone else holds the camera. Arm enters from the top or side.'],
						['first', '1st person POV', 'You look down at your own hand. Arm enters from the bottom.']
					])) +
				card('Camera & framing',
					cameraFields(s, [HAND_CAM_ANGLES, HAND_CAM_CROPS, HAND_CAM_PLACEMENTS], [
						'Where the camera is', 'How close the shot is', 'Where the hand sits in the frame',
						'e.g. tilted slightly, shot through a doorway'
					]) +
					'<p class="spg-hint">This sits on top of the perspective above, which still decides which edge the arm enters from.</p>') +
				card('Pose / activity',
					'<div class="spg-field">' + sub('Pick a ready pose (50 poses)') +
					select('posePick', s.posePick, selectGroups(HAND_POSE_GROUPS)) +
					'<p class="spg-hint">Hand only: which side faces the camera, what each finger does, where the nails point. Picking a “both hands” pose switches the hand selection for you.</p></div>' +
					sub('Quick poses') +
					presetRow([
						['both', 'I am showing my both hands'],
						['left', 'I am showing my left hand'],
						['right', 'I am showing my right hand']
					]) +
					'<p class="spg-hint">Each one writes the pose below and switches the hand selection to match.</p>' +
					textArea('pose', s.pose, 4, 'e.g. fingers gently wrapped around a coffee cup') +
					aiInline('hand_pose', 'pose', [
						'Fingers gently wrapped around a warm latte cup on a café table',
						'Hand resting on a car steering wheel, fingers relaxed',
						'Dorsal view, fingers elegantly spread against a window',
						'Scrolling a phone, index nail tip just touching the screen'
					]) +
					locked('Always added after', REF_POSE_LOCK) +
					libBlock('hand_pose', 'Saved poses', 'Save this pose', 'Name this pose, e.g. Latte cup, right hand')) +
				card('Nail finish',
					choices('nail', s.nail, [['A', 'Option A', 'Natural: bare keratin, buffed sheen'], ['B', 'Option B', 'Polished: exact hex color']]) +
					showIf('nail=B',
						colorPicker(s, '#BC9CB0', PALETTE, 'color', 'Nail polish color') +
						'<div class="spg-field">' + sub('Design (optional)') + textInput('design', s.design, 'e.g. glossy cream finish, thin white French tip') + '</div>' +
						check('matchBottle', s.matchBottle, 'Also match the polish bottle in my reference image'))) +
				card('Henna',
					showIf('henna=true',
						textArea('hennaText', s.hennaText, 3, 'Describe the henna, e.g. minimal Arabic vine from index finger to wrist') +
						aiInline('henna', 'hennaText', [
							'Minimal Arabic vine from the index finger down to the wrist',
							'Full Indian bridal mehndi on the back of the hand, fingertips filled',
							'Delicate mandala on the back of the hand with fine finger bands'
						])) +
					showIf('henna=false', '<p class="spg-hint">No henna.</p>'),
					yesNo('henna', s.henna)) +
				card('Accessories',
					'<p class="spg-hint">Up to three pieces, each with its own metal or color.</p>' +
					accessorySlot(s, 1, 'Piece 1', HAND_ACCESSORIES, '#D4AF37') +
					accessorySlot(s, 2, 'Piece 2', HAND_ACCESSORIES, '#C0C4CA') +
					accessorySlot(s, 3, 'Piece 3', HAND_ACCESSORIES, '#B76E79') +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Anything else (free text)') + yesNo('acc', s.acc) + '</div>' +
					showIf('acc=true',
						textArea('accText', s.accText, 2, 'e.g. a thin gold ring on the ring finger and a delicate chain bracelet') +
						check('accMatch', s.accMatch, 'Match the accessories in my reference image')) + '</div>') +
				card('Location / background',
					textArea('background', s.background, 3, 'e.g. cozy café by a rainy window, wooden table') +
					aiInline('hand_background', 'background', [
						'Cozy café by a rainy window, wooden table',
						'Inside a car, daytime, beige leather interior',
						'White marble bathroom counter with skincare bottles',
						'Park bench at golden hour, autumn leaves'
					]) +
					locked('Always added after', BACKGROUND_LOCK));
		},

		build: function (s) {
			var hex = normalizeHex(s.color) || '#BC9CB0';
			var camera = cameraLineFrom([
				['The camera is', s.camHeight],
				['The shot is framed as', s.camCrop],
				['The hand sits', s.camPlacement]
			], s.camNote);
			var pose = sentence(s.pose.trim().replace(/\s*Do not use the pose from the reference image\.?\s*$/i, ''));
			var bg = sentence(s.background);
			var henna = s.henna ? (sentence(s.hennaText) || 'A delicate henna design.') : 'No Henna.';

			var pieces = [1, 2, 3].map(function (n) { return accessoryPhrase(s, n, '#D4AF37'); }).filter(Boolean);
			var acc;
			if (!pieces.length && !s.acc) {
				acc = 'No accessories.';
			} else {
				var bits = [];
				if (pieces.length) bits.push(cap(sentence('She is wearing ' + pieces.join(', and '))));
				if (s.acc && sentence(s.accText)) bits.push(cap(sentence(s.accText)));
				else if (s.acc && s.accMatch && !pieces.length) bits.push('Exactly as shown in the reference image.');
				if (s.acc && s.accMatch) bits.push(ACC_MATCH);
				acc = bits.join(' ') || 'Delicate, minimal jewelry.';
			}

			return [
				HAND_PART_1,
				'',
				'2. Subject, Geometry & Perspective (The Identity Lock)',
				'',
				HAND_GEOMETRY,
				HAND_SELECTION[s.hand] || HAND_SELECTION.right,
				(HAND_PERSPECTIVE[s.perspective] || HAND_PERSPECTIVE.third) +
					(camera ? '\n* Camera & Framing: ' + camera : ''),
				'* Pose/Activity: ' + (pose ? pose + ' ' : '') + REF_POSE_LOCK,
				'* Focus: Sharp focus on the fingernails and skin texture.',
				'',
				HAND_BIO,
				'',
				'4. Specific Aesthetic Variables',
				'',
				s.nail === 'A' ? HAND_NAIL_A : handNailB(hex, s.design, s.matchBottle),
				'* Henna: ' + henna,
				'* Accessories: ' + acc,
				'',
				'5. Environment & Lighting',
				'',
				'* Location/Background: ' + (bg ? bg + ' ' : '') + BACKGROUND_LOCK,
				'* Lighting: Soft, diffused natural lighting. Apply accurate SSS to the translucent keratin nail tips and render realistic, elongated shadow physics cast by the long nails onto the background or palm.',
				'',
				'6. Output',
				'',
				'* Aesthetic: Candid, unfiltered reality. It must look exactly like a raw photo taken casually on a phone in real life.',
				'* Output Ratio: ' + s.ratio
			].join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (!s.pose.trim()) w.push('Pose / activity is empty.');
			if (!s.background.trim()) w.push('Location / background is empty.');
			if (s.henna && !s.hennaText.trim()) w.push('Henna is on but not described.');
			[1, 2, 3].forEach(function (n) {
				if (s['acc' + n + 'Pick'] === 'custom' && !String(s['acc' + n + 'Custom']).trim()) {
					w.push('Accessory piece ' + n + ' is set to custom but empty.');
				}
			});
			if (s.acc && !s.accText.trim() && !s.accMatch) w.push('Extra accessories are on but not described.');
			return w;
		},

		context: function (s, kind) {
			var pieces = [1, 2, 3].map(function (n) { return accessoryPhrase(s, n, '#D4AF37'); }).filter(Boolean);
			return {
				'Hand selection': HAND_LABELS.hand[s.hand],
				'Perspective': HAND_LABELS.perspective[s.perspective],
				'Camera': cameraLineFrom([
					['The camera is', s.camHeight],
					['The shot is framed as', s.camCrop],
					['The hand sits', s.camPlacement]
				], s.camNote),
				'Pose/activity': kind === 'hand_pose' ? '' : s.pose,
				'Nail finish': s.nail === 'A' ? 'Natural bare nails' : 'Polished ' + s.color + (s.design ? ', ' + s.design : ''),
				'Henna': kind === 'henna' ? '' : (s.henna ? (s.hennaText || 'yes') : 'none'),
				'Accessories': pieces.concat(s.acc ? [s.accText || 'as in the reference image'] : []).join('; ') || 'none',
				'Background': kind === 'hand_background' ? '' : s.background,
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			var pieces = [1, 2, 3].filter(function (n) { return s['acc' + n + 'Pick']; }).length;
			return [
				HAND_LABELS.hand[s.hand],
				s.perspective === 'first' ? '1st person' : '3rd person',
				shortLabel(HAND_POSE_GROUPS, s.posePick, ''),
				camSummary(s, HAND_CAM_ANGLES, HAND_CAM_CROPS, HAND_CAM_PLACEMENTS),
				s.nail === 'A' ? 'Natural nails' : 'Polish ' + s.color,
				s.henna ? 'Henna' : '',
				pieces ? pieces + (pieces === 1 ? ' accessory' : ' accessories') : (s.acc ? 'Accessories' : ''),
				s.ratio
			].filter(Boolean).join(' · ');
		}
	};

	/* ================================================================== *
	 * FEET generator (standing, true 1st-person self-view)
	 * ================================================================== */

	var FEET_PART_1 = [
		'Technical Reasoning Instructions (The "Realism Engine"):',
		'',
		'1. Anatomy Physics: Calculate skeletal structure. Ensure phalanges align to support nail weight in the position.',
		'2. Light Physics (Advanced SSS): Simulate deep Subsurface Scattering. Intense light must penetrate the translucent keratin tips of the natural nails, making them glow slightly against the opaque nail bed. Light should also scatter through the thinnest skin on the top of the foot, revealing subsurface depth.',
		'3. Texture Mapping Physics: Disable any smoothing algorithms, HDR, or digital beauty filters. Prioritize "High-Frequency Texture Rendering." Treat skin pores, fine hairs, cuticle ridges, and keratin micro-topography as essential details that must be resolved with 100% clarity, zero noise, and zero compression artifacts. Introduce natural skin mottling and uneven pigmentation.',
		'',
		'',
		'',
		'Image Generation Prompt:',
		'',
		'4. Camera & Sensor Data (The Close-Range Clarity Set):',
		'',
		'Device: Simulated shot on a Phase One IQ4 150MP Medium Format Camera.',
		'Lens: Rodenstock HR Digaron-W 90mm f/5.6 Macro (A specialized lens for ultra-sharp, close-range photography with zero distortion).',
		'Aperture: f/16 (Very narrow aperture to ensure deep focus across both feet, despite the close range).',
		'ISO: ISO 50 (Absolute zero grain, crystal clear).',
		'Shutter: Tripod-stabilized shot to eliminate all micro-blur, ensuring frozen sharpness.',
		'Color Science: Uncompressed RAW format (.IIQ). Straight Out Of Camera (SOOC) with a flat, neutral color profile. Absolutely zero post-processing, zero color grading, and zero artificial HDR lighting.',
		'',
		'',
		'5. The Subject, Pose & POV (Absolute True 1st-Person Self-View):',
		'',
		'Perspective: TRUE 1st-Person POV. The camera represents the subject\'s own eyes, looking straight down the front of their own body toward the floor.',
		'Framing Constraint (The Secret to True POV): The 2D composition MUST map to human downward vision. The subject\'s own legs/shins/clothing MUST originate from the absolute BOTTOM EDGE (or bottom corners) of the frame—closest to the viewer. The legs extend outward, leading to the ankles in the middle of the frame. The toes MUST point AWAY from the camera, pointing towards the TOP half of the frame. Utilize heavy visual foreshortening on the shins to convey the steep downward angle.',
		'Viewing angle: From claves to toes. ',
		'Pose: Feet should be captured in the standing mode. ',
		'',
		'Focus: Razor-sharp focus on the toenails and skin texture of both feet. The shins/clothing at the bottom edge of the frame are slightly out-of-focus due to being too close to the lens.',
		TOENAIL_GEOMETRY_LOCK,
		'',
		'',
		'',
		'6. Environment & Wardrobe:',
		'',
		'Location: Yoga mat floor, no blur, clear texture. Evening time. '
	].join('\n');

	var FEET_POLISH_CONSTRAINT = 'Explicit constraint: The application of color does not change the geometry; the nails must remain very long and sharp as defined in Section 5. It must look like a real person applied it five minutes ago at home.  Add hyper-realistic flaws: microscopic bubbles, slight unevenness in the matte topcoat reflection, a tiny brush stroke mark, or a speck of dust caught in the polish. It must look freshly painted.';

	var FEET_BARE = 'Nail polish: None. The extremely long keratin nails are completely bare and unpainted, showing their natural color, subtle sheen and translucent tips.\n' +
		'Explicit constraint: The nails must remain very long and sharp as defined in Section 5, with no polish, no color and no artificial coating.';

	var FEET_PART_3 = [
		'7. Material & Biological Realism (4K Detail):',
		'',
		'Nail Material: Natural keratin. Show micro-ridges, varying transparency at the tips, and the "lunula" (moons) at the base. They must not look plastic.',
		'Cuticles: "Biological" and imperfect. Show freshly oiled cuticles with a distinct ridge where skin meets nail.',
		'Skin Texture (Hyper-Real & Raw): Visible pores, individual fine hairs on the toes, and subsurface vascularity (faint blue veins) showing through the thin skin of the foot dorsum.',
		'Crucial: Include natural, raw micro-imperfections like slight skin redness around the knuckles, varying skin tones, and microscopic dust motes on the skin to shatter the "AI perfect" look.',
		'Pressure Physics: Subtle compression where the heel and side of the foot press against the surface.',
		'',
		'',
		'8. Vibe & Aesthetic:',
		'',
		'Style: Ultra-realistic, close-range macro photography from a personal perspective. Documentary realism.',
		'Aesthetic: Crystal clear, high definition, zero grain. Unedited, raw, everyday lighting.',
		'Do NOT use vibrant or overly saturated colors; tones must be organic, true-to-life, and slightly flat as seen in unedited RAW photography.',
		'"Tactile realism."',
		'',
		'',
		'9. Output Ratio:',
		''
	].join('\n');

	var FEET_RATIO = {
		'4:5': 'Instagram Post: Use 4:5.',
		'9:16': 'Instagram Story / Reel: Use 9:16.'
	};

	/* "A glimpse of <phrase>, only up to the ankles." — keep only the phrase. */
	function cleanClothing(t) {
		return String(t || '').trim()
			.replace(/^a glimpse of\s+/i, '')
			.replace(/[,\s]*only up to the ankles\.?$/i, '')
			.replace(/[.\s]+$/, '');
	}

	/* "A glimpse of <phrase>, only up to the ankles." from the dropdown or the custom box. */
	function feetClothingPhrase(s) {
		return pickedPhrase(s, { pick: 'clothingPick', custom: 'clothingCustom', colorOn: 'clothingColorOn', color: 'clothingColor' },
			{ palette: FASHION_PALETTE, fallback: '#B00020', clean: cleanClothing });
	}

	var FEET = {
		id: 'feet',
		title: 'Feet',
		desc: 'Standing 1st-person self-view, long toenails',
		palette: TOE_PALETTE,
		palettes: { color: TOE_PALETTE, clothingColor: FASHION_PALETTE },
		icon: '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="currentColor">' +
			'<path d="M19 24c5 0 8 5 8 12 0 8-3 18-8 18s-8-6-8-12c0-4 1-7 2-10 1-5 2-8 6-8z"/>' +
			'<ellipse cx="16" cy="15" rx="3.6" ry="4.4"/><ellipse cx="22.5" cy="14" rx="2.4" ry="2.9"/><ellipse cx="27" cy="16.5" rx="2" ry="2.4"/>' +
			'<path d="M45 24c-5 0-8 5-8 12 0 8 3 18 8 18s8-6 8-12c0-4-1-7-2-10-1-5-2-8-6-8z"/>' +
			'<ellipse cx="48" cy="15" rx="3.6" ry="4.4"/><ellipse cx="41.5" cy="14" rx="2.4" ry="2.9"/><ellipse cx="37" cy="16.5" rx="2" ry="2.4"/></g></svg>',
		defaults: {
			ratio: '4:5',
			camHeight: '',
			camCrop: '',
			camPlacement: '',
			camNote: '',
			pose: '',
			polish: 'painted',
			color: '#C2185B',
			anklet: true,
			ankletText: 'Slim gold anklets',
			ankletMatch: false,
			toeRing: false,
			toeRingText: '',
			toeRingMatch: false,
			clothingPick: 'the hem of a flowing A-line maxi dress pooling near the ankles',
			clothingCustom: '',
			clothingColorOn: true,
			clothingColor: '#B00020',
			footwear: 'barefoot',
			footwearText: '',
			footwearMatch: false
		},
		libTargets: { feet_pose: 'pose' },
		clean: { clothingCustom: cleanClothing },

		form: function (s) {
			function jewelry(key, label, placeholder, matchLabel) {
				return jewelryCard(s, key, label, placeholder, matchLabel);
			}
			return '' +
				card('Output ratio', seg('ratio', s.ratio, [['4:5', '4:5', 'post'], ['9:16', '9:16', 'story / reel']])) +
				card('Fixed by the master prompt',
					'<p class="spg-hint spg-fixed">Standing pose · True 1st-person self-view (calves to toes) · Yoga mat, evening · Phase One macro</p>') +
				card('Camera & framing',
					cameraFields(s, [FEET_CAM_ANGLES, FEET_CAM_CROPS, FEET_CAM_PLACEMENTS], [
						'How steep the look down is', 'How close the shot is', 'Where my feet sit in the frame',
						'e.g. the frame is rotated slightly to the left'
					]) +
					'<p class="spg-hint">The self-view stays fixed: shins always rise from the bottom edge and the toes point up the frame.</p>') +
				card('Stance',
					textArea('pose', s.pose, 4, 'How I am standing, e.g. weight on my left foot, right heel lifted, toes gently spread') +
					aiInline('feet_pose', 'pose', [
						'Weight on my left foot, right heel lifted, toes gently spread',
						'Feet side by side, toes lifted off the floor',
						'One foot crossed in front of the other, ankles close'
					]) +
					libBlock('feet_pose', 'Saved stances', 'Save this stance', 'Name this stance, e.g. Right heel lifted') +
					'<p class="spg-hint">Leave it empty to let the master prompt decide how the feet are placed.</p>') +
				card('Nail polish',
					choices('polish', s.polish, [['painted', 'Painted', 'Pick a cute polish color'], ['bare', 'Bare / unpainted', 'Natural keratin, no polish']]) +
					showIf('polish=painted', colorPicker(s, '#C2185B', TOE_PALETTE))) +
				jewelry('anklet', 'Anklets', 'e.g. slim gold anklets on both ankles', 'Match the anklets in my reference image') +
				jewelry('toeRing', 'Toe ring', 'e.g. a thin silver toe ring on the second toe of the right foot', 'Match the toe ring in my reference image') +
				card('Clothing',
					sub('Outfit — lower body, visible down to the ankles') +
					select('clothingPick', s.clothingPick, selectGroups(LOWER_OUTFITS)) +
					showIf('clothingPick=custom',
						'<div class="spg-field">' +
						prefixField('clothingCustom', s.clothingCustom, 'A glimpse of', 'a split dress in red design', ', only up to the ankles.') +
						aiInline('feet_clothing', 'clothingCustom', [
							'Red split dress with a gold border',
							'Flowy white linen palazzo pants',
							'Black satin slip skirt',
							'Pastel pink pleated maxi skirt'
						]) + '</div>') +
					showIf('clothingPick!=',
						check('clothingColorOn', s.clothingColorOn, 'Apply a color to the outfit') +
						showIf('clothingColorOn=true', colorPicker(s, '#B00020', FASHION_PALETTE, 'clothingColor', 'Outfit color')))) +
				card('Footwear',
					choices('footwear', s.footwear, [['barefoot', 'Barefoot', 'Default'], ['custom', 'Footwear', 'Describe it or match reference']]) +
					showIf('footwear=custom',
						'<div class="spg-field">' + textInput('footwearText', s.footwearText, 'e.g. nude open-toe block-heel sandals with thin straps') + '</div>' +
						check('footwearMatch', s.footwearMatch, 'Match the footwear in my reference image')));
		},

		build: function (s) {
			var hex = normalizeHex(s.color) || '#C2185B';
			var name = colorName(hex, TOE_PALETTE);
			var custom = name === 'Custom color';

			var polish = s.polish === 'bare' ? FEET_BARE :
				'Nail polish: Apply ' + (custom ? 'custom-color' : name.toLowerCase()) + ' nail polish (Nail polish color: ' + hex + ') only to the existing surface of the extremely long keratin nails. The ' +
				(custom ? hex : name.toLowerCase() + ' (' + hex + ')') + ' color must extend all the way to the very tip of the long, untrimmed nail.\n' + FEET_POLISH_CONSTRAINT;

			var footwear;
			if (s.footwear !== 'custom') footwear = 'Barefoot.';
			else {
				footwear = sentence(s.footwearText) || (s.footwearMatch ? 'Exactly as shown in the reference image.' : 'Open-toe footwear.');
				if (s.footwearMatch) footwear += ' ' + matchRef('footwear');
				footwear += ' The toes and the extremely long toenails remain fully visible and uncovered.';
			}

			var acc = [];
			if (s.anklet) acc.push((sentence(s.ankletText) || 'Slim gold anklets.') + (s.ankletMatch ? ' ' + matchRef('anklets') : ''));
			if (s.toeRing) acc.push('Toe ring: ' + (sentence(s.toeRingText) || 'A delicate toe ring.') + (s.toeRingMatch ? ' ' + matchRef('toe ring', true) : ''));

			var clothing = feetClothingPhrase(s);

			var camera = cameraLineFrom([
				['The camera is', s.camHeight],
				['The shot is framed as', s.camCrop],
				['My feet sit', s.camPlacement]
			], s.camNote);
			var part1 = camera
				? FEET_PART_1.replace('Viewing angle: From claves to toes. ',
					'Viewing angle: From claves to toes.\nCamera & Framing: ' + camera + ' ')
				: FEET_PART_1;

			return [
				part1 + (s.pose.trim() ? '\nStance: ' + sentence(s.pose.trim()) : ''),
				'Footwear: ' + footwear,
				'Accessories: ' + (acc.length ? acc.join(' ') : 'None.'),
				clothing
					? 'Clothing: A glimpse of ' + clothing + ', only up to the ankles.'
					: 'Clothing: None visible in the frame; the legs are bare down to the ankles.',
				' ',
				polish,
				'',
				'',
				FEET_PART_3,
				FEET_RATIO[s.ratio] || FEET_RATIO['4:5']
			].join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (s.clothingPick === 'custom' && !cleanClothing(s.clothingCustom)) w.push('Custom clothing is empty.');
			if (s.anklet && !s.ankletText.trim() && !s.ankletMatch) w.push('Anklets are on but not described.');
			if (s.toeRing && !s.toeRingText.trim() && !s.toeRingMatch) w.push('Toe ring is on but not described.');
			if (s.footwear === 'custom' && !s.footwearText.trim() && !s.footwearMatch) w.push('Footwear is on but not described.');
			return w;
		},

		context: function (s, kind) {
			return {
				'Pose': 'Standing, true 1st-person self-view looking down from the calves to the toes' +
					(kind !== 'feet_pose' && s.pose.trim() ? '. ' + s.pose.trim() : ''),
				'Camera': cameraLineFrom([
					['The camera is', s.camHeight],
					['The shot is framed as', s.camCrop],
					['My feet sit', s.camPlacement]
				], s.camNote),
				'Location': 'Yoga mat floor, evening',
				'Nail polish': s.polish === 'bare' ? 'Bare, unpainted' : colorName(s.color, TOE_PALETTE) + ' ' + s.color,
				'Anklets': s.anklet ? (s.ankletText || 'as in the reference image') : 'none',
				'Toe ring': s.toeRing ? (s.toeRingText || 'as in the reference image') : 'none',
				'Clothing': feetClothingPhrase(s),
				'Footwear': s.footwear === 'custom' ? (s.footwearText || 'as in the reference image') : 'Barefoot',
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			return [
				s.polish === 'bare' ? 'Bare nails' : colorName(s.color, TOE_PALETTE) + ' ' + s.color,
				camSummary(s, FEET_CAM_ANGLES, FEET_CAM_CROPS, FEET_CAM_PLACEMENTS),
				s.anklet ? 'Anklets' : '',
				s.toeRing ? 'Toe ring' : '',
				feetClothingPhrase(s),
				s.footwear === 'custom' ? 'Footwear' : 'Barefoot',
				s.ratio
			].filter(Boolean).join(' · ');
		}
	};

	/* ================================================================== *
	 * FEET — CAROUSEL frame (new photo moments after the reference)
	 * ================================================================== */

	var CAROUSEL_PART_1 = [
		'## Technical Reasoning Instructions (The "Realism Engine"): 1. Anatomy Physics: Calculate skeletal structure. Ensure phalanges align to support nail weight in the position. 2. Light Physics (Advanced SSS): Simulate deep Subsurface Scattering. Intense light must penetrate the translucent keratin tips of the natural nails, making them glow slightly against the opaque nail bed. Light should also scatter through the thinnest skin on the top of the foot, revealing subsurface depth. 3. Texture Mapping Physics: Disable any smoothing algorithms, HDR, or digital beauty filters. Prioritize "High-Frequency Texture Rendering." Treat skin pores, fine hairs, cuticle ridges, and keratin micro-topography as essential details that must be resolved with 100% clarity, zero noise, and zero compression artifacts. Introduce natural skin mottling and uneven pigmentation.',
		'## Image Generation Prompt:',
		'4. Camera & Sensor Data (The Close-Range Clarity Set): Device: Simulated shot on a Phase One IQ4 150MP Medium Format Camera. Lens: Rodenstock HR Digaron-W 90mm f/5.6 Macro (A specialized lens for ultra-sharp, close-range photography with zero distortion). Aperture: f/16 (Very narrow aperture to ensure deep focus across both feet, despite the close range). ISO: ISO 50 (Absolute zero grain, crystal clear). Shutter: Tripod-stabilized shot to eliminate all micro-blur, ensuring frozen sharpness. Color Science: Uncompressed RAW format (.IIQ). Straight Out Of Camera (SOOC) with a flat, neutral color profile. Absolutely zero post-processing, zero color grading, and zero artificial HDR lighting.',
		'5. The Subject, Pose & POV ',
		'Based on the environment and subject in the reference image, generate a completely new photograph taken moments later.'
	].join('\n');

	var CAROUSEL_ACTION = 'a. The New Action: The feet have moved into a new pose. ';

	var CAROUSEL_PERSPECTIVE = {
		first: 'Perspective (TRUE 1st-Person Self-View): The camera represents the subject\'s own eyes, looking straight down the front of her own body toward the floor. Framing Constraint: The subject\'s own legs/shins/clothing MUST originate from the absolute BOTTOM EDGE (or bottom corners) of the frame—closest to the viewer. The legs extend outward, leading to the ankles in the middle of the frame. The toes MUST point AWAY from the camera, toward the TOP half of the frame. Use strong visual foreshortening on the shins to convey the steep downward angle. There is no second person; this is exactly what she sees when looking down at her own feet.',
		third: 'Perspective (3rd-Person External Observer): The camera is held by a second person positioned in front of or beside the subject, looking AT her feet from a low, close angle near floor level. Framing Constraint: The feet and ankles are the main subject in the center of the frame. The legs/shins MUST NOT rise from the bottom edge of the frame as in a self-view; instead they enter from the TOP or SIDE edges of the frame. The toes point TOWARD the camera or to the side, never away from it. No self-view foreshortening of the shins. Only the feet, ankles and lower shins are in frame; the subject\'s face and upper body are not shown. If the reference image is a 1st-person self-view, this external viewpoint deliberately replaces it; everything else stays consistent with the reference.'
	};

	var CAROUSEL_FOCUS_FIRST = 'The shins/clothing at the bottom edge of the frame are slightly out-of-focus due to being too close to the lens.';
	var CAROUSEL_FOCUS_THIRD = 'The lower shins/clothing at the edge of the frame fall slightly out-of-focus, keeping all attention on the feet.';
	var CAROUSEL_VIBE_FIRST = 'close-range macro photography from a personal perspective';
	var CAROUSEL_VIBE_THIRD = 'close-range macro photography from an observer\'s perspective';

	var CAROUSEL_STRAP = 'Anatomically accurate tendon stretch along the heel, realistic skin compression where toes meet the sandal strap ';
	var CAROUSEL_NO_STRAP = 'Anatomically accurate tendon stretch along the heel, realistic skin compression where the toes and soles press against the surface.';

	var CAROUSEL_PART_2 = [
		'b. The Critical Constraint (Camera Shift): To capture this new pose realistically, the camera MUST shift its perspective slightly from the original shot. Do not use the exact same framing.',
		'Action: Tilt the camera down slightly further OR rotate the angle slightly to the side.',
		'Result: This shift must cause the background floor/surface pattern to shift position in the frame, and reveal slightly new areas of the surrounding environment that were previously just outside the frame.',
		'c. Keep Consistent: The outfit, the type of flooring material, lighting conditions, and nail length/shape must remain the same as the reference.',
		'',
		'',
		'Focus: Razor-sharp focus on the toenails and skin texture of both feet. The shins/clothing at the bottom edge of the frame are slightly out-of-focus due to being too close to the lens. ' + TOENAIL_GEOMETRY_LOCK,
		'',
		'',
		'6. Environment & Wardrobe:',
		''
	].join('\n');

	var CAROUSEL_PART_3 = [
		'Background: Ensure the background is fully detailed, textured, and clear, avoiding heavy, unnatural blurring.',
		'',
		'Footwear: Same as the reference image',
		'Accessories: Same as the reference image',
		'Clothing: Same as the reference image',
		' ',
		'',
		'',
		'',
		'',
		'7. Material & Biological Realism (4K Detail): Nail Material: Natural keratin. Show micro-ridges, varying transparency at the tips, and the "lunula" (moons) at the base. They must not look plastic. Cuticles: "Biological" and imperfect. Show freshly oiled cuticles with a distinct ridge where skin meets nail. Skin Texture (Hyper-Real & Raw): Visible pores, individual fine hairs on the toes, and subsurface vascularity (faint blue veins) showing through the thin skin of the foot dorsum. Crucial: Include natural, raw micro-imperfections like slight skin redness around the knuckles, varying skin tones, and microscopic dust motes on the skin to shatter the "AI perfect" look. Pressure Physics: Subtle compression where the heel and side of the foot press against the surface.',
		'8. Vibe & Aesthetic: Style: Ultra-realistic, close-range macro photography from a personal perspective. Documentary realism. Aesthetic: Crystal clear, high definition, zero grain. Unedited, raw, everyday lighting. Do NOT use vibrant or overly saturated colors; tones must be organic, true-to-life, and slightly flat as seen in unedited RAW photography. "Tactile realism."'
	].join('\n');

	var CAROUSEL_RATIO = {
		'4:5': '9. Output Ratio: Instagram Post: Use 4:5.',
		'9:16': '9. Output Ratio: Instagram Story / Reel: Use 9:16.'
	};

	var FEET_MODES = [['feet', '1 · Create the look'], ['feet_carousel', '2 · Carousel frame']];
	FEET.modes = FEET_MODES;
	FEET.desc = 'Create the look, then carousel frames';

	var FEET_CAROUSEL = {
		id: 'feet_carousel',
		title: 'Feet',
		hidden: true,
		modes: FEET_MODES,
		icon: FEET.icon,
		defaults: {
			ratio: '4:5',
			perspective: 'first',
			camHeight: '',
			camHeightThird: '',
			camCrop: '',
			camPlacement: '',
			camNote: '',
			pose: '',
			strap: true,
			location: ''
		},
		libTargets: { carousel_pose: 'pose' },

		form: function (s) {
			return '' +
				'<p class="spg-mode-lead">Upload your finished look as the reference image in Gemini. This prompt makes the next carousel slide: same outfit, footwear, accessories, floor and light, a new pose, and a slightly shifted camera.</p>' +
				card('Output ratio', seg('ratio', s.ratio, [['4:5', '4:5', 'post'], ['9:16', '9:16', 'story / reel']])) +
				card('Perspective',
					choices('perspective', s.perspective, [
						['first', '1st person', 'Looking down at your own feet. Shins rise from the bottom edge, toes point up the frame.'],
						['third', '3rd person', 'Someone else shoots your feet from a low angle. Legs enter from the top or side, toes face the camera.']
					])) +
				card('Camera & framing',
					showIf('perspective=first', '<div class="spg-field">' + sub('How steep the look down is') +
						select('camHeight', s.camHeight, selectGroups(FEET_CAM_ANGLES)) + '</div>') +
					showIf('perspective=third', '<div class="spg-field">' + sub('Where the other person stands') +
						select('camHeightThird', s.camHeightThird, selectGroups(FEET_CAM_ANGLES_THIRD)) + '</div>') +
					'<div class="spg-field">' + sub('How close the shot is') +
					select('camCrop', s.camCrop, selectGroups(FEET_CAM_CROPS)) + '</div>' +
					'<div class="spg-field">' + sub('Where my feet sit in the frame') +
					select('camPlacement', s.camPlacement, selectGroups(FEET_CAM_PLACEMENTS)) + '</div>' +
					'<div class="spg-field">' + sub('Anything else about the camera (optional)') +
					textInput('camNote', s.camNote, 'e.g. rotated a little further to the right than the last frame') + '</div>' +
					camReset() +
					'<p class="spg-hint">The slide still shifts the camera from the reference frame; this says where it shifts to.</p>') +
				card('Pose',
					textArea('pose', s.pose, 4, 'e.g. weight shifted to the left foot, right heel lifted, toes gently spread') +
					aiInline('carousel_pose', 'pose', [
						'Right foot steps slightly forward, heel lifted',
						'Feet crossed at the ankles, left over right',
						'Toes gently spread and lifted off the floor',
						'Weight on the left foot, right foot turned out'
					]) +
					check('strap', s.strap, 'Wearing sandals (keep the “toes meet the sandal strap” physics line)') +
					libBlock('carousel_pose', 'Saved poses', 'Save this pose', 'Name this pose, e.g. Heel lift, right foot')) +
				card('Location',
					textArea('location', s.location, 3, 'e.g. same yoga mat on a light oak floor, evening lamp light') +
					aiInline('carousel_location', 'location', [
						'Same as the reference image',
						'Purple yoga mat on a light oak floor, evening',
						'Bedroom rug by the bed, warm lamp light'
					]) +
					locked('Always added after', BACKGROUND_LOCK)) +
				card('Kept from the reference image',
					'<p class="spg-hint spg-fixed">Footwear · Accessories · Clothing · Flooring · Lighting · Nail length &amp; shape · Camera shift (tilt down or rotate slightly)</p>');
		},

		build: function (s) {
			var pose = s.pose.trim().replace(/^POSE:\s*/i, '');
			var loc = s.location.trim().replace(/^Location:\s*/i, '');
			var third = s.perspective === 'third';
			var part2 = third ? CAROUSEL_PART_2.replace(CAROUSEL_FOCUS_FIRST, CAROUSEL_FOCUS_THIRD) : CAROUSEL_PART_2;
			var part3 = third ? CAROUSEL_PART_3.replace(CAROUSEL_VIBE_FIRST, CAROUSEL_VIBE_THIRD) : CAROUSEL_PART_3;
			var camera = cameraLineFrom([
				['The camera is', third ? s.camHeightThird : s.camHeight],
				['The shot is framed as', s.camCrop],
				['My feet sit', s.camPlacement]
			], s.camNote);
			return [
				CAROUSEL_PART_1,
				CAROUSEL_PERSPECTIVE[third ? 'third' : 'first'] +
					(camera ? '\nCamera & Framing: ' + camera : ''),
				CAROUSEL_ACTION,
				'',
				'',
				'POSE:  ' + pose,
				'',
				s.strap ? CAROUSEL_STRAP : CAROUSEL_NO_STRAP,
				'',
				'',
				'',
				part2,
				'Location:   ' + loc,
				part3,
				CAROUSEL_RATIO[s.ratio] || CAROUSEL_RATIO['4:5']
			].join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (!s.pose.trim()) w.push('Pose is empty.');
			if (!s.location.trim()) w.push('Location is empty.');
			return w;
		},

		context: function (s, kind) {
			return {
				'Slide': 'Next carousel slide, moments after the reference image; same outfit, footwear, accessories, flooring and light',
				'Viewpoint': s.perspective === 'third'
					? '3rd-person: a second person photographs her feet from a low, close angle; legs enter from the top or side edges, toes point toward the camera or to the side'
					: 'True 1st-person: she looks down at her own feet; shins rise from the bottom edge, toes point toward the top of the frame',
				'Camera': cameraLineFrom([
					['The camera is', s.perspective === 'third' ? s.camHeightThird : s.camHeight],
					['The shot is framed as', s.camCrop],
					['My feet sit', s.camPlacement]
				], s.camNote),
				'Footwear': s.strap ? 'Sandals with straps (as in the reference image)' : 'As in the reference image',
				'Pose': kind === 'carousel_pose' ? '' : s.pose,
				'Location': kind === 'carousel_location' ? '' : s.location,
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			var p = s.pose.trim().replace(/\s+/g, ' ');
			var cam = [shortLabel(s.perspective === 'third' ? FEET_CAM_ANGLES_THIRD : FEET_CAM_ANGLES,
				s.perspective === 'third' ? s.camHeightThird : s.camHeight, ''),
				shortLabel(FEET_CAM_CROPS, s.camCrop, ''), shortLabel(FEET_CAM_PLACEMENTS, s.camPlacement, '')].filter(Boolean).join(', ');
			return [s.perspective === 'third' ? '3rd person' : '1st person', cam, p.length > 50 ? p.slice(0, 50) + '…' : p, s.location.trim() ? 'Location set' : '', s.ratio].filter(Boolean).join(' · ');
		}
	};

	/* ================================================================== *
	 * SELFIE generator (accidental iPhone snapshot)
	 * ================================================================== */

	var SELFIE_MASTER = 'Please generate a photo of yourself that looks like an ordinary iPhone snapshot: no distinct subject, no deliberate composition; make it completely mundane, even a bit like a failed candid shot. In the photo, include slight motion blur, uneven lighting, mild overexposure, a weird angle, and a cluttered frame. Overall, give it the feel of an accidental selfie that\'s overly real and haphazard, as if it was snapped by mistake while pulling the phone out of your pocket.';

	var LIP_PALETTE = [
		{ group: 'Pinks', colors: [
			['Baby Pink', '#F4A7B9'], ['Rosy Pink', '#E8829A'], ['Bubblegum', '#FF6FA0'], ['Barbie Pink', '#E0218A'],
			['Peachy Pink', '#F4978E'], ['Mauve Pink', '#C47C8E'], ['Watermelon', '#F25C6E']
		] },
		{ group: 'Nudes', colors: [
			['Pinky Nude', '#D8A39D'], ['Warm Nude', '#C98B75'], ['Dusty Rose', '#C08081'],
			['Rosewood', '#A45A52'], ['Caramel', '#B5734F'], ['Cinnamon', '#9A5B45']
		] },
		{ group: 'Reds & berries', colors: [
			['Strawberry', '#E0404A'], ['Cherry Red', '#C21E36'], ['Classic Red', '#B3001B'],
			['Raspberry', '#B0305C'], ['Berry', '#8E2A4F'], ['Wine', '#6D1A36'], ['Plum', '#5E2B4A']
		] },
		{ group: 'Corals', colors: [
			['Coral', '#F0776A'], ['Peach', '#F7A58B'], ['Apricot', '#F29E7A']
		] }
	];

	var BLUSH_PALETTE = [
		{ group: 'Cute blushes', colors: [
			['Baby Pink', '#F7C6CF'], ['Soft Pink', '#F4AFC0'], ['Rosy', '#E89AA8'], ['Strawberry Milk', '#F2A7B5'],
			['Peach', '#F8B99A'], ['Apricot', '#F4A582'], ['Coral', '#F28B82'], ['Berry Flush', '#D26A86'],
			['Mauve', '#C9899F'], ['Warm Bronze', '#C98E6B']
		] }
	];

	/* Phrases read naturally after "I have long hair, styled in …" */
	var HAIR_STYLES = [
		{ group: 'Loose & natural', items: [
			['loose beach waves', 'Loose beach waves'],
			['a sleek straight middle part', 'Sleek straight, middle part'],
			['a sleek deep side part', 'Sleek straight, deep side part'],
			['soft bouncy blowout curls', 'Bouncy blowout curls'],
			['long layers with curtain bangs', 'Long layers + curtain bangs'],
			['face-framing butterfly layers', 'Butterfly layers'],
			['tousled messy bedhead waves', 'Messy bedhead waves'],
			['glossy Hollywood waves swept to one side', 'Hollywood waves'],
			['a voluminous 90s blowout', '90s voluminous blowout'],
			['long mermaid curls', 'Mermaid curls'],
			['soft crimped waves', 'Crimped waves'],
			['straight hair tucked behind both ears', 'Tucked behind the ears']
		] },
		{ group: 'Ponytails & buns', items: [
			['a high sleek ponytail', 'High sleek ponytail'],
			['a low sleek ponytail', 'Low sleek ponytail'],
			['a bouncy high ponytail with a scrunchie', 'Ponytail with scrunchie'],
			['a bubble ponytail', 'Bubble ponytail'],
			['a messy top bun with loose face-framing strands', 'Messy top bun'],
			['an effortless claw-clip twist', 'Claw-clip twist'],
			['a low chignon', 'Low chignon'],
			['two space buns', 'Space buns']
		] },
		{ group: 'Half-up', items: [
			['a half-up half-down style with a claw clip', 'Half-up with claw clip'],
			['a half-up half-down style tied with a satin bow', 'Half-up with satin bow'],
			['a half-up top knot with the rest loose', 'Half-up top knot']
		] },
		{ group: 'Braids', items: [
			['one long loose side braid', 'Side braid'],
			['two pigtail braids', 'Two pigtail braids'],
			['a fishtail braid over one shoulder', 'Fishtail braid'],
			['two Dutch braids', 'Dutch braids'],
			['a braided crown', 'Braided crown'],
			['boho braids woven into loose waves', 'Boho braids']
		] },
		{ group: 'With accessories', items: [
			['loose waves with a padded headband', 'Padded headband'],
			['loose waves with a silk scarf headband', 'Silk scarf headband'],
			['loose waves pinned with pearl barrettes', 'Pearl barrettes'],
			['loose waves with a big satin bow at the back', 'Big satin bow'],
			['a wet-look slicked-back style', 'Wet-look slicked back']
		] },
		{ group: 'Other', items: [
			['match', 'Same as my reference image'],
			['custom', 'Custom… (type your own)']
		] }
	];

	var OUTFITS = [
		{ group: 'Cozy & casual', items: [
			['an oversized off-shoulder knit sweater', 'Off-shoulder knit sweater'],
			['a fitted ribbed baby tee', 'Ribbed baby tee'],
			['a cropped cardigan buttoned up', 'Cropped cardigan'],
			['an oversized hoodie', 'Oversized hoodie'],
			['an oversized graphic tee', 'Oversized graphic tee'],
			['a cozy matching lounge set', 'Lounge set'],
			['a fitted turtleneck', 'Fitted turtleneck'],
			['a soft pajama set with piping', 'Pajama set']
		] },
		{ group: 'Tops', items: [
			['a lace-trim cami top', 'Lace-trim cami'],
			['a fitted square-neck top', 'Square-neck top'],
			['a white button-up shirt, loosely open at the collar', 'Button-up shirt'],
			['a puff-sleeve blouse', 'Puff-sleeve blouse'],
			['a halter-neck knit top', 'Halter knit top'],
			['a corset-style top', 'Corset top']
		] },
		{ group: 'Dresses', items: [
			['a satin slip dress', 'Satin slip dress'],
			['a floral sundress with thin straps', 'Floral sundress'],
			['a milkmaid dress with puff sleeves', 'Milkmaid dress'],
			['a babydoll dress with bow details', 'Babydoll dress'],
			['a ribbed knit bodycon dress', 'Ribbed bodycon dress'],
			['a wrap dress', 'Wrap dress']
		] },
		{ group: 'Layers', items: [
			['a cropped denim jacket over a tank top', 'Denim jacket + tank'],
			['an oversized blazer over a fitted tee', 'Oversized blazer'],
			['a leather jacket over a simple top', 'Leather jacket'],
			['a cozy teddy coat', 'Teddy coat'],
			['a matching Pilates set with a zip-up jacket', 'Pilates set']
		] },
		{ group: 'Traditional', items: [
			['a kurti with delicate embroidery', 'Embroidered kurti'],
			['a saree with a fitted blouse', 'Saree'],
			['a salwar kameez with a light dupatta', 'Salwar kameez']
		] },
		{ group: 'Other', items: [
			['match', 'Same as my reference image'],
			['custom', 'Custom… (type your own)']
		] }
	];

	var HAND_SPOTS = [
		['lips', 'Fingertips on my lips', 'my fingertips lightly touching my lips'],
		['chin', 'Under my chin', 'my fingers resting softly under my chin'],
		['cheek', 'On my cheek', 'my palm gently resting on my cheek'],
		['forehead', 'On my forehead', 'my hand on my forehead, pushing my hair back'],
		['chest', 'On my chest', 'my hand resting flat on my chest'],
		['collarbone', 'On my collarbone', 'my fingertips lightly touching my collarbone'],
		['hair', 'Running through my hair', 'my fingers running through my hair'],
		['ear', 'Tucking hair behind ear', 'my fingers tucking a strand of hair behind my ear'],
		['peace', 'Peace sign by my face', 'a casual peace sign next to my face']
	];

	/* ------------------------------------------------------------------ *
	 * Realism blocks
	 *
	 * The selfie has one job: come back looking like a real photo off a
	 * real phone. Everything below is written as plain instructions to the
	 * image model, in the order it tends to get things wrong — identity,
	 * face shape, skin, eyes, fabric, camera, and a last "look again"
	 * pass that names the tells that give an AI image away.
	 * ------------------------------------------------------------------ */

	/* Eye colour. [phrase, label] — reads after "my eyes are …". */
	var EYE_COLORS = [
		{ group: 'Blue', items: [
			['a believable natural blue \u2014 a mid-tone denim blue with a darker outer ring and flecks of grey, the way real blue eyes look in daylight rather than a bright glowing contact-lens blue', 'Natural blue'],
			['a soft light icy blue with a pale centre and a distinctly darker limbal ring, still clearly a real human eye colour', 'Light icy blue'],
			['a deep steel blue that reads almost grey-blue in indoor light', 'Deep steel blue'],
			['a blue-grey that shifts between blue and grey depending on the light', 'Blue-grey']
		] },
		{ group: 'Green & hazel', items: [
			['a slight green, subtle rather than vivid', 'Slight green'],
			['a soft hazel with green and warm brown mixed through the iris', 'Soft hazel']
		] },
		{ group: 'Brown', items: [
			['a warm light brown with golden flecks near the pupil', 'Warm light brown'],
			['a deep natural dark brown with visible iris texture in the light', 'Deep dark brown']
		] },
		{ group: 'Other', items: [
			['match', 'Same as my reference image'],
			['custom', 'Custom\u2026 (type your own)']
		] }
	];

	/* Skin tone. [phrase, label] — a full sentence dropped into the skin block. */
	var SKIN_TONES = [
		{ group: 'From my photo', items: [
			['Keep exactly the skin tone I have in my reference image \u2014 the same depth and the same undertone, neither lighter nor darker, never brightened or whitened.', 'Same as my reference image']
		] },
		{ group: 'Warm tones', items: [
			['My skin is fair with warm golden undertones \u2014 still obviously real human skin with colour in it, never bleached or porcelain white.', 'Fair, warm golden'],
			['My skin is a light wheatish tone with warm yellow-golden undertones.', 'Light wheatish'],
			['My skin is a medium wheatish tone with warm golden-olive undertones.', 'Medium wheatish'],
			['My skin is a warm golden tan with visible sun warmth across my nose and cheeks.', 'Golden tan'],
			['My skin is a deep warm brown with rich golden undertones and a healthy natural sheen.', 'Deep warm brown']
		] },
		{ group: 'Neutral & cool', items: [
			['My skin is light with neutral undertones and a faint natural pink flush across the cheeks and nose.', 'Light neutral'],
			['My skin is light-medium with cool rosy undertones, still warm enough to look alive rather than grey.', 'Light, cool rosy']
		] },
		{ group: 'Other', items: [
			['custom', 'Custom\u2026 (type your own)']
		] }
	];

	/* Wakes the model up before it starts: this is a photorealism task. */
	var SELFIE_REALISM_HEADER = 'Before you generate anything, stop and read every instruction in this prompt, then follow all of them at once. This is a photorealism task, not an illustration or a render. You are producing one single image that has to be completely believable as a real photograph of a real person, taken on a real iPhone, in the real world. Not 95% believable \u2014 it must not feel even 1% AI-generated to someone scrolling past it in a camera roll. Every instruction below is a hard requirement, not a suggestion.';

	var SELFIE_IDENTITY_LOCK = 'Identity lock (this is me, not a lookalike): The face in the photo is my real face exactly as in my reference image \u2014 the same bone structure, face shape and jawline, the same eye shape and spacing, the same nose, the same lip shape, the same hairline and the same skin tone. Anyone who knows me has to recognise me instantly, at a glance. Do not beautify me into a generic model face, do not slim, sharpen or reshape my features, do not change my ethnicity, and do not drift towards the averaged \u201cAI girl\u201d face. Keep my natural asymmetry: one eye set slightly differently from the other, one eyebrow a little higher, a mouth that is not perfectly even, one side of my face catching the light differently.';

	var SELFIE_FACE_LOCK = 'Feminine, cute face shaping (subtle \u2014 real, never plastic): Keep the overall look soft, young and feminine. Soft face framing, with hair falling naturally over the sides of my forehead and a few strands crossing it, so less forehead is on show. A rounded, gently tapered jaw and a soft chin. Soft youthful cheeks with natural fullness on the apples. A refined, small, naturally feminine nose with a soft rounded tip, a gentle bridge and realistic nostril shape \u2014 real nose anatomy, never a filter-slimmed or digitally sharpened nose. Cute, softly full lips with a clearly shaped but natural cupid\u2019s bow, a slightly fuller lower lip, fine vertical lip lines, a soft natural lip edge that blends into the skin instead of a drawn-on outline, and a little natural moisture where the light hits. All of this stays inside what a real human face can look like: no doll proportions, no enlarged anime eyes, no shrunken filter chin.';

	function eyeDetailText(s, makeup) {
		var t = 'Eyes, brows and lashes (photographed detail, not painted): My eyes are ' + eyePhrase(s) + '. ' +
			'The irises show real structure \u2014 fine radial fibres, slightly uneven pigment, a visibly darker limbal ring at the outer edge, a soft pupil edge, and a small catchlight whose shape matches the actual light source in the room. Never a glowing iris, never a painted-on sparkle, and the two eyes are never identical copies of each other. The whites of my eyes are softly off-white with faint natural vessels and a little shadow from the upper lid, never pure clinical white. ' +
			'Thick, full, beautiful eyebrows built from individual visible hairs growing in their natural direction, slightly different from each other, denser at the inner end and softly tapering out, with real edges rather than a sharp filled-in block. ' +
			'Naturally long, dark, dense eyelashes of varying lengths that curve realistically, separate into small natural clusters, cast fine shadows on the skin, and are visible as individual lashes on the lower lash line too \u2014 never a solid painted lash line, never a repeated identical lash pattern.';
		if (makeup) {
			t += ' Everyday eye makeup: soft kajal on the waterline and along the upper lash line, slightly smudged and a little imperfect the way real kajal wears through the day, plus a thin coat of mascara with visible texture and a couple of lashes naturally clumped together. Brows softly groomed and lightly filled but still unmistakably real hair. Nothing graphic, nothing airbrushed.';
		}
		return t;
	}

	function skinText(s) {
		return 'Skin (the single most important thing in this photo): My skin has to read as real human skin under a real camera, not as rendered or retouched skin. ' +
			skinTonePhrase(s) + ' Never make me pale, porcelain, bleached or ultra-white, and never grey, flat or ashy. ' +
			'Show real skin structure at close range: visible pores, larger across the nose and inner cheeks and finer on the forehead; fine peach fuzz catching the light along the jaw, the hairline and the upper lip; a subtle natural sheen on the T-zone with the rest of my face staying matte; faint under-eye shadows and the natural texture of the skin under the eyes; slightly uneven tone with natural warmth around the nose, cheeks, eyelids and ears; any small moles, freckles, marks or blemishes left exactly as they are, unretouched; and real subsurface light through the ears, the tip of the nose and the lips. ' +
			'No smoothing, no airbrushing, no beauty filter, no skin-tone unification, no wax, plastic, porcelain or CGI sheen, no glow overlay.';
	}

	var SELFIE_FABRIC_LOCK = 'Clothing realism: The outfit is real fabric photographed under real light. The weave or knit is visible up close; the fabric falls with natural folds, creases and drape that follow my body and gravity; soft wrinkles gather where it bends at the elbows, waist and shoulders; seams, stitching, hems and edges are visible and correctly placed; there is slight everyday wear, a little pilling or a soft crease where a real garment would have it. The material behaves like itself \u2014 knit stretches and bunches, cotton creases, satin catches sharp narrow highlights, denim holds its shape. No painted-on clothing, no perfectly smooth CGI cloth, no impossible folds, no melted or disappearing seams.';

	var SELFIE_ANTI_AI = 'Final check before you output \u2014 look at the image again as if you were a stranger: it has to be indistinguishable from an ordinary photograph taken by a real person on a real iPhone. If anything in it would read as AI-generated, fix it and generate again. Specifically: nothing waxy, smoothed, glowing, perfectly symmetrical or \u201ctoo perfect\u201d; the correct five fingers per hand with real joints; ears, earrings and jewellery shaped correctly and matching each other; hair as separate strands with a natural hairline and stray flyaways, never a fused helmet or hair floating free of the head; background objects solid and structurally sensible, with straight lines straight and no melted or duplicated shapes; no nonsense lettering on anything in the frame; reflections and shadows that follow one consistent light source. No watermark, caption, border, logo, frame or AI signature of any kind.';

	var SELFIE_HAND_LOCK = 'Hand & nail realism (match my reference image): Every visible hand is my real hand exactly as in the reference image — slender, youthful, feminine fingers with my exceptionally long natural nails. Preserve the exact extreme length, thickness, natural growth direction, C-curve and shape of the nails from the reference; never shorten them or turn them into acrylics or press-ons. Exactly five fingers per hand with the thumb on the correct side, natural knuckle creases, soft skin with real pores, faint veins and freshly oiled cuticles. The long nails stay fully visible, stable and sharp with natural shadows; they never bend, break, clip through objects or get cut off by the frame edge.';

	var SELFIE_MIRROR = 'Mirror selfie: This is a casual mirror selfie. I am holding a black iPhone 15 Pro (Black Titanium) in one hand in front of me, and the phone is visible in the mirror reflection with its triple-lens camera module facing the mirror. My fingers and long nails wrap naturally around the edges of the phone without covering the lenses. The reflection follows real mirror physics (correct left-right reversal, slight smudges or fingerprints on the glass, consistent lighting).';

	var SELFIE_GAZE = {
		camera: 'Looking straight at the camera lens, eyes on the lens (in a mirror selfie, at my own reflection)',
		away: 'Looking away from the camera: my eyes are off to the side or past the lens, as if I did not notice the photo being taken',
		auto: ''
	};

	/* The mirror block keeps the feel of whichever shot type is chosen. */
	function mirrorText(shot) {
		return SELFIE_MIRROR + (shot === 'clear'
			? ' It still reads as an ordinary real mirror selfie, not a staged or generated photo.'
			: ' It still keeps the accidental, haphazard feel.');
	}

	function eyePhrase(s) {
		if (s.eyeColor === 'match') return 'exactly the eye colour I have in my reference image';
		if (s.eyeColor === 'custom') return s.eyeCustom.trim().replace(/[.\s]+$/, '') || 'a believable natural blue';
		return s.eyeColor;
	}

	function skinTonePhrase(s) {
		if (s.skinTone === 'custom') {
			var t = s.skinCustom.trim();
			return t ? cap(sentence(t)) : SKIN_TONES[0].items[0][0];
		}
		return s.skinTone || SKIN_TONES[0].items[0][0];
	}

	function hairPhrase(s) {
		if (s.hair === 'match') return 'styled exactly as in my reference image';
		if (s.hair === 'custom') return 'styled in ' + (s.hairCustom.trim().replace(/[.\s]+$/, '') || 'loose natural waves');
		return 'styled in ' + s.hair;
	}

	function outfitPhrase(s) {  /* Selfie: keeps its own "match reference" option. */
		var base;
		if (s.outfit === 'match') return 'exactly the same outfit as in my reference image';
		base = s.outfit === 'custom' ? (s.outfitCustom.trim().replace(/[.\s]+$/, '') || 'a casual everyday top') : s.outfit;
		if (!s.outfitColorOn) return base;
		var hex = normalizeHex(s.outfitColor) || '#F4C2C2';
		var name = colorName(hex, FASHION_PALETTE);
		return base + ' in ' + (name === 'Custom color' ? 'the color ' + hex : name.toLowerCase() + ' (' + hex + ')');
	}

	function spotText(id) {
		var hit = HAND_SPOTS.filter(function (h) { return h[0] === id; })[0];
		return hit ? hit[2] : HAND_SPOTS[0][2];
	}

	/* Clear-shot master: a real, well-taken phone photo instead of an accidental one. */
	var SELFIE_CLEAR_MASTER = 'Please generate a photo of yourself that looks like an ordinary, clear photo taken on a modern phone camera — the kind a real person takes of themselves on a normal day and actually keeps. It is properly in focus on my face, well exposed and steady, with no motion blur and no accidental tilt, but it is still a casual everyday snapshot rather than a professional portrait or a staged photoshoot.';

	/* Always added: the photo has to read as a real iPhone shot, never as AI. */
	var SELFIE_IPHONE_LOCK = 'Shot on a real iPhone (this must not look AI-generated): This is an ordinary JPEG straight out of an iPhone camera roll — not a digital illustration, not a 3D render, not a stock photo and not a studio portrait. Reproduce how an iPhone actually captures a face: the slightly wide front-camera field of view with mild distortion near the edges, Smart HDR\u2019s fairly flat highlights and lifted shadows, natural to slightly cool white balance, a faint amount of luminance noise in the darker areas, and the light over-sharpening the phone applies by itself. Keep my skin exactly as real skin looks — visible pores, fine peach fuzz, faint under-eye shadows, slightly uneven tone, any small blemishes or marks left unretouched, a few stray flyaway hairs, and the natural asymmetry between the two sides of my face. No beauty filter, no skin smoothing, no airbrushing, no waxy or plastic skin, no artificial glow or bloom, no HDR halos, no heavy cinematic color grading, no studio lighting rig, no perfect symmetry, no glossy CGI sheen, and no added text, watermark, border or logo. Match how the phone actually renders a scene: a natural handheld depth of field where my face is sharp and the background falls off softly and unevenly rather than into a perfect creamy blur, highlights that clip a little where a window or lamp is behind me, colour that shifts slightly towards whatever light is actually in the room, fine sensor noise and light JPEG compression visible in the shadows and flat areas, and a touch of chromatic fringing on the highest-contrast edges.';

	var SELFIE_IPHONE_TAIL = {
		clear: ' The photo itself is sharp and correctly exposed, but the framing stays a little imperfect and human, the way a real handheld shot is.',
		accidental: ' All of this still applies even though the shot is blurry, badly framed and accidental — the flaws must look like genuine camera mistakes, not a stylised \u201cvintage\u201d or \u201cglitch\u201d effect.'
	};

	function iphoneLock(shot) {
		return SELFIE_IPHONE_LOCK + (SELFIE_IPHONE_TAIL[shot] || SELFIE_IPHONE_TAIL.accidental);
	}

	/* Camera: how high the phone is held and how it is angled. [phrase, label] */
	var CAM_HEIGHTS = [
		{ group: 'Above me', items: [
			['held a little above my eye level and angled slightly down at me', 'Slightly above, angled down'],
			['held well above my head and clearly angled down at me', 'High above, looking down'],
			['held at arm\u2019s length above me while I look up into the lens', 'Overhead, me looking up']
		] },
		{ group: 'Level with me', items: [
			['held straight in front of me at my eye level', 'Eye level, straight on'],
			['held at eye level but off to one side of my face, so the angle is a little sideways', 'Eye level, off to one side']
		] },
		{ group: 'Below me', items: [
			['held just below my chin and angled slightly up at me', 'Slightly below, angled up'],
			['held low near my chest and angled up at me', 'Low, looking up'],
			['resting low on a surface and pointing up at me', 'Propped low, pointing up']
		] },
		{ group: 'Other', items: [
			['', 'Let the pose decide']
		] }
	];

	/* Camera: how much of me the frame contains. [phrase, label] */
	var CAM_CROPS = [
		{ group: 'Close', items: [
			['a tight close-up of my face that fills most of the frame', 'Tight face close-up'],
			['a head-and-shoulders crop', 'Head and shoulders']
		] },
		{ group: 'Medium', items: [
			['a chest-up crop', 'Chest up'],
			['a waist-up crop', 'Waist up']
		] },
		{ group: 'Wide', items: [
			['a half-body shot from the thighs up', 'Half body'],
			['a full-body shot with all of me inside the frame', 'Full body']
		] },
		{ group: 'Other', items: [
			['', 'Let the pose decide']
		] }
	];

	/* Camera: where I actually sit inside the frame. [phrase, label] */
	var CAM_PLACEMENTS = [
		{ group: 'Vertical', items: [
			['in the middle of the frame, with clear empty space above my head', 'Middle, space above my head'],
			['low in the frame, with a lot of background above me', 'Low in the frame'],
			['high in the frame, with the top of my head close to the top edge', 'High, head near the top'],
			['filling the frame, cropped in tight with almost no empty space around me', 'Filling the frame']
		] },
		{ group: 'Horizontal', items: [
			['off to the left of the frame, with the background filling the right side', 'Off to the left'],
			['off to the right of the frame, with the background filling the left side', 'Off to the right'],
			['slightly cut off by the edge of the frame, the way a rushed snapshot crops badly', 'Slightly cut off by the edge']
		] },
		{ group: 'Other', items: [
			['', 'Let the pose decide']
		] }
	];

	/* One "* Camera:" line from the three dropdowns plus the free-text note. */
	function cameraLine(s) {
		var parts = [];
		if (s.camHeight) parts.push('The phone is ' + s.camHeight);
		if (s.camCrop) parts.push('The shot is framed as ' + s.camCrop);
		if (s.camPlacement) parts.push('I am ' + s.camPlacement);
		if (s.camNote.trim()) parts.push(s.camNote.trim());
		if (!parts.length) return '';
		return parts.map(function (p) { return cap(sentence(p)); }).join(' ');
	}


	/* Smiles. [phrase, label, showsTeeth] — the phrase is dropped straight into the prompt. */
	var SMILES = [
		{ group: 'Closed lips', items: [
			['a soft closed-lip smile, with the corners of my mouth lifted just a little', 'Soft closed-lip smile', false],
			['a small shy smile with my lips pressed together', 'Shy little smile', false],
			['a warm closed-mouth smile that reaches my eyes and crinkles them slightly', 'Warm smile, eyes crinkled', false],
			['a subtle smirk with one corner of my mouth raised', 'Subtle smirk', false],
			['a soft half-smile with my lips barely parted', 'Barely-there half-smile', false]
		] },
		{ group: 'Showing teeth', items: [
			['a gentle smile that shows just my top row of teeth', 'Slight teeth showing', true],
			['a bright open smile with my teeth showing naturally', 'Bright open smile', true],
			['a wide toothy grin with my mouth open', 'Wide toothy grin', true],
			['a big cheesy grin, so wide that my eyes squint from smiling', 'Cheesy grin, squinty eyes', true],
			['a happy smile with my teeth showing and my nose scrunched a little', 'Happy smile + nose scrunch', true],
			['a toothy smile with my head tilted to one side', 'Toothy smile, head tilt', true]
		] },
		{ group: 'Laughing', items: [
			['caught mid-laugh, my mouth open and my eyes crinkled', 'Caught mid-laugh', true],
			['laughing out loud with my head tilted back and my mouth wide open', 'Laughing out loud', true],
			['laughing so hard that my eyes are squeezed shut', 'Laughing, eyes squeezed shut', true],
			['giggling with my lips parted and my eyes smiling', 'Giggling', true],
			['an embarrassed laugh with my chin tucked down', 'Embarrassed laugh', true]
		] },
		{ group: 'Other', items: [
			['', 'No smile / let the pose decide', false],
			['a relaxed neutral mouth, not smiling', 'Neutral, not smiling', false],
			['custom', 'Custom… (type your own)', false]
		] }
	];

	var SMILE_TEETH_NOTE = 'My teeth look like real teeth — naturally shaped, very slightly uneven and softly off-white rather than perfectly straight and bright white.';

	/* Cute face expressions. [phrase, label] */
	var EXPRESSIONS = [
		{ group: 'Sweet & soft', items: [
			['big doe eyes and a sweet, innocent expression', 'Doe-eyed and innocent'],
			['a soft dreamy look, eyes relaxed and a little unfocused', 'Soft dreamy look'],
			['puppy eyes, looking up slightly with my eyebrows raised a touch', 'Puppy eyes'],
			['a shy look, chin tucked down and my eyes glancing up', 'Shy glance upward'],
			['a bashful look with my cheeks slightly flushed', 'Bashful, flushed cheeks'],
			['looking up through my lashes with a soft expression', 'Looking through my lashes'],
			['sleepy half-closed eyes and a soft just-woke-up look', 'Sleepy soft eyes'],
			['my head tilted to one side with a sweet, gentle expression', 'Sweet head tilt']
		] },
		{ group: 'Playful & silly', items: [
			['a small pout with my lower lip pushed out', 'Cute pout'],
			['my nose scrunched up playfully', 'Nose scrunch'],
			['my cheeks puffed out', 'Puffed cheeks'],
			['my tongue sticking out playfully to one side', 'Tongue out'],
			['a playful wink with one eye closed', 'Playful wink'],
			['my eyes squeezed shut in a happy expression', 'Eyes squeezed shut'],
			['wide surprised eyes with my eyebrows raised', 'Surprised wide eyes'],
			['an exaggerated shocked look with my mouth slightly open', 'Mock shock'],
			['a silly scrunched-up face', 'Silly scrunched face']
		] },
		{ group: 'Flirty & confident', items: [
			['a mischievous smirk with one eyebrow slightly raised', 'Mischievous smirk'],
			['gently biting my lower lip', 'Biting my lower lip'],
			['my lips pursed in a tiny kiss towards the camera', 'Kissy face'],
			['a confident, calm look straight down the lens', 'Calm and confident'],
			['a knowing side-eye glance', 'Side-eye glance']
		] },
		{ group: 'Other', items: [
			['', 'None / let the pose decide'],
			['a completely relaxed, neutral expression', 'Relaxed and neutral'],
			['custom', 'Custom… (type your own)']
		] }
	];

	/* Grouped [phrase, label, …] data -> the [value, label] pairs select() expects. */
	function selectGroups(groups) {
		return groups.map(function (g) {
			return { group: g.group, items: g.items.map(function (i) { return [i[0], i[1]]; }) };
		});
	}

	function groupItem(groups, value) {
		for (var i = 0; i < groups.length; i++) {
			for (var j = 0; j < groups[i].items.length; j++) {
				if (groups[i].items[j][0] === value) return groups[i].items[j];
			}
		}
		return null;
	}

	function smilePhrase(s) {
		return s.smile === 'custom' ? s.smileCustom.trim() : s.smile;
	}

	function smileShowsTeeth(s) {
		if (s.smile === 'custom') return /teeth|grin|laugh/i.test(s.smileCustom);
		var item = groupItem(SMILES, s.smile);
		return !!(item && item[2]);
	}

	function expressionPhrase(s) {
		return s.expression === 'custom' ? s.expressionCustom.trim() : s.expression;
	}

	/* "Slightly above, angled down, Hand fills the frame" for the setup list. */
	function camSummary(s, angles, crops, placements) {
		return [shortLabel(angles, s.camHeight, ''), shortLabel(crops, s.camCrop, ''),
			shortLabel(placements, s.camPlacement, '')].filter(Boolean).join(', ');
	}

	/* Short label for the saved-setup list, e.g. "Wide toothy grin". */
	function shortLabel(groups, value, custom) {
		if (value === 'custom') return custom.trim();
		var item = groupItem(groups, value);
		return item && item[0] ? item[1] : '';
	}

	var SELFIE = {
		id: 'selfie',
		title: 'Selfie',
		desc: 'Accidental or clear phone selfie',
		palette: PALETTE,
		palettes: { lip: LIP_PALETTE, blush: BLUSH_PALETTE, outfitColor: FASHION_PALETTE, color: PALETTE },
		icon: '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="currentColor">' +
			'<path d="M20 6h24a5 5 0 0 1 5 5v42a5 5 0 0 1-5 5H20a5 5 0 0 1-5-5V11a5 5 0 0 1 5-5zm0 6v40h24V12z"/>' +
			'<circle cx="32" cy="27" r="6"/><path d="M22 46c1-7 5-10 10-10s9 3 10 10z"/></g></svg>',
		defaults: {
			ratio: '9:16',
			address: 'chatgpt',
			shot: 'accidental',
			realism: true,
			eyeColor: EYE_COLORS[0].items[0][0],
			eyeCustom: '',
			eyeMakeup: true,
			skinTone: SKIN_TONES[0].items[0][0],
			skinCustom: '',
			hair: 'loose beach waves',
			hairCustom: '',
			lipOn: true,
			lip: '#E8829A',
			lipFinish: 'glossy',
			blushOn: true,
			blush: '#F4AFC0',
			earrings: true,
			earringPick: 'small huggie hoop earrings close to the lobe',
			earringsText: '',
			earringMaterial: 'yellow gold',
			earringColor: '#E91E63',
			earringsMatch: false,
			pendant: false,
			pendantText: '',
			pendantMatch: false,
			outfit: 'an oversized off-shoulder knit sweater',
			outfitCustom: '',
			outfitColorOn: true,
			outfitColor: '#F4C2C2',
			camHeight: '',
			camCrop: '',
			camPlacement: 'in the middle of the frame, with clear empty space above my head',
			camNote: '',
			gaze: 'camera',
			smile: '',
			smileCustom: '',
			expression: '',
			expressionCustom: '',
			pose: '',
			hands: false,
			handSpot: 'lips',
			mirror: false,
			nail: 'painted',
			color: '#BC9CB0',
			background: ''
		},
		libTargets: { selfie_pose: 'pose' },

		form: function (s) {
			return '' +
				card('Output ratio', seg('ratio', s.ratio, [['9:16', '9:16', 'default'], ['4:5', '4:5']])) +
				card('Start the prompt with',
					seg('address', s.address, [['chatgpt', 'ChatGPT,'], ['gemini', 'Gemini,'], ['none', 'No name']]) +
					'<p class="spg-hint">Your master starts with “ChatGPT,”. Switch it to match the app you paste into.</p>') +
				card('Shot type',
					choices('shot', s.shot, [
						['accidental', 'Accidental shot', 'Blurry, badly framed, snapped by mistake'],
						['clear', 'Clear shot', 'In focus and well lit, still a real phone photo']
					]) +
					'<p class="spg-hint">A clear shot swaps the master prompt and adds a camera-realism block so the photo never looks AI-generated.</p>') +
				card('Maximum photorealism',
					showIf('realism=true',
						'<p class="spg-hint">Adds the full realism stack: a wake-up line before the master prompt, an identity lock so you stay recognisable, soft feminine face shaping, real skin texture, eye/brow/lash detail, real fabric behaviour, and a final \u201clook again\u201d pass listing the things that give an AI photo away.</p>') +
					showIf('realism=false',
						'<p class="spg-hint">Only the basic camera block is added. Turn this on if the photo still looks AI-generated.</p>'),
					yesNo('realism', s.realism)) +
				card('Fixed by the master prompt',
					showIf('shot=accidental', '<p class="spg-hint spg-fixed">Accidental, haphazard iPhone snapshot · motion blur, uneven light, mild overexposure, weird angle, cluttered frame · Long hair</p>') +
					showIf('shot=clear', '<p class="spg-hint spg-fixed">Clear, well-exposed phone photo · sharp focus on my face · casual, not a photoshoot · real skin texture, no filter, no AI gloss · Long hair</p>')) +
				card('Hairstyle',
					select('hair', s.hair, HAIR_STYLES) +
					showIf('hair=custom', '<div class="spg-field">' + textInput('hairCustom', s.hairCustom, 'e.g. a low twisted bun with face-framing strands') + '</div>')) +
				card('Eyes',
					'<div class="spg-field">' + sub('Eye color') +
					select('eyeColor', s.eyeColor, selectGroups(EYE_COLORS)) + '</div>' +
					showIf('eyeColor=custom', '<div class="spg-field">' + textInput('eyeCustom', s.eyeCustom, 'e.g. a soft grey-blue with a dark outer ring') + '</div>') +
					check('eyeMakeup', s.eyeMakeup, 'Kajal, subtle mascara texture and softly enhanced brows') +
					'<p class="spg-hint">Thick natural brows, long separated lashes and real iris detail are described automatically when photorealism is on.</p>') +
				card('Skin tone',
					select('skinTone', s.skinTone, selectGroups(SKIN_TONES)) +
					showIf('skinTone=custom', '<div class="spg-field">' + textInput('skinCustom', s.skinCustom, 'e.g. my skin is light wheatish with warm peach undertones') + '</div>') +
					'<p class="spg-hint">The prompt always forbids bleached, porcelain or ultra-white skin and asks for pores, peach fuzz and a soft T-zone sheen.</p>') +
				card('Lipstick',
					showIf('lipOn=true',
						colorPicker(s, '#E8829A', LIP_PALETTE, 'lip', 'Lipstick shade') +
						'<div class="spg-field">' + sub('Finish') +
						seg('lipFinish', s.lipFinish, [['glossy', 'Glossy'], ['satin', 'Satin'], ['matte', 'Matte'], ['sheer tint', 'Sheer tint']]) + '</div>') +
					showIf('lipOn=false', '<p class="spg-hint">Natural lips, no lipstick.</p>'),
					yesNo('lipOn', s.lipOn)) +
				card('Blush',
					showIf('blushOn=true', colorPicker(s, '#F4AFC0', BLUSH_PALETTE, 'blush', 'Blush shade')) +
					showIf('blushOn=false', '<p class="spg-hint">No blush.</p>'),
					yesNo('blushOn', s.blushOn)) +
				card('Earrings',
					showIf('earrings=true',
						'<div class="spg-field">' + sub('Style') +
						select('earringPick', s.earringPick, selectGroups(EARRINGS)) + '</div>' +
						showIf('earringPick=custom',
							'<div class="spg-field">' + textInput('earringsText', s.earringsText, 'e.g. small gold hoops with a tiny charm') + '</div>') +
						showIf('earringPick!=match',
							'<div class="spg-field">' + sub('Material') +
							select('earringMaterial', s.earringMaterial, selectGroups(EARRING_MATERIALS)) + '</div>' +
							showIf(EARRING_COLORED, colorPicker(s, '#E91E63', JEWEL_PALETTE, 'earringColor', 'Earring color'))) +
						check('earringsMatch', s.earringsMatch, 'Match the earrings in my reference image')) +
					showIf('earrings=false', '<p class="spg-hint">No earrings.</p>'),
					yesNo('earrings', s.earrings)) +
				jewelryCard(s, 'pendant', 'Pendant', 'e.g. a thin gold chain with a tiny heart pendant', 'Match the pendant in my reference image') +
				card('Outfit',
					select('outfit', s.outfit, OUTFITS) +
					showIf('outfit=custom', '<div class="spg-field">' + textInput('outfitCustom', s.outfitCustom, 'e.g. a cropped white tank top with lace trim') + '</div>') +
					showIf('outfit!=match',
						check('outfitColorOn', s.outfitColorOn, 'Apply a color to the outfit') +
						showIf('outfitColorOn=true', colorPicker(s, '#F4C2C2', FASHION_PALETTE, 'outfitColor', 'Outfit color')))) +
				card('Camera',
					'<div class="spg-field">' + sub('Where the camera is held') +
					select('camHeight', s.camHeight, selectGroups(CAM_HEIGHTS)) + '</div>' +
					'<div class="spg-field">' + sub('How much of me is in the frame') +
					select('camCrop', s.camCrop, selectGroups(CAM_CROPS)) + '</div>' +
					'<div class="spg-field">' + sub('Where I sit in the frame') +
					select('camPlacement', s.camPlacement, selectGroups(CAM_PLACEMENTS)) + '</div>' +
					'<div class="spg-field">' + sub('Anything else about the camera (optional)') +
					textInput('camNote', s.camNote, 'e.g. tilted a little to the left, lens smudged') + '</div>' +
					camReset() +
					'<p class="spg-hint">Use “Where I sit in the frame” to stop the shot starting right at the top of the picture.</p>') +
				card('Where I\'m looking',
					choices('gaze', s.gaze, [
						['camera', 'At the camera', 'Eyes on the lens, like a normal selfie'],
						['away', 'Away from the camera', 'Eyes off to the side, unaware of the shot'],
						['auto', 'Let the pose decide', 'Nothing is added; your pose text decides']
					])) +
				card('Smile',
					select('smile', s.smile, selectGroups(SMILES)) +
					showIf('smile=custom', '<div class="spg-field">' + textInput('smileCustom', s.smileCustom, 'e.g. a lopsided grin with one dimple showing') + '</div>') +
					'<p class="spg-hint">Controls my mouth. The teeth options add a note so the teeth look real, not perfectly white.</p>') +
				card('Face expression',
					select('expression', s.expression, selectGroups(EXPRESSIONS)) +
					showIf('expression=custom', '<div class="spg-field">' + textInput('expressionCustom', s.expressionCustom, 'e.g. one eyebrow raised, lips pushed to one side') + '</div>') +
					'<p class="spg-hint">Controls the rest of my face — eyes, brows, cheeks. Works together with the smile above.</p>') +
				card('Pose (overall)',
					textArea('pose', s.pose, 3, 'e.g. caught mid-laugh, looking slightly past the phone, one shoulder raised') +
					aiInline('selfie_pose', 'pose', s.shot === 'clear' ? [
						'Sitting by the window, phone held at arm\u2019s length',
						'Standing against a plain wall, one hand in my hair',
						'Leaning back on the sofa, phone held slightly above me',
						'Outside on a walk, phone at eye level, hair moving a little'
					] : [
						'Caught mid-laugh, looking just past the camera',
						'Lying on the bed, phone held too close, half my face cut off',
						'Walking, glancing down at the screen, slightly blurry',
						'Sitting in a car, looking out the window, not ready'
					]) +
					libBlock('selfie_pose', 'Saved poses', 'Save this pose', 'Name this pose, e.g. Mid-laugh bed')) +
				card('Hands in the photo',
					showIf('hands=true',
						sub('Where is my hand?') + select('handSpot', s.handSpot, HAND_SPOTS.map(function (h) { return [h[0], h[1]]; }))) +
					showIf('hands=false', '<p class="spg-hint">Hands not visible (except the phone hand in a mirror selfie).</p>'),
					yesNo('hands', s.hands)) +
				card('Mirror selfie with a black iPhone 15 Pro',
					showIf('mirror=true', '<p class="spg-hint">Adds a mirror-selfie block: black iPhone 15 Pro (Black Titanium) held in one hand, visible in the reflection.</p>') +
					showIf('mirror=false', '<p class="spg-hint">Regular front-camera selfie.</p>'),
					yesNo('mirror', s.mirror)) +
				'<div data-show-if="hands=true|mirror=true">' +
				card('Fingernails',
					choices('nail', s.nail, [['painted', 'Painted', 'Pick a polish color'], ['bare', 'Bare', 'Natural keratin, no polish']]) +
					showIf('nail=painted', colorPicker(s, '#BC9CB0', PALETTE, 'color', 'Nail polish color')) +
					'<p class="spg-hint">A detailed hand &amp; long-nail realism block (matching your reference image) is added automatically whenever hands are visible.</p>') +
				'</div>' +
				card('Background',
					textArea('background', s.background, 3, 'e.g. messy bedroom, unmade bed, laundry on a chair, lamp light') +
					aiInline('selfie_background', 'background', [
						'Messy bedroom, unmade bed, evening lamp light',
						'Car front seat, daytime, parking lot outside',
						'Small bathroom mirror, cluttered counter',
						'Café table, half-finished coffee, busy background'
					]));
		},

		build: function (s) {
			var addr = { chatgpt: 'ChatGPT, ', gemini: 'Gemini, ', none: '' }[s.address] || '';
			var master = s.shot === 'clear' ? SELFIE_CLEAR_MASTER : SELFIE_MASTER;
			var head = s.realism ? SELFIE_REALISM_HEADER + ' ' : '';
			var intro = addr + head + master + ' I have long hair, ' + hairPhrase(s) + ', and my eyes are ' + eyePhrase(s) + '.';

			var lipHex = normalizeHex(s.lip) || '#E8829A';
			var lipName = colorName(lipHex, LIP_PALETTE);
			var lips = s.lipOn
				? (lipName === 'Custom color' ? 'Lipstick' : lipName + ' lipstick') + ' (Lipstick color: ' + lipHex + ') with a ' + s.lipFinish + ' finish, applied casually like real everyday makeup, slightly imperfect and not airbrushed.'
				: 'Natural lips, no lipstick.';

			var blushHex = normalizeHex(s.blush) || '#F4AFC0';
			var blushName = colorName(blushHex, BLUSH_PALETTE);
			var blush = s.blushOn
				? (blushName === 'Custom color' ? 'Blush' : blushName + ' blush') + ' (Blush color: ' + blushHex + '), softly applied on the apples of my cheeks, natural and a little uneven like real makeup.'
				: 'None.';

			function jewel(on, text, match, what, single) {
				if (!on) return 'None.';
				var t = cap(sentence(text)) || (match ? 'Exactly as shown in the reference image.' : 'Simple, delicate ' + what + '.');
				return t + (match ? ' ' + matchRef(what, single) : '');
			}

			var hex = normalizeHex(s.color) || '#BC9CB0';
			var nailName = colorName(hex, PALETTE);
			var nails = s.nail === 'bare'
				? 'Bare, unpainted natural keratin with translucent tips.'
				: 'Painted with ' + (nailName === 'Custom color' ? '' : nailName.toLowerCase() + ' ') + 'nail polish (Nail polish color: ' + hex + '), hand-applied all the way to the very tip of each long nail, slightly imperfect like real at-home polish.';

			var hands;
			if (s.mirror && s.hands) hands = 'One hand holds the phone; my other hand is visible with ' + spotText(s.handSpot) + '.';
			else if (s.mirror) hands = 'Only the hand holding the phone is visible.';
			else if (s.hands) hands = 'One hand is visible in the frame, with ' + spotText(s.handSpot) + '.';
			else hands = 'Not visible in the frame.';

			var lines = [
				intro,
				'',
				s.shot === 'clear' ? 'Details (keep it clear, natural and real):' : 'Details (keep the accidental, unposed feel):',
				'* Lips: ' + lips,
				'* Blush: ' + blush,
				'* Earrings: ' + jewel(s.earrings, earringPhrase(s), s.earringsMatch || s.earringPick === 'match', 'earrings'),
				'* Pendant: ' + jewel(s.pendant, s.pendantText, s.pendantMatch, 'pendant', true),
				'* Outfit: I\'m wearing ' + outfitPhrase(s) + '.'
			];
			if (s.realism) {
				lines.push('* ' + SELFIE_IDENTITY_LOCK);
				lines.push('* ' + SELFIE_FACE_LOCK);
				lines.push('* ' + skinText(s));
				lines.push('* ' + eyeDetailText(s, s.eyeMakeup));
				lines.push('* ' + SELFIE_FABRIC_LOCK);
			}
			var smile = smilePhrase(s);
			var expression = expressionPhrase(s);
			var camera = cameraLine(s);
			if (camera) lines.push('* Camera: ' + camera);
			if (SELFIE_GAZE[s.gaze]) {
				/* Only claim a "natural unposed expression" when nothing else describes my face. */
				lines.push('* Gaze: ' + SELFIE_GAZE[s.gaze] +
					(s.gaze === 'camera' && !smile && !expression ? ', with a natural unposed expression.' : '.'));
			}
			if (smile) lines.push('* Smile: ' + cap(sentence(smile)) + (smileShowsTeeth(s) ? ' ' + SMILE_TEETH_NOTE : ''));
			if (expression) lines.push('* Expression: ' + cap(sentence(expression)));
			if (s.pose.trim()) lines.push('* Pose: ' + cap(sentence(s.pose)));
			lines.push('* Hands: ' + hands);
			if (s.mirror) lines.push('* ' + mirrorText(s.shot));
			if (s.mirror || s.hands) {
				lines.push('* ' + SELFIE_HAND_LOCK);
				lines.push('* Fingernails: ' + nails);
			}
			lines.push('* Background: ' + (cap(sentence(s.background)) ||
				(s.shot === 'clear' ? 'An ordinary everyday place, nothing staged.' : 'An ordinary, cluttered everyday place.')));
			lines.push('* ' + iphoneLock(s.shot));
			if (s.realism) lines.push('* ' + SELFIE_ANTI_AI);
			lines.push('* Aspect ratio: ' + (s.ratio === '9:16' ? '9:16 (vertical phone photo).' : '4:5.'));
			return lines.join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (s.hair === 'custom' && !s.hairCustom.trim()) w.push('Custom hairstyle is empty.');
			if (s.eyeColor === 'custom' && !s.eyeCustom.trim()) w.push('Custom eye color is empty.');
			if (s.skinTone === 'custom' && !s.skinCustom.trim()) w.push('Custom skin tone is empty.');
			if (s.outfit === 'custom' && !s.outfitCustom.trim()) w.push('Custom outfit is empty.');
			if (s.smile === 'custom' && !s.smileCustom.trim()) w.push('Custom smile is empty.');
			if (s.expression === 'custom' && !s.expressionCustom.trim()) w.push('Custom face expression is empty.');
			if (s.earrings && s.earringPick === 'custom' && !s.earringsText.trim()) w.push('Custom earrings are empty.');
			if (s.pendant && !s.pendantText.trim() && !s.pendantMatch) w.push('Pendant is on but not described.');
			return w;
		},

		context: function (s, kind) {
			return {
				'Photo': s.shot === 'clear'
					? 'Clear, well-exposed everyday phone selfie: sharp focus, natural light, real skin texture, no filter and nothing that looks AI-generated'
					: 'Accidental, haphazard iPhone selfie: slight motion blur, uneven lighting, mild overexposure, weird angle, cluttered frame',
				'Subject': 'Young woman, long hair ' + hairPhrase(s) + ', eyes ' + eyePhrase(s),
				'Skin': skinTonePhrase(s),
				'Realism': s.realism ? 'Maximum: identity lock, real skin texture, eye/brow/lash detail, real fabric, anti-AI final pass' : 'Basic camera block only',
				'Outfit': outfitPhrase(s),
				'Mirror selfie': s.mirror ? 'Yes: holding a black iPhone 15 Pro, visible in the mirror' : 'No: front-camera selfie',
				'Hands': s.hands ? 'One hand with ' + spotText(s.handSpot) : 'Not visible',
				'Gaze': s.gaze === 'camera' ? 'Looking straight at the camera' : s.gaze === 'away' ? 'Looking away from the camera, unaware of the shot' : '',
				'Camera': cameraLine(s),
				'Smile': smilePhrase(s),
				'Face expression': expressionPhrase(s),
				'Pose': kind === 'selfie_pose' ? '' : s.pose,
				'Background': kind === 'selfie_background' ? '' : s.background,
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			var hair = s.hair === 'custom' ? s.hairCustom : s.hair === 'match' ? 'Ref hair' : s.hair;
			var outfit = s.outfit === 'custom' ? s.outfitCustom : s.outfit === 'match' ? 'Ref outfit' : s.outfit;
			var gaze = s.gaze === 'camera' ? 'At camera' : s.gaze === 'away' ? 'Looking away' : '';
			var camera = [shortLabel(CAM_HEIGHTS, s.camHeight, ''), shortLabel(CAM_CROPS, s.camCrop, ''),
				shortLabel(CAM_PLACEMENTS, s.camPlacement, '')].filter(Boolean).join(', ');
			var smile = shortLabel(SMILES, s.smile, s.smileCustom);
			var expression = shortLabel(EXPRESSIONS, s.expression, s.expressionCustom);
			var eyes = s.eyeColor === 'custom' ? s.eyeCustom : shortLabel(EYE_COLORS, s.eyeColor, '') || 'Ref eyes';
			return [s.shot === 'clear' ? 'Clear shot' : 'Accidental', s.realism ? 'Max realism' : '', hair, eyes, outfit,
				camera, gaze, smile, expression,
				s.mirror ? 'Mirror' : '', s.hands ? 'Hands' : '', s.ratio].filter(Boolean).join(' · ');
		}
	};


	/* ------------------------------------------------------------------ *
	 * Quote library — the emotional universe of the page
	 *
	 * "The things women feel but rarely say out loud." Eight pillars that
	 * run from wanting, through losing, to becoming. Every line here is
	 * written for this page: no borrowed lines, no interchangeable
	 * Pinterest wisdom. The heading doubles as the chapter it belongs to.
	 * ------------------------------------------------------------------ */

	var QUOTE_LIBRARY_GROUPS = [
		{ group: 'Things she wanted', items: [
			['w01', 'The first person he told'],
			['w02', 'Noticed on an ordinary Tuesday'],
			['w03', 'Chosen without auditioning'],
			['w04', 'Somewhere to put the armour down'],
			['w05', 'Remembered from one mention']
		] },
		{ group: 'Things she couldn’t say', items: [
			['s01', 'What she didn’t send'],
			['s02', 'She noticed everything'],
			['s03', 'Believed the first time'],
			['s04', 'All she meant was stay'],
			['s05', 'She pretended too well']
		] },
		{ group: 'Love & romance', items: [
			['l01', 'He waited for her sentences'],
			['l02', 'The hour before he arrived'],
			['l03', 'How she takes her tea'],
			['l04', 'Saving a thought to tell him'],
			['l05', 'Loved carefully']
		] },
		{ group: 'Things that hurt', items: [
			['h01', 'Nothing happened'],
			['h02', 'The same things, someone else'],
			['h03', 'Never allowed to call it hers'],
			['h04', 'Who she was when she believed him'],
			['h05', 'Answering like a stranger']
		] },
		{ group: 'Things she deserves', items: [
			['d01', 'Enough for whom'],
			['d02', 'She stopped making exceptions'],
			['d03', 'Done explaining herself'],
			['d04', 'Not a convenience'],
			['d05', 'Loved on a trial basis']
		] },
		{ group: 'Things she became', items: [
			['b01', 'No longer flinching'],
			['b02', 'Soft is not weak'],
			['b03', 'Carrying it alone'],
			['b04', 'Rooms she didn’t want'],
			['b05', 'Peace is not boring']
		] },
		{ group: 'Late-night thoughts', items: [
			['n01', 'Conversations at 1 a.m.'],
			['n02', 'Three minutes of someone'],
			['n03', 'Replaying to understand'],
			['n04', 'The drafts folder'],
			['n05', 'Lonely for being known']
		] },
		{ group: 'Healing & becoming', items: [
			['g01', 'The way seasons do'],
			['g02', 'A whole week'],
			['g03', 'Forgiven, not readmitted'],
			['g04', 'She let it ring'],
			['g05', 'Her own, entirely']
		] },
		{ group: 'Other', items: [
			['', 'No preset quote — I write my own']
		] }
	];

	var QUOTE_LIBRARY = {
		w01: { title: 'The first person', text: 'She didn’t want a grand gesture.\n\nShe wanted to be the first person\nhe thought of telling.\n\nShe was the fourth.' },
		w02: { title: 'An ordinary Tuesday', text: 'She never wanted to be spoiled.\n\nShe wanted to be noticed on a Tuesday,\nwhen there was no reason to.' },
		w03: { title: 'The audition', text: 'She wasn’t asking to be everything.\n\nShe was asking to stop auditioning\nfor a part she already had.' },
		w04: { title: 'Somewhere soft', text: 'She wanted one person\nshe could put the armour down for.\n\nShe kept meeting people\nwho needed her to keep it on.' },
		w05: { title: 'Mentioned once', text: 'She told him once, in passing.\n\nShe spent a year finding out\nwhether he had been listening.' },
		s01: { title: 'What she didn’t send', text: 'She typed it four times.\n\nShe sent: goodnight.\n\nHe said it back and went to sleep.' },
		s02: { title: 'She noticed', text: 'She noticed everything.\n\nThat was never the problem.\nThe problem was pretending she hadn’t.' },
		s03: { title: 'Ask again', text: 'She said she was fine\nand waited to be asked again.\n\nHe believed her.\n\nThat was the answer.' },
		s04: { title: 'What she meant', text: 'All she meant was stay.\n\nAll she said was:\nit’s fine, go.\n\nHe went.' },
		s05: { title: 'She taught him', text: 'She pretended not to care so well\nthat he stopped trying to prove she did.\n\nShe had taught him that.' },
		l01: { title: 'The beginning', text: 'It was never the compliments.\n\nIt was that he waited\nfor her to finish her sentences.\n\nNo one had before.' },
		l02: { title: 'The hour before', text: 'Half of loving him happened\nin the hour before he arrived.\n\nThe other half\nin the hour after he left.' },
		l03: { title: 'How she takes it', text: 'Romance was never the flowers.\n\nIt was him learning\nhow she takes her tea.\n\nNobody had bothered to learn anything.' },
		l04: { title: 'Saved to tell him', text: 'She knew the night she caught herself\nsaving a thought to tell him later.\n\nShe still saves them.\nThere is no later now.' },
		l05: { title: 'Carefully', text: 'Being loved loudly is lovely.\n\nBeing loved carefully\nis what ruins you for everyone after.' },
		h01: { title: 'Nothing happened', text: 'Nothing happened.\n\nThat was what happened.\n\nTry explaining that to someone\nwho asks why you’re sad.' },
		h02: { title: 'The same things', text: 'It wasn’t losing him.\n\nIt was watching him do\nthe same things with someone else —\n\nand do them better.' },
		h03: { title: 'Never hers', text: 'She grieved something\nshe was never allowed to call hers.\n\nThere is no word for that,\nso she said she was fine.' },
		h04: { title: 'Who she was', text: 'She didn’t miss him.\n\nShe missed who she was\nwhen she still believed him.' },
		h05: { title: 'Like a stranger', text: 'He didn’t leave.\n\nHe just started answering\nthe way strangers do.\n\nShe stayed months after that.' },
		d01: { title: 'Enough for whom', text: 'She stopped asking\nwhy she wasn’t enough,\n\nand started asking:\nenough for whom.\n\nThe answer embarrassed her.' },
		d02: { title: 'Exceptions', text: 'She didn’t get colder.\n\nShe stopped making exceptions\nfor people who never made one for her.' },
		d03: { title: 'Already decided', text: 'The bravest thing she did\nwas stop explaining herself\nto someone who had already decided.' },
		d04: { title: 'A convenience', text: 'She would rather be alone\nthan be a convenience\nwith someone’s name on it.' },
		d05: { title: 'Not a trial', text: 'She isn’t hard to love.\n\nShe is done being loved\non a trial basis.' },
		b01: { title: 'No longer flinching', text: 'She grew into a woman\nwho no longer flinches at her reflection.\n\nIt cost her everything she had\nbeen taught to be.' },
		b02: { title: 'Soft', text: 'She called her softness a weakness\nfor years,\n\nuntil she counted\nhow few people are brave enough\nto stay soft.' },
		b03: { title: 'Who called first', text: 'The friendships thinned.\n\nThen she counted how many of them\nhad ever called first.' },
		b04: { title: 'The rooms emptied', text: 'She stopped decorating herself\nfor rooms she didn’t want to be in.\n\nHalf the rooms emptied.' },
		b05: { title: 'The first quiet', text: 'Twenty-five taught her\nthat peace is not boring.\n\nIt is just the first quiet\nshe was ever given.' },
		n01: { title: 'One in the morning', text: 'At one in the morning\nshe has whole conversations\nwith people who are asleep.\n\nShe wins all of them.' },
		n02: { title: 'Three minutes', text: 'Some songs are just\nthree minutes of someone\nshe promised herself she would forget.' },
		n03: { title: 'Replaying it', text: 'She replays it\nnot to change it,\n\nbut because no one\never explained it to her.' },
		n04: { title: 'Drafts', text: 'Her drafts folder\nis the most honest thing she owns.\n\nNobody has read it.' },
		n05: { title: 'Lonely for being known', text: 'She isn’t lonely for people.\n\nShe is lonely for being known.\n\nThere is a difference,\nand it is the one keeping her awake.' },
		g01: { title: 'The way seasons do', text: 'She let go the way seasons do:\n\nnot all at once,\nand never where anyone could watch.' },
		g02: { title: 'A whole week', text: 'One day she noticed\nshe had gone a whole week\nwithout checking.\n\nShe almost told him.' },
		g03: { title: 'Forgiven', text: 'She forgave him.\n\nShe just never made room again.\n\nPeople keep confusing the two.' },
		g04: { title: 'She let it ring', text: 'She is not the girl\nwho waited by the phone.\n\nShe is the woman who heard it ring\nand finished her sentence first.' },
		g05: { title: 'Her own', text: 'She used to be someone’s almost.\n\nNow she is her own, entirely.\n\nIt is quieter than she expected.' }
	};

	/* The AI writer answers with "TITLE: …" and then the quote. Split them. */
	function splitWrittenQuote(text) {
		/* Some models bold the whole thing; the page is print, not markdown. */
		var lines = String(text || '').replace(/\r/g, '').replace(/\*\*/g, '').split('\n');
		var title = '';
		while (lines.length && !lines[0].trim()) lines.shift();
		if (lines.length && /^\s*title\s*[:\-–]/i.test(lines[0])) {
			title = lines.shift().replace(/^\s*title\s*[:\-–]\s*/i, '').trim();
		}
		while (lines.length && (!lines[0].trim() || /^\s*quote\s*[:\-–]\s*$/i.test(lines[0]))) lines.shift();
		var body = lines.join('\n').trim()
			.replace(/^\s*quote\s*[:\-–]\s*/i, '')
			.trim();
		/* Strip wrapping quote marks, but only when they are a matching pair:
		 * a quote that opens with someone speaking keeps its own marks. */
		if (/^[“"‘']/.test(body) && /[”"’']$/.test(body)) {
			body = body.replace(/^[“"‘']+/, '').replace(/[”"’']+$/, '').trim();
		}
		return { title: title, text: body };
	}

	/* The eight pillars, as a steer for the AI writer. */
	var QUOTE_THEMES = [
		{ group: 'The emotional universe', items: [
			['', 'Let me describe it myself'],
			['a secret thing she wants emotionally — to be understood, pursued, prioritised, noticed, or chosen consistently, written as desire rather than demand', 'Things she wanted'],
			['a thought she has but would never say out loud — what she almost texted, what she noticed, what she pretended not to care about', 'Things she couldn’t say'],
			['falling in love, a quiet romantic moment, anticipation, or the small specific thing that made her sure', 'Love & romance'],
			['heartbreak that is recognition rather than sadness — being left, being replaced, a situationship, someone slowly becoming a stranger', 'Things that hurt'],
			['self-worth: a boundary, walking away, refusing to beg for attention, realising what she will no longer accept', 'Things she deserves'],
			['growing into herself — becoming softer without becoming weaker, changing friendships, outgrowing rooms and people', 'Things she became'],
			['a late-night thought: overthinking at 1 a.m., a memory, an imagined conversation, something left unsaid', 'Late-night thoughts'],
			['healing: letting go slowly, noticing she has stopped checking, forgiving without going back, becoming her own', 'Healing & becoming']
		] }
	];

	/* ================================================================== *
	 * QUOTE generator
	 *
	 * A page of my book, held in my own hand. Only the thumb shows, with
	 * my long nail on it. The words are set in real book typography, not
	 * handwriting. Two reference images go with this prompt: my thumb and
	 * its long nail, and the book with a blank page.
	 * ================================================================== */

	var QUOTE_PART_1 = [
		'Technical Reasoning Instructions (The "Realism Engine")',
		'',
		'Reference Isolation Protocol: I am giving you two reference images. Image 1 is my thumb and my long natural thumbnail — use it only for the exact nail and skin anatomy. Image 2 is my book, open at a blank page — use it for the exact paper tone, page proportions, margins, binding and cover. Do not copy the pose, the camera angle, the lighting or the background of either reference image; build a completely fresh photograph from the instructions below.',
		'',
		'Anatomy & Tendon Physics: Calculate the skeletal structure of the hand (phalanges, metacarpals). Enforce a distinctly young, feminine, elegant bone structure with a slender thumb and minimal joint definition. Simulate the soft tendon tension and very delicate knuckle creasing of a thumb that is genuinely taking the weight of a book, with the pad of the thumb compressing slightly where it presses on the page or the edge of the cover.',
		'',
		'Light Physics (Advanced SSS): Simulate deep Subsurface Scattering within human tissue. Light must penetrate the skin of the thumb, revealing subtle, very faint youthful vascularity. Simulate the translucent nature of natural keratin so the free edge of the long nail glows faintly where light passes through it, showing its real thickness against the more opaque nail bed.',
		'',
		'Texture Mapping Physics: Disable all smoothing algorithms and AI beauty filters. Prioritise "High-Frequency Texture Rendering". Skin pores, the fingerprint ridges on the thumb pad, the organic cuticle, the natural keratin ridges of the nail and the fibre texture of the paper must all resolve with 100% clarity. No motion blur, no shaky edges. Keep the skin soft, taut and youthful, never coarse, aged or masculine, and never plastic, waxy or CGI.',
		'',
		'Print & Paper Physics: This is a printed page of a real book, not a screen and not handwriting. The paper is uncoated book stock: it shows its fibre grain, takes the light unevenly and bends on a real curve toward the spine. The ink is offset-printed — it sits crisply on the fibres with microscopically soft edges where it has sunk in, and every letter is perfectly consistent with every other instance of that letter. Type on a page that curves must curve and foreshorten with the paper, never sit on it flat like a sticker.'
	].join('\n');

	var QUOTE_CAMERA = [
		'1. Camera & Sensor Data (The Photorealistic Set)',
		'',
		'* Device: Simulated shot on an iPhone 15 Pro, handheld in the other hand.',
		'* Lens: 24mm (Wide) with slight, natural perspective distortion, so whatever is nearest the lens reads slightly larger.',
		'* Settings: ISO 400 to 800, natural indoor daylight.',
		'* Sensor Physics: Enforce an unfiltered, photorealistic protocol. Allow faint luminance noise in the shadows, subtle chromatic aberration on high-contrast edges, and a natural specular highlight on the nail. Absolutely zero airbrushed AI aesthetic.',
		'* Focus: Razor-sharp on the printed words and on the thumbnail. Every letter must be perfectly legible and perfectly crisp.'
	].join('\n');

	/* Always in the prompt: only the thumb, and it never covers a word. */
	var QUOTE_THUMB_LOCK = [
		'* [CRITICAL HAND LOCK]: Only my thumb is visible. All four other fingers are hidden behind the book, supporting it from underneath — no fingertip, no knuckle, no second nail and no sliver of another finger appears anywhere in the frame. Exactly one nail is visible in the whole image.',
		'* [CRITICAL NAIL LOCK]: The visible thumbnail is my own nail from the reference image: the same extreme length, the same thickness, the same C-curve, the same growth direction and the same free-edge shape. This morphology is non-negotiable. The nail is natural keratin, never an acrylic or a thick extension. It is fully visible, uncropped by the frame edge, and sharp from cuticle to tip.',
		'* [CRITICAL TEXT LOCK]: The thumb and its nail rest on the margin or the outer edge of the page and must never overlap, cover, shadow out or hide a single printed word. Every line of type stays complete and fully readable. Nothing is cropped away at the edge of the frame either.'
	].join('\n');

	/* Always in the prompt: this is typesetting, not lettering. */
	var QUOTE_PRINT_LOCK = [
		'* [TYPOGRAPHY LOCK]: The words are professionally typeset and offset-printed, exactly as they would appear in a published book. There is absolutely no handwriting, no calligraphy, no brush lettering, no marker, no pen and no hand-drawn character anywhere on the page.',
		'* Every letter is machine-perfect and identical to every other instance of itself. The baseline is dead straight, the line spacing is even, the word spacing is consistent, and the letterfit is professional — no crowding, no gaps, no wobble, no variation in weight from one letter to the next.',
		'* The type is the hero of the page: rendered at full resolution, with clean sharp edges and no blur, no artefacts, no doubled or malformed letters, and no invented characters. Spelling and punctuation exactly as given.',
		'* Ink density is even and solid, with the faint natural softness of ink absorbed into uncoated paper. No embossing, no drop shadows, no glow, no outline, no gradient, no texture overlay on the letters.'
	].join('\n');

	var QUOTE_BOOK_LOCK = '* [BOOK LOCK]: Use the book from the reference image as the exact book — the same paper tone, the same page proportions, the same margins, the same binding and the same cover. The type sits inside those margins with generous white space around it and is optically centred on the page. The page keeps its real curvature where it bends toward the spine, and the type curves and foreshortens with the paper.';

	/* Clean, real, and carrying no text of its own. */
	var QUOTE_BG_LOCK = 'The background must be clean, simple and genuinely real: one ordinary surface with at most one or two natural elements, never a crowd of props arranged around the book. There must be no text, no lettering, no logos, no book titles, no labels, no packaging and no writing of any kind anywhere in the background — the only readable words in the entire image are the ones printed on the page. Keep it fully detailed and textured with natural depth of field and no heavy artificial blurring, so it reads as a real photograph of a real place rather than a generated scene.';

	var QUOTE_OUTPUT = [
		'* Aesthetic: Candid, unfiltered reality. It must look exactly like a raw photo taken casually on a phone in real life — a real hand, holding a real printed book, in real light.',
		'* Absolutely no watermark, no caption, no border, no frame and no added graphics.'
	].join('\n');

	/* ---- Camera dropdowns for the book ---- */
	var QUOTE_CAM_ANGLES = [
		{ group: 'Looking down at the page', items: [
			['held above the book looking straight down at the open page, the page filling the frame square-on', 'Straight down at the page'],
			['looking down at the page at a slight angle, so the top of the page is a little further away', 'Slightly angled down'],
			['looking down over the top edge of the book, the way I see it myself while reading', 'My own view, over the top edge']
		] },
		{ group: 'From the side', items: [
			['off to the left at a three-quarter angle, so the page runs away slightly to the right', 'Three-quarter from the left'],
			['off to the right at a three-quarter angle, so the page runs away slightly to the left', 'Three-quarter from the right']
		] },
		{ group: 'Level & low', items: [
			['level with the book, straight on, so the page faces the lens flat', 'Level, straight on'],
			['slightly below the book, angled a little up at it', 'Slightly below, angled up']
		] },
		CAM_LET_MASTER
	];
	var QUOTE_CAM_CROPS = [
		{ group: 'Close', items: [
			['a close crop on the printed lines and the thumb, with the type filling most of the frame', 'Close on the type'],
			['the open page filling the frame from edge to edge, with the thumb at one side', 'Page fills the frame']
		] },
		{ group: 'Wider', items: [
			['the whole open book with both outer edges inside the frame', 'The whole book'],
			['the book together with my hand and a little of the surroundings behind it', 'Book, hand and surroundings']
		] },
		CAM_LET_MASTER
	];
	var QUOTE_CAM_PLACEMENTS = [
		{ group: 'Where the book sits', items: [
			['centred in the frame with even space around it', 'Centred'],
			['slightly off to the left, with more space on the right', 'Off to the left'],
			['slightly off to the right, with more space on the left', 'Off to the right'],
			['tilted a few degrees so the page is not perfectly square to the frame', 'Tilted a few degrees'],
			['entering the frame from the bottom edge, as if I am holding it up in front of me', 'Entering from the bottom']
		] },
		CAM_LET_MASTER
	];

	/* ---- How the book is held: the thumb is always the only finger seen ---- */
	var QUOTE_HOLDS = [
		{ group: 'One hand, from the side', items: [
			['I hold the book from its right-hand outer edge, my thumb lying flat along the outer margin of the right page with its long nail pointing up the margin, and all four other fingers behind the book taking its weight', 'Right edge, thumb up the margin'],
			['I hold the book from its left-hand outer edge, my thumb lying flat along the outer margin of the left page with its long nail pointing up the margin, and all four other fingers hidden behind the page', 'Left edge, thumb up the margin'],
			['I hold the book from the side with my thumb angled diagonally across the outer margin, the long nail pointing in toward the corner of the page but stopping well clear of the type', 'Side, thumb angled in'],
			['I hold the book from the side with only the tip of my thumb and its long nail on the page, the rest of the thumb below the edge of the cover and the other fingers behind it', 'Side, only the thumb tip on the page']
		] },
		{ group: 'From below', items: [
			['I support the book from underneath, my thumb reaching up onto the bottom margin of the page with the long nail pointing up toward the type but well below the last line, the other fingers hidden under the cover', 'Bottom margin, nail pointing up'],
			['I balance the book on my hidden fingers and rest my thumb on the bottom corner of the page, the nail lying diagonally across the corner', 'Bottom corner'],
			['I hold the book low so it leans back toward the camera, my thumb hooked over the bottom edge with the nail resting on the very edge of the paper', 'Hooked over the bottom edge']
		] },
		{ group: 'From the top', items: [
			['I hold the book from the top, my thumb pressing lightly on the top margin above the first line, the long nail pointing down the page but stopping short of the type', 'Top margin, nail pointing down'],
			['I pin the top corner of the page with my thumb, the long nail lying along the corner, the other fingers behind the cover', 'Top corner pinned']
		] },
		{ group: 'Holding it open', items: [
			['I hold the page open against the spring of the spine, my thumb flat on the outer margin and the long nail curving slightly away from the paper where it lifts off the page', 'Holding the page open'],
			['I hold the book at the spine side, my thumb resting in the gutter between the two pages with the long nail following the fold, the other fingers behind the book', 'At the spine, nail along the fold'],
			['I press the page flat with my thumb near the spine so the paper curves gently under it, the long nail clear of every line of type', 'Pressing the page flat']
		] },
		{ group: 'Lifted toward the camera', items: [
			['I hold the book up toward the camera with one hand, my thumb on the near outer margin and its long nail the closest part of the hand to the lens', 'Held up, nail nearest the lens'],
			['I tilt the book slightly toward the camera and grip the outer edge, my thumb lying along it with the nail reading at a three-quarter angle', 'Tilted toward the camera'],
			['I hold the book out at arm\'s length, my thumb small in the frame at the page edge but its long nail still sharp and fully visible', 'At arm\'s length']
		] },
		{ group: 'Resting', items: [
			['the book rests on my lap and I steady it with my thumb on the outer margin, the other fingers underneath and out of sight', 'Resting on my lap'],
			['the book rests against my knee, tilted up, my thumb holding the outer edge with the long nail on the margin', 'Leaning on my knee'],
			['the book lies on a surface and I hold the page down with my thumb alone, the other fingers curled away behind my hand and out of frame', 'Flat, page held down']
		] },
		{ group: 'Other', items: [
			['custom', 'Custom… (describe the hold)']
		] }
	];

	/* ---- Backgrounds: clean, real, and never carrying text ---- */
	var QUOTE_BACKGROUNDS = [
		{ group: 'Dark silk & velvet', items: [
			['crumpled black silk, catching a single soft highlight along one fold', 'Black silk'],
			['deep red velvet, its pile catching the light in two directions', 'Deep red velvet'],
			['a black satin sheet with long soft folds running out of frame', 'Black satin'],
			['a wine-dark throw of heavy fabric, the weave just visible in the low light', 'Wine-dark throw'],
			['dark grey linen, matte and softly creased', 'Dark grey linen']
		] },
		{ group: 'Cream & skin tones', items: [
			['a cream silk sheet with a warm, low sheen and soft shadows in the folds', 'Cream silk'],
			['an ivory linen bedsheet in warm, directional light', 'Ivory linen'],
			['a warm sand-coloured throw, soft and unpatterned', 'Warm sand throw'],
			['a plain cream wall with warm light falling across it at a steep angle', 'Cream wall, raking light']
		] },
		{ group: 'Dark surfaces', items: [
			['a dark walnut table top, polished, with the grain catching a low light', 'Dark walnut'],
			['black marble with faint pale veining running through it', 'Black marble'],
			['a matte black surface with almost nothing to read but the shadow', 'Matte black'],
			['a dark leather surface, softly grained, with one warm highlight', 'Dark leather'],
			['a deep charcoal plaster wall with subtle uneven texture', 'Charcoal wall']
		] },
		{ group: 'Low light & shadow', items: [
			['warm low light from one side, most of the frame falling away into deep shadow', 'One side lit, rest in shadow'],
			['candlelight just out of frame, throwing a warm unsteady glow across the surface', 'Candlelight from off frame'],
			['late evening light through a window, long shadows and a dark room behind', 'Late evening window light'],
			['a single warm lamp off to one side, the background going almost black', 'One warm lamp']
		] },
		{ group: 'One quiet element', items: [
			['a dark surface with a single lit candle softly out of focus behind, its flame small and steady', 'One candle behind'],
			['a dark surface with a single glass of red wine well out of focus at the edge of the frame', 'One glass of wine'],
			['a dark surface with one folded silk scarf at the very edge of the frame', 'One folded silk scarf'],
			['dark green leaves of a houseplant filling the background softly, gently out of focus', 'Dark soft leaves']
		] },
		{ group: 'Pale & minimal', items: [
			['a plain white bedsheet in soft daylight, with only the natural creases of the fabric', 'White bedsheet'],
			['a white marble surface with faint natural grey veining', 'White marble'],
			['a plain matte beige wall with nothing on it at all', 'Plain beige wall']
		] },
		{ group: 'Other', items: [
			['custom', 'Custom… (describe the background)']
		] }
	];

	/* ------------------------------------------------------------------ *
	 * Typography
	 *
	 * Image models know some typefaces by name and guess at the rest, so
	 * every option describes the letterforms as well as naming the font.
	 * That way the page still reads as the right kind of book even when
	 * the model has never heard of the family.
	 * ------------------------------------------------------------------ */

	var QUOTE_FONTS = [
		{ group: 'Classic book serifs — recommended', items: [
			['EB Garamond, a classic old-style book serif: moderate stroke contrast, gently bracketed serifs, a small x-height, an angled stress and a warm, literary colour on the page', 'EB Garamond'],
			['Sabon, the quiet old-style serif used to set printed poetry collections: even colour, calm balanced letterforms and generous inner white space', 'Sabon'],
			['Adobe Caslon, a warm old-style serif with slightly irregular, human letterforms and sturdy bracketed serifs', 'Caslon'],
			['Crimson Pro, a contemporary old-style book serif with open counters, restrained contrast and a soft, readable texture', 'Crimson Pro']
		] },
		{ group: 'Elegant, high-contrast serifs', items: [
			['Cormorant Garamond, a delicate high-contrast display serif: very fine hairlines, sharp tapering serifs and long elegant extenders', 'Cormorant Garamond'],
			['Didot, a modern serif with extreme contrast, flat unbracketed hairline serifs and a crisp fashion-magazine elegance', 'Didot'],
			['Playfair Display, a high-contrast transitional serif with flat hairline serifs and a strong editorial presence', 'Playfair Display'],
			['Baskerville, a transitional serif with vertical stress, crisp sharp serifs and generous round counters', 'Baskerville']
		] },
		{ group: 'Warm modern serifs', items: [
			['Lora, a contemporary serif with brushed curves, moderate contrast and a warm, faintly calligraphic feel', 'Lora'],
			['Libre Baskerville, a sturdy book serif with a large x-height and excellent legibility at small sizes', 'Libre Baskerville'],
			['Georgia, a robust serif with a large x-height, thick unfussy strokes and a friendly, solid texture', 'Georgia']
		] },
		{ group: 'Clean sans-serifs', items: [
			['a light geometric sans-serif in the manner of Futura: perfect circular bowls, uniform stroke weight and wide letter spacing', 'Geometric sans (Futura-like)'],
			['a neutral grotesque sans-serif in the manner of Helvetica Neue Light: even strokes, tight apertures and a calm modern surface', 'Neutral sans (Helvetica-like)'],
			['a humanist sans-serif in the manner of Jost or Inter: open shapes, slightly warm proportions and a clean contemporary look', 'Humanist sans']
		] },
		{ group: 'Other', items: [
			['custom', 'Custom… (name the typeface)']
		] }
	];

	var QUOTE_TYPE_SIZES = [
		{ group: 'Size on the page', items: [
			['set large and airy, only a few words to a line, with a great deal of white space around the text block', 'Large and airy'],
			['set at a comfortable book size, roughly the size of the body text in a printed novel', 'Normal book size'],
			['set small and quiet in the middle of a large empty page, so the white space dominates', 'Small, lots of white space']
		] }
	];

	var QUOTE_ALIGNMENTS = [
		{ group: 'Alignment', items: [
			['centred on the page, each line optically balanced against the others, with ragged line lengths', 'Centred'],
			['set flush left with a ragged right edge, the text block sitting in the upper half of the page', 'Flush left'],
			['centred in the exact optical middle of the page, with equal space above and below the text block', 'Centred, middle of the page']
		] }
	];

	var QUOTE_EMPHASIS = [
		{ group: 'Emphasis', items: [
			['', 'None — plain roman throughout'],
			['the whole quote set in italic', 'The whole quote in italic'],
			['the key words of the quote set in italic, the rest in roman', 'Key words in italic'],
			['the first line set in small capitals with letter spacing, the rest in roman', 'First line in small caps'],
			['the last line set in italic, the rest in roman', 'Last line in italic']
		] }
	];

	/* Where the Instagram handle sits. Whatever is chosen here is described
	 * as a fixed position, so every image in the feed matches the last. */
	var QUOTE_LIGHTING = [
		{ group: 'Low and luxurious', items: [
			['warm, low, directional light from one side, so the page and the nail are clearly lit while the rest of the frame falls away into soft shadow', 'Warm low light from one side'],
			['candlelight quality: a warm, slightly uneven glow with deep shadows, still bright enough to read every word', 'Candlelit'],
			['a single soft lamp above and behind the camera, giving one clean highlight down the length of the nail', 'One soft lamp, highlight on the nail'],
			['moody editorial lighting: one soft key light, deep falloff, and a faint rim of light along the edge of the thumb', 'Moody editorial']
		] },
		{ group: 'Daylight', items: [
			['soft, diffused natural daylight falling across the page, even and bright, with a gentle real shadow from the thumb and the long nail', 'Soft daylight'],
			['late afternoon light coming in low from one side, warm and directional, with long soft shadows', 'Late afternoon, low and warm']
		] }
	];

	var QUOTE_HANDLE_PLACES = [
		{ group: 'Bottom of the page', items: [
			['centred in the bottom margin of the page, below everything else, with clear space above it', 'Bottom centre'],
			['in the bottom right corner of the page, inside the margin', 'Bottom right'],
			['in the bottom left corner of the page, inside the margin', 'Bottom left']
		] },
		{ group: 'Top of the page', items: [
			['centred in the top margin of the page, above everything else, with clear space below it', 'Top centre'],
			['in the top right corner of the page, inside the margin', 'Top right']
		] }
	];

	var QUOTE_HANDLE_STYLES = [
		{ group: 'How it is set', items: [
			['set very small in spaced capitals, lighter than the quote so it never competes with it', 'Small spaced capitals'],
			['set very small in italic, in the same typeface as the quote', 'Small italic'],
			['set small and plain in the same typeface as the quote', 'Small and plain'],
			['set very small in a light sans-serif, quietly modern against the serif of the quote', 'Small sans-serif']
		] }
	];

	var QUOTE_HEADING_STYLES = [
		{ group: 'How the heading is set', items: [
			['set in small capitals with wide letter spacing, noticeably smaller than the quote, with a generous gap beneath it', 'Small caps, spaced'],
			['set in italic at the same size as the quote, with a generous gap beneath it', 'Italic'],
			['set in roman capitals, small and widely letter-spaced', 'Spaced capitals'],
			['set in the same face as the quote but smaller and lighter in colour', 'Smaller and lighter']
		] }
	];

	/* ---- Ink and accent colors ---- */
	var INK_PALETTE = [
		{ group: 'Print ink', colors: [
			['Black', '#000000'], ['Book Black', '#1A1A1A'], ['Soft Black', '#2B2B2B'],
			['Warm Charcoal', '#3A332E'], ['Deep Brown', '#4A382C']
		] },
		{ group: 'Deep reds', colors: [
			['Deep Red', '#7A0C14'], ['Oxblood', '#5A0E17'], ['Wine', '#6B1028'],
			['Crimson', '#9B111E'], ['Lacquer Red', '#B8151F']
		] },
		{ group: 'Cream & skin', colors: [
			['Cream', '#F3EBDD'], ['Ivory', '#FAF3E6'], ['Warm Sand', '#E4D2BC'],
			['Soft Skin', '#E8C4AE'], ['Muted Gold', '#B49460'], ['Antique Brass', '#8C6B3F']
		] }
	];

	/* ---- Little imperfections that kill the "AI" feel ---- */
	var QUOTE_FLAWS = [
		['flawDirt', 'a very faint line of everyday dirt tucked under the free edge of the nail'],
		['flawSmudge', 'a tiny smudge of polish on the skin of the cuticle where the brush slipped'],
		['flawUneven', 'the polish slightly uneven along the cuticle line, not a perfect crescent'],
		['flawChip', 'a small chip in the polish near the tip of the nail'],
		['flawScratch', 'a hairline scratch across the gloss, catching the light differently'],
		['flawDust', 'a single speck of dust sitting in the polish'],
		['flawRidge', 'the natural keratin ridges of the nail plate showing faintly through the finish'],
		['flawCuticle', 'one side of the cuticle very slightly overgrown, the way a real hand looks mid-week'],
		['flawEdge', 'the free edge not perfectly even, with one tiny natural nick'],
		['flawPaper', 'one corner of the page very slightly soft from being turned, and a faint natural bow in the paper'],
		['flawPrint', 'the ink on one or two letters a hair lighter where the press ran dry, visible only up close']
	];

	function quoteFlaws(s) {
		return QUOTE_FLAWS.filter(function (f) { return s[f[0]]; }).map(function (f) { return f[1]; });
	}

	function quoteFont(s) {
		return s.font === 'custom'
			? (s.fontCustom.trim() || 'a classic old-style book serif')
			: s.font;
	}

	var QUOTE_DEFAULT_QUOTE = QUOTE_LIBRARY.s03.text;

	var QUOTE = {
		id: 'quote',
		title: 'Quote',
		desc: 'A page of my book, thumb nail showing',
		palettes: {
			color: PALETTE,
			inkColor: INK_PALETTE,
			accentColor: INK_PALETTE,
			followColor: INK_PALETTE,
			handleColor: INK_PALETTE,
			acc1Color: JEWEL_PALETTE,
			acc2Color: JEWEL_PALETTE
		},
		icon: '<svg viewBox="0 0 64 64" aria-hidden="true"><g fill="currentColor">' +
			'<path d="M14 10h28a6 6 0 0 1 6 6v36a6 6 0 0 1-6 6H14a4 4 0 0 1-4-4V14a4 4 0 0 1 4-4zm4 6v32h24V16z"/>' +
			'<rect x="22" y="22" width="16" height="3" rx="1.5"/><rect x="22" y="30" width="16" height="3" rx="1.5"/>' +
			'<rect x="22" y="38" width="10" height="3" rx="1.5"/>' +
			'<path d="M50 40c3 0 5 2 5 6s-2 8-5 8-4-3-4-7 1-7 4-7z"/></g></svg>',
		defaults: {
			ratio: '9:16',
			hand: 'right',
			hold: 'I hold the book from its right-hand outer edge, my thumb lying flat along the outer margin of the right page with its long nail pointing up the margin, and all four other fingers behind the book taking its weight',
			holdCustom: '',
			camHeight: '',
			camCrop: '',
			camPlacement: '',
			camNote: '',
			quotePick: 's03',
			theme: '',
			aiIdea: '',
			heading: 'Ask again',
			quote: QUOTE_DEFAULT_QUOTE,
			closing: '',
			quoteMarks: false,
			font: 'EB Garamond, a classic old-style book serif: moderate stroke contrast, gently bracketed serifs, a small x-height, an angled stress and a warm, literary colour on the page',
			fontCustom: '',
			typeSize: 'set large and airy, only a few words to a line, with a great deal of white space around the text block',
			alignment: 'centred on the page, each line optically balanced against the others, with ragged line lengths',
			emphasis: '',
			headingOn: true,
			headingStyle: 'set in small capitals with wide letter spacing, noticeably smaller than the quote, with a generous gap beneath it',
			inkColor: '#000000',
			accentOn: false,
			accentWhat: 'the heading',
			accentColor: '#C98B94',
			chapterOn: false,
			chapterText: 'Chapter Two — Things She Couldn’t Say',
			pageNumOn: false,
			pageNum: '37',
			followOn: false,
			followText: 'Follow',
			followColor: '#000000',
			handleOn: true,
			handleText: '@shatania.veyra',
			handlePlace: 'centred in the bottom margin of the page, below everything else, with clear space above it',
			handleStyle: 'set very small in spaced capitals, lighter than the quote so it never competes with it',
			handleColor: '#000000',
			lighting: 'warm, low, directional light from one side, so the page and the nail are clearly lit while the rest of the frame falls away into soft shadow',
			nail: 'B',
			color: '#7A0C14',
			nailNote: '',
			flawDirt: false,
			flawSmudge: true,
			flawUneven: true,
			flawChip: false,
			flawScratch: false,
			flawDust: true,
			flawRidge: true,
			flawCuticle: false,
			flawEdge: false,
			flawPaper: true,
			flawPrint: false,
			acc1Pick: '',
			acc1Custom: '',
			acc1ColorOn: true,
			acc1Color: '#D4AF37',
			acc2Pick: '',
			acc2Custom: '',
			acc2ColorOn: true,
			acc2Color: '#C0C4CA',
			background: 'a plain white bedsheet in soft daylight, with only the natural creases of the fabric',
			backgroundCustom: ''
		},
		libTargets: { quote_text: 'quote' },
		libNouns: { quote_text: 'quote' },

		/* Picking one of the library quotes fills the heading and the body. */
		onSelect: {
			quotePick: function (s, form) {
				var hit = QUOTE_LIBRARY[s.quotePick];
				if (!hit) return;
				s.heading = hit.title;
				s.quote = hit.text;
				if (form.elements.heading) form.elements.heading.value = hit.title;
				if (form.elements.quote) form.elements.quote.value = hit.text;
			}
		},

		/* The AI writer answers with a title and a quote, so it fills both. */
		aiFields: {
			quote_write: {
				before: function (s) { return { heading: s.heading, quotePick: s.quotePick }; },
				preview: function (text) {
					var split = splitWrittenQuote(text);
					return (split.title ? split.title + '\n' : '') + split.text;
				},
				apply: function (s, text, form) {
					var split = splitWrittenQuote(text);
					if (split.title) {
						s.heading = split.title;
						if (form.elements.heading) form.elements.heading.value = split.title;
					}
					s.quotePick = '';
					if (form.elements.quotePick) form.elements.quotePick.value = '';
					return split.text || text;
				},
				restore: function (s, snap, form) {
					s.heading = snap.heading;
					s.quotePick = snap.quotePick;
					if (form.elements.heading) form.elements.heading.value = snap.heading;
					if (form.elements.quotePick) form.elements.quotePick.value = snap.quotePick;
				}
			}
		},

		form: function (s) {
			return '' +
				'<p class="spg-mode-lead">Give the image model two reference pictures with this prompt: <strong>1.</strong> my thumb and its long nail, <strong>2.</strong> my book, open at a blank page.</p>' +
				card('Aspect ratio', seg('ratio', s.ratio, [['9:16', '9:16', 'default'], ['4:5', '4:5']])) +
				card('The words on the page',
					'<div class="spg-field">' + sub('Pick one from the collection (40)') +
					select('quotePick', s.quotePick, selectGroups(QUOTE_LIBRARY_GROUPS)) +
					'<p class="spg-hint">Fills the heading and the body below. Edit them freely afterwards.</p></div>' +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Heading') + yesNo('headingOn', s.headingOn) + '</div>' +
					showIf('headingOn=true',
						textInput('heading', s.heading, 'e.g. What she meant') +
						'<div class="spg-field">' + sub('How it is set') +
						select('headingStyle', s.headingStyle, selectGroups(QUOTE_HEADING_STYLES)) + '</div>') + '</div>' +
					'<div class="spg-field">' + sub('The quote — one line per line of the page') +
					textArea('quote', s.quote, 6, 'Type the quote exactly as it should appear, with your own line breaks') +
					textTools('quote') + '</div>' +
					check('quoteMarks', s.quoteMarks, 'Wrap it in printed quotation marks') +
					'<div class="spg-field">' + sub('Closing line (optional)') +
					textInput('closing', s.closing, 'e.g. — from the book') + '</div>' +
					libBlock('quote_text', 'Saved quotes', 'Save this quote', 'Name this quote, e.g. Drafts folder') +
					'<div class="spg-field">' + sub('Theme for the AI writer') +
					select('theme', s.theme, selectGroups(QUOTE_THEMES)) + '</div>' +
					aiPanel('quote_write', 'quote', 'aiIdea', s.aiIdea, [
						'She almost texted him and didn’t',
						'Being the one who always understands',
						'The moment she realised she had stopped checking',
						'Wanting to be chosen without having to ask',
						'A love that let her stop performing'
					], 'Pick a theme above, or say what it should be about, and the AI writes a new quote in the voice of the page — a heading and the lines. Leave it empty to rewrite what is in the box.',
					'e.g. the quiet jealousy of seeing him be gentle with someone else',
					'Quote writer', '✦ Write a quote')) +
				card('Typography',
					'<div class="spg-field">' + sub('Typeface') +
					select('font', s.font, selectGroups(QUOTE_FONTS)) +
					showIf('font=custom',
						'<div class="spg-field">' + textInput('fontCustom', s.fontCustom, 'e.g. Freight Text Pro, a warm old-style serif') + '</div>') +
					'</div>' +
					'<div class="spg-field">' + sub('Size on the page') +
					select('typeSize', s.typeSize, selectGroups(QUOTE_TYPE_SIZES)) + '</div>' +
					'<div class="spg-field">' + sub('Alignment') +
					select('alignment', s.alignment, selectGroups(QUOTE_ALIGNMENTS)) + '</div>' +
					'<div class="spg-field">' + sub('Emphasis') +
					select('emphasis', s.emphasis, selectGroups(QUOTE_EMPHASIS)) + '</div>' +
					colorPicker(s, '#000000', INK_PALETTE, 'inkColor', 'Ink color') +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('A second colour') + yesNo('accentOn', s.accentOn) + '</div>' +
					showIf('accentOn=true',
						'<div class="spg-field">' + sub('What is printed in it') +
						textInput('accentWhat', s.accentWhat, 'e.g. the heading, or the last line') + '</div>' +
						colorPicker(s, '#C98B94', INK_PALETTE, 'accentColor', 'Accent color')) + '</div>' +
					locked('Always added', 'Real book typesetting: machine-perfect letterforms, a dead-straight baseline, even spacing, and absolutely no handwriting anywhere on the page.')) +
				card('Instagram handle',
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Print my handle on the page') + yesNo('handleOn', s.handleOn) + '</div>' +
					showIf('handleOn=true',
						textInput('handleText', s.handleText, '@shatania.veyra') +
						'<div class="spg-field">' + sub('Where it sits — the same spot on every image') +
						select('handlePlace', s.handlePlace, selectGroups(QUOTE_HANDLE_PLACES)) + '</div>' +
						'<div class="spg-field">' + sub('How it is set') +
						select('handleStyle', s.handleStyle, selectGroups(QUOTE_HANDLE_STYLES)) + '</div>' +
						colorPicker(s, '#000000', INK_PALETTE, 'handleColor', 'Its color') +
						'<p class="spg-hint">Leave this alone once you like it. The prompt tells the model the handle is in exactly this place, at exactly this size, in every image — that is what makes a feed look like one feed.</p>') +
					showIf('handleOn=false', '<p class="spg-hint">No handle on the page.</p>')) +
				card('Page furniture',
					'<p class="spg-hint">The small printed details that make it read as a page out of a real book.</p>' +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Chapter line') + yesNo('chapterOn', s.chapterOn) + '</div>' +
					showIf('chapterOn=true', textInput('chapterText', s.chapterText, 'e.g. Chapter Two — Things She Couldn’t Say')) + '</div>' +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('Page number') + yesNo('pageNumOn', s.pageNumOn) + '</div>' +
					showIf('pageNumOn=true', textInput('pageNum', s.pageNum, 'e.g. 37')) + '</div>' +
					'<div class="spg-acc"><div class="spg-acc-head">' + sub('“Follow” word') + yesNo('followOn', s.followOn) + '</div>' +
					showIf('followOn=true',
						textInput('followText', s.followText, 'e.g. Follow') +
						colorPicker(s, '#000000', INK_PALETTE, 'followColor', 'Its color')) + '</div>') +
				card('Which hand',
					seg('hand', s.hand, [['right', 'Right hand'], ['left', 'Left hand']]) +
					'<p class="spg-hint">Sets the correct thumb side and the direction the nail points.</p>') +
				card('How I hold the book',
					sub('Hold') +
					select('hold', s.hold, selectGroups(QUOTE_HOLDS)) +
					showIf('hold=custom',
						'<div class="spg-field">' + textArea('holdCustom', s.holdCustom, 3, 'Describe the hold — remember only the thumb may be visible') + '</div>') +
					'<p class="spg-hint">Every hold keeps the four other fingers behind the book and the nail clear of the type.</p>') +
				card('Camera & framing',
					cameraFields(s, [QUOTE_CAM_ANGLES, QUOTE_CAM_CROPS, QUOTE_CAM_PLACEMENTS], [
						'Where the camera is', 'How close the shot is', 'Where the book sits in the frame',
						'e.g. a slight handheld tilt to the left'
					])) +
				card('Nails',
					choices('nail', s.nail, [['A', 'Unpainted', 'Bare natural keratin'], ['B', 'Polished', 'Pick a polish color']]) +
					showIf('nail=B', colorPicker(s, '#7A0C14', PALETTE, 'color', 'Nail polish color')) +
					'<div class="spg-field">' + sub('Anything else about the nail (optional)') +
					textInput('nailNote', s.nailNote, 'e.g. a thin glossy top coat, almond shape') + '</div>') +
				card('Real-world imperfections',
					'<p class="spg-hint">A few of these stop the shot reading as a render. Two or three is usually enough.</p>' +
					QUOTE_FLAWS.map(function (f) {
						return check(f[0], s[f[0]], esc(cap(f[1])));
					}).join('')) +
				card('Accessories',
					'<p class="spg-hint">Anything on the hand that holds the book. Leave both empty for a bare hand.</p>' +
					accessorySlot(s, 1, 'Piece 1', HAND_ACCESSORIES, '#D4AF37') +
					accessorySlot(s, 2, 'Piece 2', HAND_ACCESSORIES, '#C0C4CA')) +
				card('Background',
					sub('Pick a clean, simple background') +
					select('background', s.background, selectGroups(QUOTE_BACKGROUNDS)) +
					showIf('background=custom',
						'<div class="spg-field">' +
						textArea('backgroundCustom', s.backgroundCustom, 3, 'Describe it — keep it clean, real and free of any text') +
						aiInline('quote_background', 'backgroundCustom', [
							'A plain white bedsheet in morning light',
							'An empty wooden desk by a window',
							'A plain beige wall, nothing on it'
						]) + '</div>') +
					locked('Always added after', QUOTE_BG_LOCK) +
					'<div class="spg-field">' + sub('Lighting') +
					select('lighting', s.lighting, selectGroups(QUOTE_LIGHTING)) + '</div>');
		},

		build: function (s) {
			var hold = s.hold === 'custom'
				? (s.holdCustom.trim() || 'I hold the book by its outer edge with my thumb on the margin and every other finger hidden behind it')
				: s.hold;
			var bg = s.background === 'custom' ? s.backgroundCustom.trim() : s.background;
			var camera = cameraLineFrom([
				['The camera is', s.camHeight],
				['The shot is framed as', s.camCrop],
				['The book sits', s.camPlacement]
			], s.camNote);

			var inkName = colorName(s.inkColor, INK_PALETTE);
			var ink = (inkName === 'Custom color' ? '' : inkName.toLowerCase() + ' ') + '(' + (normalizeHex(s.inkColor) || '#000000') + ')';

			var nailHex = normalizeHex(s.color) || '#E7C4C0';
			var nailLine = s.nail === 'A'
				? 'Unpainted. Bare natural keratin buffed to a soft sheen, with visible transparency at the long free edge and a distinct lunula at the base.'
				: 'Polished. Nail polish color: ' + nailHex + ' (' + colorName(nailHex, PALETTE).toLowerCase() + '), a deep glossy lacquer carried all the way to the tip of the long nail, with a wet-looking specular highlight running down its length. It must look like polish on a natural nail, never an acrylic or a thick extension.';
			if (s.nailNote.trim()) nailLine += ' ' + sentence(s.nailNote.trim());

			var flaws = quoteFlaws(s);
			var flawLine = flaws.length
				? '* Deliberate real-life imperfections (include every one of these, kept small and believable): ' + flaws.join('; ') + '.'
				: '* Everything is clean and evenly finished, but it must still read as a real photograph rather than a rendered one.';

			var pieces = [1, 2].map(function (n) { return accessoryPhrase(s, n, '#D4AF37'); }).filter(Boolean);
			var accLine = pieces.length
				? '* Accessories on the visible hand: ' + pieces.join(', and ') + '. They must not cover the thumb, the nail or any of the type.'
				: '* Accessories: none. The hand is bare.';

			var type = [
				'* Typeface: the page is set in ' + quoteFont(s) + '.',
				'* Size and space: ' + s.typeSize + '.',
				'* Alignment: ' + s.alignment + '.',
				'* Ink: the type is printed in ' + ink + ' on the paper of the reference book.'
			];
			if (s.emphasis) type.push('* Emphasis: ' + s.emphasis + '.');
			if (s.headingOn && s.heading.trim()) type.push('* The heading is ' + s.headingStyle + '.');
			if (s.accentOn && s.accentWhat.trim()) {
				var accentName = colorName(s.accentColor, INK_PALETTE);
				type.push('* A second colour: ' + s.accentWhat.trim() + ' is printed in ' +
					(accentName === 'Custom color' ? '' : accentName.toLowerCase() + ' ') +
					'(' + (normalizeHex(s.accentColor) || '#C98B94') + '), everything else in the main ink.');
			}

			var words = [];
			if (s.chapterOn && s.chapterText.trim()) {
				words.push('* A running head at the very top of the page, set small in spaced capitals: "' + s.chapterText.trim() + '"');
			}
			if (s.headingOn && s.heading.trim()) {
				words.push('* The heading, above the quote: "' + s.heading.trim() + '"');
			}
			words.push('* The quote itself, set exactly as below, keeping every line break exactly where it is written here:');
			words.push('');
			words.push((s.quoteMarks ? '“' : '') + s.quote.trim() + (s.quoteMarks ? '”' : ''));
			words.push('');
			if (s.closing.trim()) {
				words.push('* A last line, lower down the page, after a clear gap: "' + s.closing.trim() + '"');
			}
			if (s.handleOn && s.handleText.trim()) {
				var handleName = colorName(s.handleColor, INK_PALETTE);
				words.push('* [SIGNATURE LOCK] My Instagram handle, printed ' + s.handlePlace + '. It is ' + s.handleStyle +
					', in ' + (handleName === 'Custom color' ? '' : handleName.toLowerCase() + ' ') +
					'(' + (normalizeHex(s.handleColor) || '#000000') + '). It reads exactly: ' + s.handleText.trim() +
					' — reproduce it character by character, all in lower case, keeping the @ and the full stop, with no spaces, no capital letters and no extra punctuation. ' +
					'It sits in this exact position at this exact size in every image, it never overlaps the quote, the thumb or the nail, and nothing is ever printed beside it.');
			}
			if (s.followOn && s.followText.trim()) {
				var followName = colorName(s.followColor, INK_PALETTE);
				words.push('* In the bottom right corner of the page, small: the word "' + s.followText.trim() + '" in ' +
					(followName === 'Custom color' ? '' : followName.toLowerCase() + ' ') + '(' + (normalizeHex(s.followColor) || '#000000') + ').');
			}
			if (s.pageNumOn && s.pageNum.trim()) {
				words.push('* A page number centred at the bottom of the page, set small: "' + s.pageNum.trim() + '"');
			}
			words.push('* Print nothing else anywhere on the page. No extra words, no titles I have not asked for, no ornaments, no flourishes, no decorative rules, no logo, no signature.');

			return [
				QUOTE_PART_1,
				'',
				'Image Generation Prompt',
				'',
				QUOTE_CAMERA + (camera ? '\n* Camera & Framing: ' + camera : ''),
				'',
				'2. Subject & Hand Lock',
				'',
				'* The subject is my own ' + (s.hand === 'left' ? 'left' : 'right') + ' hand holding my book. ' + cap(sentence(hold)),
				QUOTE_THUMB_LOCK,
				QUOTE_BOOK_LOCK,
				'',
				'3. The Typography',
				'',
				QUOTE_PRINT_LOCK,
				type.join('\n'),
				'',
				'4. The Words On The Page (print exactly this and nothing more)',
				'',
				words.join('\n'),
				'',
				'5. The Nail',
				'',
				'* Nail finish: ' + nailLine,
				flawLine,
				'* Cuticle: real and healthy, with a clean ridge where the nail fold meets the nail plate — maintained, but not surgically perfect.',
				accLine,
				'',
				'6. Environment & Lighting',
				'',
				'* Background: ' + (bg ? cap(sentence(bg)) + ' ' : '') + QUOTE_BG_LOCK,
				'* Lighting: ' + s.lighting + '. However dark the rest of the frame is, every printed word stays easy to read and the long nail keeps a clean, believable highlight.',
				'',
				'7. Output',
				'',
				QUOTE_OUTPUT,
				'* Output Ratio: ' + s.ratio
			].join('\n');
		},

		warnings: function (s) {
			var w = [];
			if (!s.quote.trim()) w.push('The quote is empty — there is nothing to print on the page.');
			if (s.hold === 'custom' && !s.holdCustom.trim()) w.push('Custom hold is empty.');
			if (s.font === 'custom' && !s.fontCustom.trim()) w.push('Custom typeface is empty.');
			if (s.background === 'custom' && !s.backgroundCustom.trim()) w.push('Custom background is empty.');
			if (s.followOn && !s.followText.trim()) w.push('The “Follow” word is on but empty.');
			if (s.handleOn && !s.handleText.trim()) w.push('The handle is on but empty.');
			if (s.quote.split('\n').length > 12) w.push('That is a lot of lines for one page — the type may come out small and cramped.');
			return w;
		},

		context: function (s, kind) {
			return {
				'Subject': 'My ' + (s.hand === 'left' ? 'left' : 'right') + ' hand holding a page of my book, only the thumb visible',
				'The page': 'Printed book typography, set in ' + quoteFont(s).split(',')[0],
				'Theme': kind === 'quote_write' ? '' : (s.theme || ''),
				'On the page': kind === 'quote_text' || kind === 'quote_write' ? '' : s.quote,
				'Background': kind === 'quote_background' ? '' : (s.background === 'custom' ? s.backgroundCustom : s.background),
				'Aspect ratio': s.ratio
			};
		},

		summary: function (s) {
			var first = s.quote.trim().split('\n')[0] || '';
			if (first.length > 40) first = first.slice(0, 40) + '…';
			return [s.ratio, quoteFont(s).split(',')[0], s.nail === 'A' ? 'Bare nail' : 'Polish ' + s.color,
				first, camSummary(s, QUOTE_CAM_ANGLES, QUOTE_CAM_CROPS, QUOTE_CAM_PLACEMENTS)].filter(Boolean).join(' · ');
		}
	};

	/* ================================================================== *
	 * Master prompt blocks
	 *
	 * Every long fixed paragraph above can be replaced from
	 * Studio → AI Settings without touching this file. BLOCKS keeps the
	 * built-in text so the settings screen can offer "Load built-in".
	 * ================================================================== */

	var BLOCKS = {
		BACKGROUND_LOCK: BACKGROUND_LOCK,
		REF_POSE_LOCK: REF_POSE_LOCK,
		SOLE_PART_1: SOLE_PART_1,
		SOLE_PART_2_HEAD: SOLE_PART_2_HEAD,
		SOLE_ANATOMY_LOCK: SOLE_ANATOMY_LOCK,
		SOLE_NEW_POSE_LOCK: SOLE_NEW_POSE_LOCK,
		SOLE_VIBE: SOLE_VIBE,
		HAND_PART_1: HAND_PART_1,
		HAND_GEOMETRY: HAND_GEOMETRY,
		HAND_BIO: HAND_BIO,
		FEET_PART_1: FEET_PART_1,
		FEET_PART_3: FEET_PART_3,
		CAROUSEL_PART_1: CAROUSEL_PART_1,
		QUOTE_PART_1: QUOTE_PART_1,
		QUOTE_CAMERA: QUOTE_CAMERA,
		QUOTE_THUMB_LOCK: QUOTE_THUMB_LOCK,
		QUOTE_PRINT_LOCK: QUOTE_PRINT_LOCK,
		QUOTE_BOOK_LOCK: QUOTE_BOOK_LOCK,
		QUOTE_BG_LOCK: QUOTE_BG_LOCK,
		SELFIE_REALISM_HEADER: SELFIE_REALISM_HEADER,
		SELFIE_IDENTITY_LOCK: SELFIE_IDENTITY_LOCK,
		SELFIE_FACE_LOCK: SELFIE_FACE_LOCK,
		SELFIE_FABRIC_LOCK: SELFIE_FABRIC_LOCK,
		SELFIE_IPHONE_LOCK: SELFIE_IPHONE_LOCK,
		SELFIE_ANTI_AI: SELFIE_ANTI_AI
	};

	(function applyBlockOverrides() {
		var o = CONFIG.blocks || {};
		function has(k) { return typeof o[k] === 'string' && o[k].trim(); }
		if (has('BACKGROUND_LOCK')) BACKGROUND_LOCK = o.BACKGROUND_LOCK;
		if (has('REF_POSE_LOCK')) REF_POSE_LOCK = o.REF_POSE_LOCK;
		if (has('SOLE_PART_1')) SOLE_PART_1 = o.SOLE_PART_1;
		if (has('SOLE_PART_2_HEAD')) SOLE_PART_2_HEAD = o.SOLE_PART_2_HEAD;
		if (has('SOLE_ANATOMY_LOCK')) SOLE_ANATOMY_LOCK = o.SOLE_ANATOMY_LOCK;
		if (has('SOLE_NEW_POSE_LOCK')) SOLE_NEW_POSE_LOCK = o.SOLE_NEW_POSE_LOCK;
		if (has('SOLE_VIBE')) SOLE_VIBE = o.SOLE_VIBE;
		if (has('HAND_PART_1')) HAND_PART_1 = o.HAND_PART_1;
		if (has('HAND_GEOMETRY')) HAND_GEOMETRY = o.HAND_GEOMETRY;
		if (has('HAND_BIO')) HAND_BIO = o.HAND_BIO;
		if (has('FEET_PART_1')) FEET_PART_1 = o.FEET_PART_1;
		if (has('FEET_PART_3')) FEET_PART_3 = o.FEET_PART_3;
		if (has('CAROUSEL_PART_1')) CAROUSEL_PART_1 = o.CAROUSEL_PART_1;
		if (has('QUOTE_PART_1')) QUOTE_PART_1 = o.QUOTE_PART_1;
		if (has('QUOTE_CAMERA')) QUOTE_CAMERA = o.QUOTE_CAMERA;
		if (has('QUOTE_THUMB_LOCK')) QUOTE_THUMB_LOCK = o.QUOTE_THUMB_LOCK;
		if (has('QUOTE_PRINT_LOCK')) QUOTE_PRINT_LOCK = o.QUOTE_PRINT_LOCK;
		if (has('QUOTE_BOOK_LOCK')) QUOTE_BOOK_LOCK = o.QUOTE_BOOK_LOCK;
		if (has('QUOTE_BG_LOCK')) QUOTE_BG_LOCK = o.QUOTE_BG_LOCK;
		if (has('SELFIE_REALISM_HEADER')) SELFIE_REALISM_HEADER = o.SELFIE_REALISM_HEADER;
		if (has('SELFIE_IDENTITY_LOCK')) SELFIE_IDENTITY_LOCK = o.SELFIE_IDENTITY_LOCK;
		if (has('SELFIE_FACE_LOCK')) SELFIE_FACE_LOCK = o.SELFIE_FACE_LOCK;
		if (has('SELFIE_FABRIC_LOCK')) SELFIE_FABRIC_LOCK = o.SELFIE_FABRIC_LOCK;
		if (has('SELFIE_IPHONE_LOCK')) SELFIE_IPHONE_LOCK = o.SELFIE_IPHONE_LOCK;
		if (has('SELFIE_ANTI_AI')) SELFIE_ANTI_AI = o.SELFIE_ANTI_AI;
	})();

	var TOOLS = [SOLE, HAND, FEET, FEET_CAROUSEL, SELFIE, QUOTE];

	/* ------------------------------------------------------------------ *
	 * App shell
	 * ------------------------------------------------------------------ */

	function stateKey(tool) { return 'spg_' + tool.id + '_state_v1'; }

	/* ---- Prompt history: the last 30 prompts you copied ---- */

	var HISTORY_KEY = 'spg_history_v1';
	var HISTORY_MAX = 30;

	function historyAll() {
		var list = lsGet(HISTORY_KEY, []);
		return Array.isArray(list) ? list : [];
	}

	function historyFor(toolId) {
		return historyAll().filter(function (h) { return h.tool === toolId; });
	}

	function historyAdd(toolId, title, text, snap) {
		var list = historyAll();
		if (list.length && list[0].tool === toolId && list[0].text === text) return list;
		list.unshift({ id: Date.now().toString(36), tool: toolId, title: title, text: text, state: snap, at: Date.now() });
		list = list.slice(0, HISTORY_MAX);
		lsSet(HISTORY_KEY, list);
		return list;
	}

	function historyRemove(id) {
		var list = historyAll().filter(function (h) { return h.id !== id; });
		lsSet(HISTORY_KEY, list);
		return list;
	}

	function timeAgo(ms) {
		var d = Math.max(0, Date.now() - ms);
		var m = Math.round(d / 60000);
		if (m < 1) return 'just now';
		if (m < 60) return m + ' min ago';
		var h = Math.round(m / 60);
		if (h < 24) return h + (h === 1 ? ' hour ago' : ' hours ago');
		var days = Math.round(h / 24);
		return days + (days === 1 ? ' day ago' : ' days ago');
	}

	function historyBlock() {
		return '<div class="spg-history" data-history>' +
			'<p class="spg-hint">Every prompt you copy is kept here, on this device only. Load one to bring back the exact setup that made it.</p>' +
			'<ul class="spg-saved-list" data-history-list></ul>' +
			'<p class="spg-hint" data-history-msg></p>' +
			'</div>';
	}

	/* Rough token estimate: good enough to warn before a model truncates. */
	function estimateTokens(text) {
		return Math.round(text.length / 4);
	}

	function mergeState(tool, v) {
		var s = clone(tool.defaults);
		if (v && typeof v === 'object') {
			Object.keys(s).forEach(function (k) {
				if (k in v && typeof v[k] === typeof s[k]) s[k] = v[k];
			});
		}
		return s;
	}

	function snapshot(s) {
		var copy = clone(s);
		delete copy.aiIdea;
		return copy;
	}

	function shell() {
		return '' +
			'<div class="spg-app">' +
			'<header class="spg-header">' +
			'<p class="spg-eyebrow">Studio</p><h1 class="spg-title">Prompt Generator</h1>' +
			'</header>' +
			'<section class="spg-home" data-view="home">' +
			'<p class="spg-lead">Pick a master prompt to fill in.</p>' +
			'<div class="spg-tiles">' +
			TOOLS.filter(function (t) { return !t.hidden; }).map(function (t) {
				return '<button type="button" class="spg-tile" data-open="' + t.id + '">' +
					'<span class="spg-tile-icon">' + t.icon + '</span>' +
					'<span class="spg-tile-name">' + esc(t.title) + '</span>' +
					'<span class="spg-tile-desc">' + esc(t.desc) + '</span>' +
					'</button>';
			}).join('') +
			'</div>' +
			'</section>' +
			'<section class="spg-gen" data-view="gen" hidden></section>' +
			'</div>';
	}

	function panel(tool, s, setupsOpen) {
		return '' +
			'<div class="spg-gen-bar">' +
			'<button type="button" class="spg-link" data-back>&larr; All prompts</button>' +
			'<h2 class="spg-gen-title">' + esc(tool.title) + '</h2>' +
			'<div class="spg-bar-actions">' +
			'<button type="button" class="spg-btn" data-setups-toggle aria-expanded="' + (setupsOpen ? 'true' : 'false') + '">☰ Setups</button>' +
			'<button type="button" class="spg-btn" data-history-toggle aria-expanded="false">🕘 History</button>' +
			'<button type="button" class="spg-link" data-reset-all>Reset all</button>' +
			'</div>' +
			'</div>' +
			(tool.modes ? '<div class="spg-modes" role="tablist">' + tool.modes.map(function (m) {
				var on = m[0] === tool.id;
				return '<button type="button" role="tab" class="spg-mode' + (on ? ' is-active' : '') + '" data-mode="' + m[0] + '" aria-selected="' + on + '">' + esc(m[1]) + '</button>';
			}).join('') + '</div>' : '') +
			'<div class="spg-setups" data-setups' + (setupsOpen ? '' : ' hidden') + '>' +
			'<p class="spg-hint spg-setups-lead">Save every field of this ' + esc(tool.title.toLowerCase()) + ' prompt as one setup, then load it any time to recreate it exactly.</p>' +
			libBlock(tool.id + '_setup', 'Saved setups', 'Save current setup', 'Name this setup, e.g. Café latte, mauve nails') +
			'</div>' +
			'<div class="spg-setups" data-history-drawer hidden>' +
			'<p class="spg-hint spg-setups-lead">Prompt history</p>' +
			historyBlock() +
			'</div>' +
			'<div class="spg-grid">' +
			'<form class="spg-form" autocomplete="off" onsubmit="return false">' + tool.form(s) + '</form>' +
			'<aside class="spg-out">' +
			'<div class="spg-out-head">' +
			'<h3>Complete prompt</h3>' +
			'<button type="button" class="spg-btn spg-btn-primary" data-copy>Copy prompt</button>' +
			'</div>' +
			'<ul class="spg-warn" data-warn hidden></ul>' +
			'<pre class="spg-preview" data-preview tabindex="0"></pre>' +
			'<p class="spg-count" data-count></p>' +
			'<p class="spg-sr" data-live aria-live="polite"></p>' +
			'</aside>' +
			'</div>';
	}

	function mount(root) {
		root.innerHTML = shell();
		var home = root.querySelector('[data-view="home"]');
		var gen = root.querySelector('[data-view="gen"]');
		var tool = null;
		var state = null;
		var refreshers = [];

		function update() {
			var text = tool.build(state);
			var tokens = estimateTokens(text);
			gen.querySelector('[data-preview]').textContent = text;
			gen.querySelector('[data-count]').textContent =
				text.length.toLocaleString() + ' characters · ~' + tokens.toLocaleString() + ' tokens · Ratio ' + state.ratio;
			var warn = gen.querySelector('[data-warn]');
			var w = tool.warnings(state);
			if (text.length > 12000) {
				w = w.concat(['This prompt is very long (' + text.length.toLocaleString() +
					' characters). Image models often ignore the end of a prompt this size — consider shortening the pose or background.']);
			}
			clearTimeout(update.live);
			update.live = setTimeout(function () {
				var live = gen.querySelector('[data-live]');
				if (live) live.textContent = 'Prompt updated: ' + text.length.toLocaleString() + ' characters, ' + w.length + ' notes.';
			}, 900);
			warn.hidden = !w.length;
			warn.innerHTML = w.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('');
			lsSet(stateKey(tool), state);
			gen.querySelectorAll('[data-clear]').forEach(function (b) {
				b.hidden = !state[b.getAttribute('data-clear')];
			});
			syncEraseButtons(gen);
			refreshers.forEach(function (fn) { fn(); });
		}

		function render(setupsOpen) {
			refreshers = [];
			if (tool.clean) {
				Object.keys(tool.clean).forEach(function (k) {
					if (typeof state[k] === 'string') state[k] = tool.clean[k](state[k]);
				});
			}
			gen.innerHTML = panel(tool, state, setupsOpen);
			bind();
			addEraseButtons(gen);
			update();
		}

		function open(id) {
			tool = TOOLS.filter(function (t) { return t.id === id; })[0];
			state = mergeState(tool, lsGet(stateKey(tool), null));
			home.hidden = true;
			gen.hidden = false;
			render(false);
			root.scrollIntoView({ block: 'start', behavior: 'smooth' });
		}

		function close() {
			gen.hidden = true;
			home.hidden = false;
			gen.innerHTML = '';
			refreshers = [];
		}

		function bind() {
			var form = gen.querySelector('.spg-form');

			function applyShowIf() {
				/* "a=b", "a!=b", or "a=b|c=d" (shown when any condition matches) */
				gen.querySelectorAll('[data-show-if]').forEach(function (el) {
					el.hidden = !el.getAttribute('data-show-if').split('|').some(function (cond) {
						var neg = cond.indexOf('!=') > -1;
						var c = cond.split(neg ? '!=' : '=');
						return (String(state[c[0]]) === c[1]) !== neg;
					});
				});
			}
			applyShowIf();

			/* ---- generic field binding ---- */
			form.addEventListener('input', function (e) {
				var t = e.target;
				if (!t.name || t.type === 'radio' || t.type === 'checkbox' || t.hasAttribute('data-color-text')) return;
				if (t.name in state) { state[t.name] = t.value; syncClear(t.name); update(); }
			});

			/* Tidy fields with fixed wording when you leave them (e.g. a pasted "A glimpse of …"). */
			function cleanField(name) {
				var fn = tool.clean && tool.clean[name];
				var el = form.elements[name];
				if (!fn || !el) return;
				var v = fn(el.value);
				if (v !== el.value) {
					el.value = v;
					state[name] = v;
					syncClear(name);
					update();
				}
			}
			form.addEventListener('focusout', function (e) {
				if (e.target.name) cleanField(e.target.name);
			});
			form.addEventListener('paste', function (e) {
				var name = e.target.name;
				if (name && tool.clean && tool.clean[name]) setTimeout(function () { cleanField(name); }, 0);
			});

			/* One-click erase (×) buttons */
			function syncClear(name) {
				var b = gen.querySelector('[data-clear="' + name + '"]');
				if (b) b.hidden = !form.elements[name].value;
			}
			gen.querySelectorAll('[data-clear]').forEach(function (b) {
				b.addEventListener('click', function () {
					var name = b.getAttribute('data-clear');
					var el = form.elements[name];
					el.value = '';
					state[name] = '';
					b.hidden = true;
					update();
					el.focus();
				});
			});

			form.addEventListener('change', function (e) {
				var t = e.target;
				if (t.type === 'radio' && t.name in state) {
					state[t.name] = t.hasAttribute('data-bool') ? t.value === 'true' : t.value;
					applyShowIf();
					update();
					var shown = gen.querySelector('[data-show-if="' + t.name + '=' + t.value + '"]');
					var first = shown && shown.querySelector('textarea, input[type="text"]');
					if (first && t.value === 'true' && !first.value) first.focus();
				} else if (t.type === 'checkbox' && t.name in state) {
					state[t.name] = t.checked;
					applyShowIf();
					update();
				} else if (t.tagName === 'SELECT' && t.name in state) {
					state[t.name] = t.value;
					if (tool.onSelect && tool.onSelect[t.name]) tool.onSelect[t.name](state, form);
					applyShowIf();
					update();
					var revealed = gen.querySelector('[data-show-if="' + t.name + '=' + t.value + '"]');
					var input = revealed && revealed.querySelector('textarea, input[type="text"]');
					if (input && !input.value) input.focus();
				} else if (t.hasAttribute('data-color-text')) {
					t.value = normalizeHex(t.value) || t.value;
				}
			});

			/* ---- color pickers (one per [data-color] box) ---- */
			gen.querySelectorAll('[data-color]').forEach(function (box) {
				var key = box.getAttribute('data-color');
				var pal = (tool.palettes && tool.palettes[key]) || tool.palette || PALETTE;
				var picker = box.querySelector('[data-color-picker]');
				var text = box.querySelector('[data-color-text]');
				var nameEl = box.querySelector('[data-color-name]');
				var err = box.querySelector('[data-color-err]');
				function set(hex, fromText, silent) {
					var n = normalizeHex(hex);
					err.hidden = !!n;
					if (!n) return;
					state[key] = n;
					picker.value = n;
					if (!fromText) text.value = n;
					box.querySelectorAll('.spg-swatch').forEach(function (b) {
						b.classList.toggle('is-active', b.getAttribute('data-swatch') === n);
					});
					var exact = colorName(n, pal, true);
					var near = colorName(n, pal);
					nameEl.textContent = exact !== 'Custom color' ? exact
						: (near === 'Custom color' ? 'Custom color' : 'Custom · near ' + near);
					if (!silent) update();
				}
				picker.addEventListener('input', function () { set(picker.value, false); });
				text.addEventListener('input', function () { set(text.value, true); });
				box.querySelectorAll('[data-swatch]').forEach(function (b) {
					b.addEventListener('click', function () { set(b.getAttribute('data-swatch'), false); });
				});
				var favBtn = box.querySelector('[data-fav]');
				function markFav() {
					if (!favBtn) return;
					var on = favColors().indexOf(normalizeHex(state[key]) || '') > -1;
					favBtn.classList.toggle('is-on', on);
					favBtn.title = on ? 'Remove from my colors' : 'Save this color';
				}
				if (favBtn) {
					favBtn.addEventListener('click', function () {
						favToggle(state[key]);
						render(false);
					});
				}
				set(state[key], false, true);
				markFav();
			});

			/* ---- text tools: erase & paste / erase / restore ---- */
			gen.querySelectorAll('[data-tt]').forEach(function (b) {
				var target = b.getAttribute('data-target');
				var field = form.elements[target];
				var msg = gen.querySelector('[data-tt-msg="' + target + '"]');
				function flash(m) {
					msg.textContent = m;
					clearTimeout(flash.t);
					flash.t = setTimeout(function () { msg.textContent = ''; }, 3500);
				}
				function setVal(v) { field.value = v; state[target] = v; update(); }
				b.addEventListener('click', function () {
					var action = b.getAttribute('data-tt');
					if (action === 'restore') { setVal(tool.defaults[target]); flash('Default restored.'); return; }
					setVal('');
					field.focus();
					if (action !== 'paste') return;
					if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
						navigator.clipboard.readText().then(function (txt) {
							if (txt && txt.trim()) { setVal(txt.trim()); flash('Pasted from clipboard.'); }
							else flash('Clipboard is empty — type or paste your text.');
						}).catch(function () { flash('Cleared. Press Ctrl+V / ⌘V to paste.'); });
					} else {
						flash('Cleared. Press Ctrl+V / ⌘V to paste.');
					}
				});
			});

			/* ---- quick preset buttons (tool.presets) ---- */
			gen.querySelectorAll('[data-preset]').forEach(function (b) {
				b.addEventListener('click', function () {
					var fn = tool.presets && tool.presets[b.getAttribute('data-preset')];
					if (!fn) return;
					fn(state);
					render(false);
				});
			});

			/* ---- reset the camera fields back to the defaults ---- */
			gen.querySelectorAll('[data-reset-cam]').forEach(function (b) {
				b.addEventListener('click', function () {
					CAM_KEYS.forEach(function (k) {
						if (k in state) state[k] = tool.defaults[k];
					});
					render(false);
					var msg = gen.querySelector('[data-reset-cam-msg]');
					if (msg) {
						msg.textContent = 'Camera settings reset.';
						setTimeout(function () { if (msg.isConnected) msg.textContent = ''; }, 3500);
					}
				});
			});

			/* ---- AI refine ---- */
			gen.querySelectorAll('[data-ai]').forEach(function (box) {
				var kind = box.getAttribute('data-kind');
				var target = box.getAttribute('data-target');
				var ideaName = box.getAttribute('data-idea');
				var field = form.elements[target];
				var ideaField = ideaName ? form.elements[ideaName] : null;
				var run = box.querySelector('[data-ai-run]');
				var many = box.querySelector('[data-ai-variants]');
				var undo = box.querySelector('[data-ai-undo]');
				var options = box.querySelector('[data-ai-options]');
				var msg = box.querySelector('[data-ai-msg]');
				var undoStack = [];

				function note(m, isErr) {
					msg.textContent = m;
					msg.classList.toggle('spg-err', !!isErr);
				}

				box.querySelectorAll('[data-ai-example]').forEach(function (chip) {
					chip.addEventListener('click', function () {
						var into = ideaField || field;
						if (into.disabled) return;
						into.value = chip.getAttribute('data-ai-example');
						state[into.name] = into.value;
						update();
						into.focus();
					});
				});

				var extraStack = [];
				var fields = (tool.aiFields && tool.aiFields[kind]) || null;

				function apply(text) {
					undoStack.push(field.value);
					extraStack.push(fields && fields.before ? fields.before(state) : null);
					if (undoStack.length > 20) { undoStack.shift(); extraStack.shift(); }
					var clean = tool.clean && tool.clean[target] ? tool.clean[target](text) : text;
					if (fields && fields.apply) clean = fields.apply(state, clean, form);
					field.value = clean;
					state[target] = clean;
					syncClear(target);
					update();
					undo.hidden = false;
					undo.textContent = undoStack.length > 1 ? 'Undo (' + undoStack.length + ')' : 'Undo';
				}

				function showOptions(texts) {
					options.innerHTML = '<p class="spg-hint">Pick the one you like. The others are discarded.</p>' +
						texts.map(function (t, i) {
							var shown = fields && fields.preview ? fields.preview(t) : t;
							return '<button type="button" class="spg-ai-option" data-ai-pick="' + i + '">' +
								'<strong>Option ' + (i + 1) + '</strong><span>' + esc(shown) + '</span></button>';
						}).join('');
					options.hidden = false;
					options.querySelectorAll('[data-ai-pick]').forEach(function (b) {
						b.addEventListener('click', function () {
							apply(texts[parseInt(b.getAttribute('data-ai-pick'), 10)]);
							options.hidden = true;
							options.innerHTML = '';
							note('Option applied. Edit it freely, or refine again.');
						});
					});
				}

				function ask(variants) {
					var idea = ideaField ? ideaField.value.trim() : field.value.trim();
					var current = ideaField ? field.value.trim() : '';
					if (!idea && !current) {
						note('Write something first.', true);
						(ideaField || field).focus();
						return;
					}
					var btn = variants > 1 ? many : run;
					var label = btn.textContent;
					run.disabled = true;
					many.disabled = true;
					btn.textContent = variants > 1 ? 'Writing 3…' : 'Refining…';
					field.classList.add('is-loading');
					options.hidden = true;
					options.innerHTML = '';
					note(ideaField && !idea ? 'Polishing the current text…' : 'Writing…');

					api('POST', 'refine', {
						kind: kind, idea: idea, current: current,
						context: tool.context(state, kind), variants: variants
					})
						.then(function (d) {
							var texts = (d.texts && d.texts.length ? d.texts : [d.text]).filter(Boolean);
							if (!texts.length) throw new Error('The AI returned an empty answer. Try again.');
							var left = (d.cap && d.used) ? ' · ' + (d.cap - d.used) + ' refines left today' : '';
							if (variants > 1 && texts.length > 1) {
								showOptions(texts);
								note('Three options below.' + left);
							} else {
								apply(texts[0]);
								note('Done. Edit it freely, or refine again.' + left);
							}
						})
						.catch(function (err) { note(err.message || 'Something went wrong. Try again.', true); })
						.then(function () {
							run.disabled = false;
							many.disabled = false;
							btn.textContent = label;
							field.classList.remove('is-loading');
						});
				}

				run.addEventListener('click', function () { ask(1); });
				many.addEventListener('click', function () { ask(3); });

				undo.addEventListener('click', function () {
					if (!undoStack.length) return;
					var prev = undoStack.pop();
					var extra = extraStack.pop();
					if (extra && fields && fields.restore) fields.restore(state, extra, form);
					field.value = prev;
					state[target] = prev;
					undo.hidden = !undoStack.length;
					undo.textContent = undoStack.length > 1 ? 'Undo (' + undoStack.length + ')' : 'Undo';
					syncClear(target);
					update();
					note('Previous text restored.');
				});
			});

			/* ---- libraries: saved poses and saved setups ---- */
			gen.querySelectorAll('[data-lib]').forEach(function (el) {
				var kind = el.getAttribute('data-lib');
				var isSetup = /_setup$/.test(kind);
				var target = tool.libTargets[kind];
				bindLibrary(el, libStore(kind), isSetup ? {
					noun: 'setup',
					get: function () { return snapshot(state); },
					set: function (v) {
						var known = Object.keys(tool.defaults);
						var saved = v && typeof v === 'object' ? Object.keys(v) : [];
						var missing = known.filter(function (k) { return k !== 'aiIdea' && saved.indexOf(k) < 0; });
						var gone = saved.filter(function (k) { return known.indexOf(k) < 0; });
						state = mergeState(tool, v);
						state.aiIdea = '';
						render(true);
						if (missing.length || gone.length) {
							var parts = [];
							if (missing.length) parts.push(missing.length + ' newer field' + (missing.length === 1 ? '' : 's') + ' left at the default');
							if (gone.length) parts.push(gone.length + ' old field' + (gone.length === 1 ? '' : 's') + ' no longer used');
							window.setTimeout(function () {
								var m = document.querySelector('[data-lib="' + tool.id + '_setup"] [data-lib-msg]');
								if (m) {
									m.textContent = 'Loaded, but this setup is from an older version: ' + parts.join(', ') + '.';
									m.classList.remove('spg-err');
								}
							}, 0);
						}
					},
					isActive: function (v) { return JSON.stringify(snapshot(mergeState(tool, v))) === JSON.stringify(snapshot(state)); },
					snippet: function (v) { return tool.summary(mergeState(tool, v)); },
					empty: 'No saved setups yet. When everything looks right, tap “Save current setup”.'
				} : {
					noun: (tool.libNouns && tool.libNouns[kind]) || 'pose',
					get: function () { return state[target].trim(); },
					set: function (v) {
						state[target] = v;
						form.elements[target].value = v;
						update();
					},
					isActive: function (v) { return v === state[target].trim(); },
					snippet: function (v) { return v; },
					empty: 'Nothing saved yet. When one looks perfect, save it with the button above.'
				});
			});

			/* ---- top bar ---- */
			gen.querySelector('[data-back]').addEventListener('click', close);

			gen.querySelectorAll('[data-mode]').forEach(function (b) {
				b.addEventListener('click', function () {
					var id = b.getAttribute('data-mode');
					if (id !== tool.id) open(id);
				});
			});

			var setupsToggle = gen.querySelector('[data-setups-toggle]');
			setupsToggle.addEventListener('click', function () {
				var box = gen.querySelector('[data-setups]');
				box.hidden = !box.hidden;
				setupsToggle.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
			});

			var historyToggle = gen.querySelector('[data-history-toggle]');
			historyToggle.addEventListener('click', function () {
				var box = gen.querySelector('[data-history-drawer]');
				box.hidden = !box.hidden;
				historyToggle.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
				if (!box.hidden) drawHistory();
			});

			var historyList = gen.querySelector('[data-history-list]');
			var historyMsg = gen.querySelector('[data-history-msg]');

			function drawHistory() {
				if (!historyList || !historyList.isConnected) return;
				var items = historyFor(tool.id);
				if (!items.length) {
					historyList.innerHTML = '<li class="spg-saved-empty">Nothing yet. Every prompt you copy lands here.</li>';
					return;
				}
				historyList.innerHTML = items.map(function (h) {
					var snip = String(h.title || h.text).replace(/\s+/g, ' ');
					if (snip.length > 100) snip = snip.slice(0, 100) + '…';
					return '<li class="spg-saved-item">' +
						'<button type="button" class="spg-saved-load" data-hist-load="' + esc(h.id) + '" title="Load this setup">' +
						'<strong>' + esc(timeAgo(h.at)) + '</strong><span>' + esc(snip) + '</span></button>' +
						'<button type="button" class="spg-btn spg-btn-mini" data-hist-copy="' + esc(h.id) + '">Copy</button>' +
						'<button type="button" class="spg-saved-del" data-hist-del="' + esc(h.id) + '" title="Delete" aria-label="Delete">×</button>' +
						'</li>';
				}).join('');
			}

			function histNote(m) {
				if (!historyMsg) return;
				historyMsg.textContent = m;
				clearTimeout(histNote.t);
				histNote.t = setTimeout(function () { if (historyMsg.isConnected) historyMsg.textContent = ''; }, 3500);
			}

			historyList.addEventListener('click', function (e) {
				var load = e.target.closest('[data-hist-load]');
				var copy = e.target.closest('[data-hist-copy]');
				var del = e.target.closest('[data-hist-del]');
				var id = (load || copy || del) && (load || copy || del).getAttribute(load ? 'data-hist-load' : copy ? 'data-hist-copy' : 'data-hist-del');
				var hit = historyAll().filter(function (h) { return h.id === id; })[0];
				if (!hit) return;
				if (load) {
					if (!hit.state) { histNote('That entry has no saved setup, only its text.'); return; }
					state = mergeState(tool, hit.state);
					state.aiIdea = '';
					render(false);
					/* render() rebuilt the panel, so talk to the new nodes. */
					var drawer = gen.querySelector('[data-history-drawer]');
					var toggle = gen.querySelector('[data-history-toggle]');
					if (drawer) drawer.hidden = false;
					if (toggle) toggle.setAttribute('aria-expanded', 'true');
					var fresh = gen.querySelector('[data-history-msg]');
					if (fresh) {
						fresh.textContent = 'Setup restored.';
						setTimeout(function () { if (fresh.isConnected) fresh.textContent = ''; }, 3500);
					}
				} else if (copy) {
					copyText(hit.text).then(function () { histNote('Copied that prompt.'); })
						.catch(function () { histNote('Copy failed — open it and select the text.'); });
				} else if (del) {
					historyRemove(id);
					drawHistory();
					histNote('Deleted.');
				}
			});

			drawHistory();

			gen.querySelector('[data-reset-all]').addEventListener('click', function () {
				if (window.confirm('Reset every field of the ' + tool.title + ' prompt to the defaults?')) {
					state = clone(tool.defaults);
					render(false);
				}
			});

			var copyBtn = gen.querySelector('[data-copy]');
			copyBtn.addEventListener('click', function () {
				var text = tool.build(state);
				/* Keep it in history even if the clipboard itself refuses. */
				historyAdd(tool.id, tool.summary(state), text, snapshot(state));
				drawHistory();
				copyText(text).then(function () {
					copyBtn.textContent = 'Copied ✓';
					copyBtn.classList.add('is-done');
				}).catch(function () {
					copyBtn.textContent = 'Copy failed — select text';
				}).then(function () {
					setTimeout(function () {
						copyBtn.textContent = 'Copy prompt';
						copyBtn.classList.remove('is-done');
					}, 2000);
				});
			});
		}

		function bindLibrary(el, store, o) {
			var list = el.querySelector('[data-lib-list]');
			var msg = el.querySelector('[data-lib-msg]');
			var count = el.querySelector('[data-lib-count]');
			var saveForm = el.querySelector('[data-lib-form]');
			var openBtn = el.querySelector('[data-lib-open]');
			var nameInput = el.querySelector('[data-lib-name]');
			var filterInput = el.querySelector('[data-lib-filter]');
			var items = [];
			var loaded = false;

			function note(m, isErr) {
				msg.textContent = m;
				msg.classList.toggle('spg-err', !!isErr);
				clearTimeout(note.t);
				if (!isErr && m) note.t = setTimeout(function () { msg.textContent = ''; }, 3500);
			}

			function draw() {
				if (!loaded || !el.isConnected) return;
				var q = filterInput.value.trim().toLowerCase();
				var shown = items.filter(function (p) {
					var snip = String(o.snippet(p.value));
					return !q || p.name.toLowerCase().indexOf(q) > -1 || snip.toLowerCase().indexOf(q) > -1;
				});
				count.textContent = items.length ? '(' + items.length + ')' : '';
				filterInput.hidden = items.length < 6;
				if (!items.length) { list.innerHTML = '<li class="spg-saved-empty">' + esc(o.empty) + '</li>'; return; }
				if (!shown.length) { list.innerHTML = '<li class="spg-saved-empty">Nothing matches “' + esc(q) + '”.</li>'; return; }
				list.innerHTML = shown.map(function (p) {
					var snip = String(o.snippet(p.value)).replace(/\s+/g, ' ');
					if (snip.length > 110) snip = snip.slice(0, 110) + '…';
					return '<li class="spg-saved-item' + (o.isActive(p.value) ? ' is-active' : '') + '">' +
						'<button type="button" class="spg-saved-load" data-load="' + esc(p.id) + '" title="Load">' +
						'<strong>' + esc(p.name) + '</strong><span>' + esc(snip) + '</span></button>' +
						'<button type="button" class="spg-saved-del" data-del="' + esc(p.id) + '" title="Delete" aria-label="Delete ' + esc(p.name) + '">×</button>' +
						'</li>';
				}).join('');
			}

			var timer;
			refreshers.push(function () { clearTimeout(timer); timer = setTimeout(draw, 250); });

			function openSave() {
				var v = o.get();
				if (!v || (typeof v === 'string' && !v.trim())) { note('The ' + o.noun + ' is empty. Nothing to save.', true); return; }
				note('');
				saveForm.hidden = false;
				openBtn.hidden = true;
				nameInput.value = '';
				nameInput.focus();
			}

			function closeSave() {
				saveForm.hidden = true;
				openBtn.hidden = false;
			}

			function doSave() {
				var name = nameInput.value.trim();
				if (!name) { nameInput.focus(); note('Give it a name.', true); return; }
				var exists = items.some(function (p) { return p.name.toLowerCase() === name.toLowerCase(); });
				if (exists && !window.confirm('“' + name + '” already exists. Replace it?')) return;
				store.save(name, o.get()).then(function (l) {
					items = l;
					closeSave();
					draw();
					note((exists ? 'Updated “' : 'Saved “') + name + '”.');
				}).catch(function (e) { note(e.message, true); });
			}

			openBtn.addEventListener('click', openSave);
			el.querySelector('[data-lib-cancel]').addEventListener('click', closeSave);
			el.querySelector('[data-lib-save]').addEventListener('click', doSave);
			nameInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); doSave(); }
				if (e.key === 'Escape') closeSave();
			});
			filterInput.addEventListener('input', draw);

			list.addEventListener('click', function (e) {
				var load = e.target.closest('[data-load]');
				var del = e.target.closest('[data-del]');
				var find = function (id) { return items.filter(function (x) { return x.id === id; })[0]; };
				if (load) {
					var p = find(load.getAttribute('data-load'));
					if (!p) return;
					o.set(p.value);
					if (el.isConnected) { draw(); note('Loaded “' + p.name + '”.'); }
				} else if (del) {
					var d = find(del.getAttribute('data-del'));
					if (!d || !window.confirm('Delete “' + d.name + '”?')) return;
					store.remove(d.id).then(function (l) {
						items = l;
						draw();
						note('Deleted “' + d.name + '”.');
					}).catch(function (err) { note(err.message, true); });
				}
			});

			var exportBtn = el.querySelector('[data-lib-export]');
			var importBtn = el.querySelector('[data-lib-import]');
			var fileInput = el.querySelector('[data-lib-file]');

			exportBtn.addEventListener('click', function () {
				if (!items.length) { note('Nothing to export yet.', true); return; }
				var payload = {
					studio: 'library',
					kind: el.getAttribute('data-lib'),
					exported: new Date().toISOString(),
					items: items.map(function (i) { return { name: i.name, value: i.value }; })
				};
				download('studio-' + el.getAttribute('data-lib') + '-' + new Date().toISOString().slice(0, 10) + '.json',
					JSON.stringify(payload, null, 2));
				note('Exported ' + items.length + ' ' + (items.length === 1 ? o.noun : o.noun + 's') + '.');
			});

			importBtn.addEventListener('click', function () { fileInput.click(); });

			fileInput.addEventListener('change', function () {
				var file = fileInput.files && fileInput.files[0];
				if (!file) return;
				var reader = new FileReader();
				reader.onload = function () {
					var data;
					try { data = JSON.parse(String(reader.result)); }
					catch (err) { note('That file is not valid JSON.', true); return; }
					var incoming = Array.isArray(data) ? data : (data && Array.isArray(data.items) ? data.items : null);
					if (!incoming || !incoming.length) { note('That file had no saved items in it.', true); return; }
					if (data && data.kind && data.kind !== el.getAttribute('data-lib') &&
						!window.confirm('That file was exported from “' + data.kind + '”, not “' + el.getAttribute('data-lib') + '”. Import it anyway?')) {
						return;
					}
					store.importItems(incoming).then(function (r) {
						items = r.items;
						draw();
						note('Imported: ' + r.added + ' added, ' + r.updated + ' updated' + (r.skipped ? ', ' + r.skipped + ' skipped' : '') + '.');
					}).catch(function (err) { note(err.message, true); });
				};
				reader.onerror = function () { note('Could not read that file.', true); };
				reader.readAsText(file);
				fileInput.value = '';
			});

			store.list().then(function (l) {
				items = l;
				loaded = true;
				draw();
			}).catch(function (e) {
				list.innerHTML = '';
				note('Could not load: ' + e.message, true);
			});
		}

		root.querySelectorAll('[data-open]').forEach(function (b) {
			b.addEventListener('click', function () { open(b.getAttribute('data-open')); });
		});

		/* [studio tool="sole" home="no"] */
		var wanted = String(CONFIG.openTool || '').trim();
		if (wanted && TOOLS.some(function (t) { return t.id === wanted; })) {
			open(wanted);
			root.scrollIntoView = root.scrollIntoView || function () {};
		}
		if (CONFIG.showHome === false) {
			root.classList.add('spg-no-home');
		}
	}

	function init() {
		document.querySelectorAll('.spg-root').forEach(function (root) {
			if (!root.getAttribute('data-spg-ready')) {
				root.setAttribute('data-spg-ready', '1');
				mount(root);
			}
		});
	}

	window.SPG = { tools: TOOLS, blocks: BLOCKS };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
