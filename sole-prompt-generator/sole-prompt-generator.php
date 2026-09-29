<?php
/**
 * Plugin Name:       Studio
 * Description:       Prompt Studio for Gemini: fill in only the variables of your master prompts (Sole, Hand, Feet, Selfie, Quote) and copy the complete prompt. Includes Groq-powered AI refine, per-user saved poses and setups, prompt history and editable master prompt blocks. Your saved data is never deleted, not even when the plugin is removed. Admin menu: Studio. Shortcode: [studio]
 * Version:           4.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Studio
 * License:           GPL-2.0-or-later
 * Text Domain:       sole-prompt-generator
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPG_VERSION', '4.5.0' );
define( 'SPG_URL', plugin_dir_url( __FILE__ ) );
define( 'SPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPG_GROQ_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions' );
define( 'SPG_DEFAULT_MODEL', 'openai/gpt-oss-120b' );
define( 'SPG_LIB_MAX', 300 );
define( 'SPG_DEFAULT_DAILY_CAP', 200 );

function spg_load_textdomain() {
	load_plugin_textdomain( 'sole-prompt-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'spg_load_textdomain' );

/* -------------------------------------------------------------------------
 * AI presets (one per refine field)
 * ---------------------------------------------------------------------- */

function spg_preset_sole_pose() {
	return <<<'PROMPT'
You are a pose director who writes the "POSE DETAIL" section of a photorealistic macro photography prompt for Google Gemini's image model. The photo is a close-up of a woman's bare foot soles with exceptionally long natural toenails. The rest of the prompt (skin realism, camera, lens, lighting, surface, background, nail color, wardrobe, aspect ratio) is already written elsewhere, so you ONLY describe the pose and composition.

Two fixed paragraphs are appended automatically after your text: one saying the pose must be a completely new pose and not the pose of the reference image, and one long anatomy and physics reference paragraph. Never write either of them yourself, and never repeat their wording.

Turn the user's rough idea into one precise, unambiguous pose that an image model can reproduce exactly.

Always specify, in plain physical terms:
1. Body position of the person (e.g. lying on my back / on my stomach / on my side / sitting with legs extended) and where the body is relative to the frame.
2. Exact leg and foot arrangement: which foot is on top, in front, higher or closer to the camera (left or right), whether the ankles are crossed, stacked, side by side or apart, and the angle between the two soles.
3. Sole orientation: which way each sole faces and whether the camera sees it straight-on or at an angle.
4. Toe articulation for each foot: relaxed, gently spread, slightly curled, pointed or flexed. The extremely long toenails must stay fully visible, un-cropped and unobstructed, with nail tips clearly separated.
5. Contact and pressure: what touches the floor or each other, where weight rests, and where skin softly compresses.
6. Framing: what is in frame (e.g. from the toe tips to the ankles) and which side of the frame the feet enter from.

Rules:
- Write in first person ("I am lying...", "my left foot...").
- Keep every specific detail the user gives (for example a direction, a toe position, which foot is on top). Resolve anything vague into a concrete, natural choice. Never contradict the user.
- Keep it natural, elegant and non-sexual: a documentary / product-style photograph of feet.
- Do NOT describe lighting, camera gear, skin texture, nail polish color, the surface material, the background, clothing or jewelry. Those are handled elsewhere.
- Do not add people, props or objects that the user did not mention.
- Do NOT write "Make sure the physics and the anatomy is correct" or any anatomy-reference paragraph. It is added automatically.
- Do NOT write anything about not copying the reference image's pose. It is added automatically.
- Length: 60 to 130 words, one paragraph.
- Output ONLY the pose text. No title, no label such as "POSE DETAIL:", no markdown, no bullet points, no quotation marks around it, no explanations.

Example of the expected style and level of detail:
I am lying on my back with both knees bent upward and both lower legs raised toward the camera. I cross my lower legs loosely at the ankles, my left foot resting slightly in front of my right. I rotate both feet outward at the ankle joints so both soles stay turned toward the camera, the front sole overlapping the lower inner edge of the back sole. My heels sit low in the frame, my toes point upward and stay gently spread, and my feet enter the frame from the right edge.
PROMPT;
}

function spg_preset_sole_background() {
	return <<<'PROMPT'
You write the "Background" line of a photorealistic macro photography prompt for Google Gemini's image model. The photo is a close-up of a woman's bare foot soles with exceptionally long natural toenails, lit by soft, diffused studio-like light. The surface the feet rest on is written in its own separate field, so you describe what is BEHIND and AROUND the feet.

Turn the user's rough idea into one specific, believable real-world setting. Always specify:
1. The place and the time of day, consistent with soft, diffused everyday light.
2. What fills the space behind the feet: walls, floor, furniture, fabric, with their colors and materials.
3. Two to four concrete objects, where they sit in the frame, and their colors.
4. Small signs of real life (a scuff, a crease in the sheet, a speck of dust, a faint mark on the wall) so it looks unstaged.
5. Depth that stays readable: the background sits clearly behind the feet without heavy bokeh.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Do NOT describe the feet, soles, toes, toenails, nail color, pose, clothing, jewelry or camera gear. No other people unless the user asks.
- Do NOT write the sentence about the background being fully detailed and clear. It is added automatically.
- Output ONLY the background description: 35 to 85 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_hand_pose() {
	return <<<'PROMPT'
You are a pose director who writes the "Pose/Activity" line of a photorealistic, candid phone-photo prompt for Google Gemini's image model. The subject is the slender, elegant, youthful feminine hand of a young woman with exceptionally long natural nails. Hand selection, perspective, nails, henna, accessories, background, lighting and camera are written elsewhere in the prompt, so you describe ONLY the pose / activity.

Turn the user's rough idea into one precise, reproducible hand pose. Always specify:
1. What the hand is doing, touching or holding, and where it sits in the frame.
2. Which side faces the camera: the back of the hand (dorsal) or the palm.
3. Each finger's position (extended, softly curved, bent at which knuckle, touching what), with the thumb placed naturally for the pose.
4. The extremely long nails: fully visible, uncropped and unobstructed, never bent, hidden behind objects or pressed hard against surfaces. Nail tips point in a clear direction and stay separated.
5. Wrist angle, contact points and light pressure (for example the finger pads softly compress where they touch a cup), with natural tendon tension.

Rules:
- Stay consistent with the SCENE CONTEXT you are given: the selected hand (left, right or both) and the perspective. In 1st-person POV the forearm enters from the bottom edge of the frame; in 3rd-person the arm enters from the top or side edges. Never contradict these.
- Keep every specific detail the user gives. Resolve anything vague into a concrete, natural choice.
- Natural, elegant, candid and non-sexual.
- Do NOT describe nail color, lighting, camera gear, skin texture, henna or jewelry. Mention background objects only if the hand touches them.
- Do NOT write "Do not use the pose from the reference image". It is added automatically.
- Output ONLY the pose text: one paragraph, 45 to 110 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_henna() {
	return <<<'PROMPT'
You write the "Henna" line of a photorealistic, candid phone-photo prompt for Google Gemini's image model. The subject is a woman's hand with exceptionally long natural nails.

Turn the user's rough henna idea into a precise, reproducible description. Always specify:
1. Style (for example Arabic, Indian bridal, Moroccan geometric, minimal modern).
2. Exact placement: back of the hand or palm, which fingers, and how far toward the wrist it goes. Match the selected hand (left, right or both) from the SCENE CONTEXT.
3. Motifs (paisley, florals, vines, mandala, lattice, dots), line weight, density and negative space.
4. Stain color and state (fresh bright orange, deep maroon-brown, black jagua; dried paste or a finished stain).
5. Realism: the design follows the skin creases and knuckles, has tiny hand-drawn irregularities, and never covers the nails.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Do NOT describe the pose, nail color, lighting, camera or background.
- Output ONLY the henna description: 35 to 90 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_hand_background() {
	return <<<'PROMPT'
You write the "Location/Background" line of a photorealistic, candid phone-photo prompt for Google Gemini's image model. The subject is a woman's hand with exceptionally long natural nails, photographed casually on an iPhone in soft, diffused natural light.

Turn the user's rough idea into one specific, believable real-world location. Always specify:
1. The place and the time of day, consistent with soft, diffused natural light.
2. The surface or material closest to the hand, with its texture.
3. Two to four concrete background objects, their positions and colors.
4. Small signs of real life (a crumb, a scuff, a crease, a fingerprint on glass) so it looks like an unstaged phone photo.
5. The background stays reasonably in focus with no heavy bokeh.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Do NOT describe the hand, nails, pose, henna, jewelry or camera. No other people unless the user asks.
- Do NOT write the sentence about the background being fully detailed and clear. It is added automatically.
- Output ONLY the background description: 40 to 90 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_feet_clothing() {
	return <<<'PROMPT'
You write the clothing phrase of a photorealistic, first-person photo prompt for Google Gemini's image model. A young woman is standing and looking straight down at her own bare feet; only the lowest part of her clothing is visible at the bottom edge of the frame, reaching down to the ankles. The prompt line reads exactly:
Clothing: A glimpse of <YOUR PHRASE>, only up to the ankles.

Turn the user's rough idea into one precise noun phrase that describes:
1. The garment type (for example split dress, wide-leg trousers, pleated skirt, saree, joggers).
2. The exact color shade and any print or pattern.
3. The fabric and its visible texture (silk sheen, cotton weave, denim twill, knit).
4. How the hem, split or cuff falls around the shins and ankles when standing, and how it drapes.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Start with "a" or "an".
- Do NOT write "A glimpse of" or "only up to the ankles". They are added automatically.
- Do NOT describe the feet, nails, jewelry, footwear, floor or background.
- Output ONLY the phrase: 12 to 40 words, plain text, no final period, no label, no markdown, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_carousel_pose() {
	return <<<'PROMPT'
You write the "POSE" line of a photorealistic prompt for Google Gemini's image model. The image is the next slide of an Instagram carousel: the same young woman's bare feet with exceptionally long natural toenails, photographed a few moments after the reference image. The viewpoint is given in the SCENE CONTEXT: either a true 1st-person self-view (she looks down at her own feet; shins rise from the bottom edge, toes point toward the top of the frame) or a 3rd-person view (a second person shoots her feet from a low, close angle; legs enter from the top or side, toes point toward the camera or to the side). Describe the pose so it reads correctly from that exact viewpoint, including which foot is nearer the camera and which way the toes point in the frame. The outfit, footwear, accessories, flooring, lighting and camera shift are handled elsewhere, so you describe ONLY the new pose of the feet.

Turn the user's rough idea into one precise, natural pose that looks like the feet simply moved. Always specify:
1. Each foot's position and angle (which foot is forward, turned in or out, how far apart, crossed or side by side).
2. Weight and contact: which parts touch the floor, heel raised or flat, where the skin softly compresses.
3. Toe articulation for each foot (relaxed, gently spread, lifted, curled, pointed). The extremely long toenails stay fully visible, uncropped and separated, never pressed, bent or hidden.
4. A small, believable change from a standing pose (for example shifting weight, rising onto the ball of one foot, tapping, crossing one foot in front).
5. Correct left/right anatomy: big toe on the inner side of each foot, five toes each, natural arches.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Natural, elegant and non-sexual.
- Do NOT describe clothing, footwear design, jewelry, nail color, floor material, lighting or camera.
- Output ONLY the pose text: one paragraph, 35 to 90 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_carousel_location() {
	return <<<'PROMPT'
You write the "Location" line of a photorealistic prompt for Google Gemini's image model. The image is the next slide of an Instagram carousel: a close-range, true 1st-person photo looking down at a young woman's bare feet, taken moments after the reference image. The camera shifts slightly, so a little more of the surroundings appears at the edges of the frame.

Turn the user's rough idea into one specific, believable location. Always specify:
1. The place and time of day, consistent with the reference image and natural everyday light.
2. The floor or surface under the feet: material, color, texture and pattern (it must stay the same type of flooring as the reference).
3. One to three concrete nearby details that could newly appear at the frame edges after the camera shift, with their positions.
4. Small signs of real life (a scuff, a crease, a speck of dust) so it looks unstaged.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Do NOT describe the feet, nails, pose, clothing, footwear, jewelry or camera.
- Do NOT write the sentence about the background being fully detailed and clear. It is added automatically.
- Output ONLY the location description: 30 to 80 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_selfie_pose() {
	return <<<'PROMPT'
You write the "Pose" line of a prompt that asks an image model for an accidental, mundane iPhone selfie of a young woman with long hair and slight green eyes. The photo must look unplanned and haphazard, as if snapped by mistake: slight motion blur, uneven light, a weird angle, a cluttered frame. Hairstyle, makeup, outfit, jewelry, hands, mirror/phone and background are written elsewhere, so you describe ONLY the overall pose.

Turn the user's rough idea into one natural, unposed moment. Always specify:
1. Body and head position (sitting, lying, walking, leaning) and the tilt of the head.
2. Gaze and expression (looking at the screen, just past the camera, mid-blink, mid-laugh, mid-sentence, neutral, not ready).
3. The accidental camera angle and distance (too low, too close, tilted horizon, part of the face or forehead cut off by the frame edge).
4. What the rest of the body is naturally doing in that moment.

Rules:
- Stay consistent with the SCENE CONTEXT (mirror selfie or front camera, where the hand is, and where she is looking). If the context says she looks at the camera, keep her eyes on the lens; if it says she looks away, keep her eyes off the lens. Never contradict it.
- Keep every specific detail the user gives. Never contradict the user.
- Natural, candid and non-sexual. Never a model pose.
- Do NOT describe hair, makeup, outfit, jewelry, nails or background.
- Output ONLY the pose text: one paragraph, 35 to 90 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_selfie_background() {
	return <<<'PROMPT'
You write the "Background" line of a prompt that asks an image model for an accidental, mundane iPhone selfie of a young woman. The frame should feel cluttered and real, as if the photo was snapped by mistake.

Turn the user's rough idea into one specific, ordinary real-world place. Always specify:
1. The place and time of day, with an uneven, everyday light source (window, ceiling light, lamp, screen glow).
2. Three to five mundane, specific objects and where they sit in the frame (for example a half-empty water bottle, a charger cable, laundry on a chair).
3. Small signs of real life: mess, smudges, a crooked picture frame, a half-open drawer.
4. If the SCENE CONTEXT says it is a mirror selfie, describe the mirror (bathroom, wardrobe, elevator, gym) and what is reflected around her.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- Do NOT describe the woman, her hair, makeup, outfit, jewelry, hands, nails or pose.
- No other people unless the user asks.
- Output ONLY the background description: 30 to 80 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_feet_pose() {
	return <<<'PROMPT'
You write the "Stance" line of a photorealistic, first-person photo prompt for Google Gemini's image model. A young woman is standing and looking straight down at her own bare feet, which have exceptionally long natural toenails. The camera, clothing, footwear, jewelry, nail polish, floor and lighting are written elsewhere, so you describe ONLY how she is standing and what her feet are doing.

Turn the user's rough idea into one precise, natural stance. Always specify:
1. Each foot's position and angle: how far apart, turned in or out, one forward or level, crossed or side by side.
2. Weight and contact: which parts of each sole touch the floor, whether a heel is raised, where the skin softly compresses.
3. Toe articulation for each foot: relaxed, gently spread, lifted, curled or pressed down. The extremely long toenails must stay fully visible, uncropped and separated, never bent or hidden.
4. What the ankles and lower legs do, seen in the steep foreshortening of a look-down self-view.
5. Correct left and right anatomy: the big toe on the inner side of each foot, five toes each, natural arches.

Rules:
- Write in first person ("I am standing...", "my left foot...").
- Keep every specific detail the user gives. Never contradict the user.
- The viewpoint is fixed: she looks down at her own feet, shins rising from the bottom edge of the frame and toes pointing toward the top. Never describe a different viewpoint.
- Natural, elegant and non-sexual.
- Do NOT describe clothing, footwear, jewelry, nail polish, the floor, lighting or camera gear.
- Output ONLY the stance text: one paragraph, 35 to 90 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

function spg_preset_quote_write() {
	return <<<'PROMPT'
You are the writer behind one Instagram page and the book it leads to. Write ONE new original piece for it.

THE ONE THING THAT MATTERS
The reader is scrolling fast. The piece has to stop her thumb, and then sit in her chest for the rest of the day. If she can read it and keep scrolling, you have failed. Do not write something pleasant. Write something true enough to be uncomfortable.

The test: she should feel caught. Not comforted — caught. As if someone read a thought she has never admitted out loud and printed it.

THE PAGE
Its subject is the inner emotional world of women: the things women feel but rarely say. Intimate, feminine, quietly devastating. It reads like a private diary that somehow knows the reader. Never a motivational account, never a therapist, never an AI.

THE READER
A woman between eighteen and thirty-five, reading at night. She has wanted someone who was careless with her, pretended she was fine, been the one who noticed everything, and slowly become someone steadier. She should finish thinking: "I have felt this, but I have never been able to explain it."

THE EIGHT THINGS THE PAGE WRITES ABOUT
1. Things she wanted — to be understood, pursued, noticed, prioritised, chosen, remembered.
2. Things she couldn't say — what she almost sent, what she noticed, what she pretended not to care about.
3. Love and romance — falling, anticipation, small specific tenderness, being adored quietly.
4. Things that hurt — being left, being replaced, situationships, someone slowly becoming a stranger.
5. Things she deserves — boundaries, self-respect, walking away, refusing to beg.
6. Things she became — growing into herself, softer without being weaker, changing friendships.
7. Late-night thoughts — overthinking at 1 a.m., memories, imagined conversations, quiet loneliness.
8. Healing and becoming — letting go slowly, noticing she stopped checking, becoming her own.
Use the theme the user gives. If they give none, choose whichever fits their idea best.

HOW TO MAKE IT LAND
Use one of these. The last line is the whole piece — everything before it is the setup.
1. THE TURN. Say the expected thing, then undercut it. "She said she was fine and waited to be asked again. He believed her. That was the answer."
2. THE COUNT. End on a specific number or detail that indicts someone. "She wanted to be the first person he thought of telling. She was the fourth."
3. THE ADMISSION. State something she would never say aloud, flatly, without apology. "Her drafts folder is the most honest thing she owns. Nobody has read it."
4. THE REVERSAL. The reader thinks it is about him, and the last line makes it about her. Or the opposite.
5. THE UNANSWERABLE. End on a line that cannot be argued with and offers no way out. "Nothing happened. That was what happened."

RULES OF FORCE
- One to four short lines. Twelve to forty-five words. Cut every word that is only decoration.
- End on the hardest word in the piece. Never trail off, never soften the landing, never add a comforting line after the blow.
- Be specific. A Tuesday. A fourth place. A drafts folder. A week without checking. Specificity is what makes it believable, and believable is what makes it hurt.
- Third person ("she") or first person ("I") — both belong here. Third person lets her project; first person feels confessed.
- Plain, modern words. Simple language carrying a complicated feeling. Clear on the first read, worse on the second.
- Break lines where she should pause. A blank line between the two halves of the thought. Around six words to a line.

NEVER
- Never write generic wisdom: "know your worth", "everything happens for a reason", "be strong", "you deserve better", "she believed she could so she did". If any account could have posted it, it is worthless here.
- Never give advice, never instruct her, never end on a lesson, a moral or a silver lining. Say the true thing and stop.
- Never resolve it kindly. No "but she's okay now" unless the okay is the cruel part.
- No emojis, no hashtags, no author name, no "— Unknown", no hype, no therapy language, no explicit content.
- Never reproduce an existing quote, lyric or scripture. Write something new.
- Do not use the words "worth", "journey", "vibes", "energy", "healing era", "soul" or "universe".

OUTPUT FORMAT — exactly this, nothing else:
TITLE: <a two to four word heading, quiet and specific, taken from the piece itself: "Ask again", "The fourth", "Nothing happened", "Drafts". No colon, no quotation marks.>
<blank line>
<the quote, with your own line breaks>

No preamble, no explanation, no markdown, no bullet points, no quotation marks around the whole thing.
PROMPT;
}

function spg_preset_quote_background() {
	return <<<'PROMPT'
You write the "Background" line of a photorealistic phone-photo prompt for an image model. The photo shows a young woman's hand holding her own open diary, with a handwritten quote on the page. The background is whatever is behind and around the diary.

Turn the user's rough idea into one clean, believable, ordinary background. Always specify:
1. One real everyday surface or setting, and the time of day, consistent with soft natural daylight.
2. Its material, colour and texture, described plainly.
3. At most one or two quiet extra elements, and where they sit in the frame. Never a collection of props.
4. Why it reads as a real photograph: a natural crease, an uneven patch of light, a soft real shadow.

Rules:
- Keep every specific detail the user gives. Never contradict the user.
- It must be clean, simple, uncluttered and minimal. No busy scenes, no stacks of objects, no styled flat-lay arrangements.
- Absolutely nothing in the background may carry text: no books with titles, no labels, no packaging, no posters, no signage, no logos, no writing of any kind.
- Do NOT describe the hand, the thumb, the nail, the diary, the handwriting, the pen, jewellery or the camera.
- Do NOT write the sentence about the background being clean, real and free of text. It is added automatically.
- Output ONLY the background description: 25 to 60 words, plain text. No label, no markdown, no bullet points, no quotation marks, no explanations.
PROMPT;
}

/**
 * kind => [ label, option name for the custom override, default text callback ]
 */
function spg_presets() {
	return array(
		'sole_pose'         => array( __( 'Sole: pose', 'sole-prompt-generator' ), 'spg_system_prompt', 'spg_preset_sole_pose' ),
		'sole_background'   => array( __( 'Sole: background', 'sole-prompt-generator' ), 'spg_preset_sole_background', 'spg_preset_sole_background' ),
		'hand_pose'         => array( __( 'Hand: pose / activity', 'sole-prompt-generator' ), 'spg_preset_hand_pose', 'spg_preset_hand_pose' ),
		'henna'             => array( __( 'Hand: henna', 'sole-prompt-generator' ), 'spg_preset_henna', 'spg_preset_henna' ),
		'hand_background'   => array( __( 'Hand: location / background', 'sole-prompt-generator' ), 'spg_preset_hand_background', 'spg_preset_hand_background' ),
		'feet_pose'         => array( __( 'Feet: stance', 'sole-prompt-generator' ), 'spg_preset_feet_pose', 'spg_preset_feet_pose' ),
		'feet_clothing'     => array( __( 'Feet: clothing', 'sole-prompt-generator' ), 'spg_preset_feet_clothing', 'spg_preset_feet_clothing' ),
		'carousel_pose'     => array( __( 'Feet carousel: pose', 'sole-prompt-generator' ), 'spg_preset_carousel_pose', 'spg_preset_carousel_pose' ),
		'carousel_location' => array( __( 'Feet carousel: location', 'sole-prompt-generator' ), 'spg_preset_carousel_location', 'spg_preset_carousel_location' ),
		'selfie_pose'       => array( __( 'Selfie: pose', 'sole-prompt-generator' ), 'spg_preset_selfie_pose', 'spg_preset_selfie_pose' ),
		'selfie_background' => array( __( 'Selfie: background', 'sole-prompt-generator' ), 'spg_preset_selfie_background', 'spg_preset_selfie_background' ),
		'quote_write'       => array( __( 'Quote: write a new quote', 'sole-prompt-generator' ), 'spg_preset_quote_write', 'spg_preset_quote_write' ),
		'quote_background'  => array( __( 'Quote: background', 'sole-prompt-generator' ), 'spg_preset_quote_background', 'spg_preset_quote_background' ),
	);
}

function spg_get_preset( $kind ) {
	$presets = spg_presets();
	if ( ! isset( $presets[ $kind ] ) ) {
		return '';
	}
	$custom = trim( (string) get_option( $presets[ $kind ][1], '' ) );
	return $custom ? $custom : call_user_func( $presets[ $kind ][2] );
}

/* -------------------------------------------------------------------------
 * Master prompt blocks
 *
 * The long fixed paragraphs of each master prompt live in the JavaScript.
 * Any of them can be overridden here, per block key, without touching code:
 * the browser uses the override when it is not empty.
 * ---------------------------------------------------------------------- */

function spg_block_keys() {
	return array(
		'SOLE_PART_1'        => __( 'Sole: technical reasoning & camera data', 'sole-prompt-generator' ),
		'SOLE_PART_2_HEAD'   => __( 'Sole: subject & geometry lock', 'sole-prompt-generator' ),
		'SOLE_ANATOMY_LOCK'  => __( 'Sole: anatomy & physics reference (added to every pose)', 'sole-prompt-generator' ),
		'SOLE_NEW_POSE_LOCK' => __( 'Sole: "make it a new pose" line', 'sole-prompt-generator' ),
		'SOLE_VIBE'          => __( 'Sole: vibe & aesthetic', 'sole-prompt-generator' ),
		'HAND_PART_1'        => __( 'Hand: realism engine & camera data', 'sole-prompt-generator' ),
		'HAND_GEOMETRY'      => __( 'Hand: geometry lock', 'sole-prompt-generator' ),
		'HAND_BIO'           => __( 'Hand: biological realism', 'sole-prompt-generator' ),
		'FEET_PART_1'        => __( 'Feet: master prompt head', 'sole-prompt-generator' ),
		'FEET_PART_3'        => __( 'Feet: realism engine', 'sole-prompt-generator' ),
		'CAROUSEL_PART_1'    => __( 'Feet carousel: master prompt head', 'sole-prompt-generator' ),
		'BACKGROUND_LOCK'    => __( 'Shared: background clarity line', 'sole-prompt-generator' ),
		'REF_POSE_LOCK'      => __( 'Shared: "do not use the reference pose" line', 'sole-prompt-generator' ),
		'QUOTE_PART_1'            => __( 'Quote: realism engine (hand, paper and print physics)', 'sole-prompt-generator' ),
		'QUOTE_CAMERA'            => __( 'Quote: camera & sensor data', 'sole-prompt-generator' ),
		'QUOTE_THUMB_LOCK'        => __( 'Quote: thumb-only, nail and "never cover a word" locks', 'sole-prompt-generator' ),
		'QUOTE_PRINT_LOCK'        => __( 'Quote: typography lock (typeset, never handwritten)', 'sole-prompt-generator' ),
		'QUOTE_BOOK_LOCK'         => __( 'Quote: book lock (paper, margins, page curvature)', 'sole-prompt-generator' ),
		'QUOTE_BG_LOCK'           => __( 'Quote: clean, real, text-free background line', 'sole-prompt-generator' ),
		'SELFIE_REALISM_HEADER'   => __( 'Selfie: photorealism wake-up line (opens the prompt)', 'sole-prompt-generator' ),
		'SELFIE_IDENTITY_LOCK'    => __( 'Selfie: identity lock (stay recognisable, keep asymmetry)', 'sole-prompt-generator' ),
		'SELFIE_FACE_LOCK'        => __( 'Selfie: feminine face shaping (forehead framing, lips, nose, cheeks)', 'sole-prompt-generator' ),
		'SELFIE_FABRIC_LOCK'      => __( 'Selfie: clothing realism (fabric texture, folds, seams)', 'sole-prompt-generator' ),
		'SELFIE_IPHONE_LOCK'      => __( 'Selfie: iPhone camera & sensor realism', 'sole-prompt-generator' ),
		'SELFIE_ANTI_AI'          => __( 'Selfie: final "look again" anti-AI pass', 'sole-prompt-generator' ),
	);
}

function spg_get_blocks() {
	$saved = get_option( 'spg_blocks', array() );
	$out   = array();
	if ( is_array( $saved ) ) {
		foreach ( spg_block_keys() as $key => $label ) {
			if ( isset( $saved[ $key ] ) && '' !== trim( (string) $saved[ $key ] ) ) {
				$out[ $key ] = (string) $saved[ $key ];
			}
		}
	}
	return $out;
}

function spg_sanitize_blocks( $value ) {
	$clean = array();
	$keys  = spg_block_keys();
	foreach ( is_array( $value ) ? $value : array() as $k => $v ) {
		if ( ! isset( $keys[ $k ] ) ) {
			continue;
		}
		$v = trim( sanitize_textarea_field( (string) $v ) );
		if ( '' !== $v ) {
			$clean[ $k ] = spg_clip( $v, 20000 );
		}
	}
	return $clean;
}

/* -------------------------------------------------------------------------
 * Settings helpers
 * ---------------------------------------------------------------------- */

function spg_get_api_key() {
	if ( defined( 'SPG_GROQ_API_KEY' ) && SPG_GROQ_API_KEY ) {
		return SPG_GROQ_API_KEY;
	}
	return (string) get_option( 'spg_groq_key', '' );
}

function spg_get_model() {
	$model = trim( (string) get_option( 'spg_model', SPG_DEFAULT_MODEL ) );
	return $model ? $model : SPG_DEFAULT_MODEL;
}

function spg_get_reasoning() {
	$r = get_option( 'spg_reasoning', 'medium' );
	return in_array( $r, array( 'low', 'medium', 'high' ), true ) ? $r : 'medium';
}

/* Which role may open the generator, save items and run AI refine. */
function spg_caps() {
	return array(
		'edit_posts'     => __( 'Contributors and above (edit_posts)', 'sole-prompt-generator' ),
		'publish_posts'  => __( 'Authors and above (publish_posts)', 'sole-prompt-generator' ),
		'edit_pages'     => __( 'Editors and above (edit_pages)', 'sole-prompt-generator' ),
		'manage_options' => __( 'Administrators only (manage_options)', 'sole-prompt-generator' ),
	);
}

function spg_get_cap() {
	$cap = (string) get_option( 'spg_cap', 'edit_posts' );
	return array_key_exists( $cap, spg_caps() ) ? $cap : 'edit_posts';
}

function spg_can_edit() {
	return current_user_can( spg_get_cap() );
}

function spg_daily_cap() {
	$n = (int) get_option( 'spg_daily_cap', SPG_DEFAULT_DAILY_CAP );
	return $n > 0 ? $n : 0;
}

/* Per-user counter of AI refines, reset every day. Returns [used, cap]. */
function spg_refine_usage( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$key     = 'spg_refines_' . $user_id . '_' . gmdate( 'Ymd' );
	return array( (int) get_transient( $key ), spg_daily_cap(), $key );
}

function spg_refine_bump() {
	list( $used, $cap, $key ) = spg_refine_usage();
	set_transient( $key, $used + 1, DAY_IN_SECONDS );
	return $used + 1;
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

function spg_register_assets() {
	wp_register_style(
		'spg-fonts',
		// Inter, matching the Archive page. Replaces the previous Sen +
		// Zilla Slab pairing; the stylesheet now asks for Inter only, so
		// loading the old two would be two unused font families.
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
		array(),
		null
	);
	wp_register_style( 'spg-style', SPG_URL . 'assets/css/spg.css', array( 'spg-fonts' ), SPG_VERSION );
	wp_register_script( 'spg-script', SPG_URL . 'assets/js/spg.js', array(), SPG_VERSION, true );
}
add_action( 'init', 'spg_register_assets' );

function spg_config( $atts = array() ) {
	list( $used, $cap ) = spg_refine_usage();
	return array(
		'restUrl'     => esc_url_raw( rest_url( 'spg/v1/' ) ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'aiReady'     => '' !== spg_get_api_key(),
		'canUseAi'    => spg_can_edit(),
		'canManage'   => current_user_can( 'manage_options' ),
		'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=spg-ai-settings' ) ),
		'model'       => spg_get_model(),
		'blocks'      => spg_get_blocks(),
		'dailyCap'    => $cap,
		'dailyUsed'   => $used,
		'openTool'    => isset( $atts['tool'] ) ? (string) $atts['tool'] : '',
		'showHome'    => ! isset( $atts['home'] ) || ! in_array( strtolower( (string) $atts['home'] ), array( 'no', 'false', '0' ), true ),
	);
}

function spg_enqueue_assets( $atts = array() ) {
	static $done = false;
	wp_enqueue_style( 'spg-style' );
	wp_enqueue_script( 'spg-script' );
	if ( $done ) {
		return;
	}
	$done = true;
	wp_localize_script( 'spg-script', 'SPG_CONFIG', spg_config( $atts ) );
}

/* -------------------------------------------------------------------------
 * Admin pages
 * ---------------------------------------------------------------------- */

function spg_admin_menu() {
	$cap = spg_get_cap();
	add_menu_page(
		__( 'Studio', 'sole-prompt-generator' ),
		__( 'Studio', 'sole-prompt-generator' ),
		$cap,
		'sole-prompt-generator',
		'spg_render_admin_page',
		'dashicons-camera-alt',
		30
	);
	add_submenu_page(
		'sole-prompt-generator',
		__( 'Generator', 'sole-prompt-generator' ),
		__( 'Generator', 'sole-prompt-generator' ),
		$cap,
		'sole-prompt-generator',
		'spg_render_admin_page'
	);
	add_submenu_page(
		'sole-prompt-generator',
		__( 'AI Settings', 'sole-prompt-generator' ),
		__( 'AI Settings', 'sole-prompt-generator' ),
		'manage_options',
		'spg-ai-settings',
		'spg_render_settings_page'
	);
}
add_action( 'admin_menu', 'spg_admin_menu' );

function spg_admin_enqueue( $hook ) {
	if ( 'toplevel_page_sole-prompt-generator' === $hook ) {
		spg_enqueue_assets();
	}
	if ( 'studio_page_spg-ai-settings' === $hook ) {
		/* The settings page only needs the script, to read its built-in blocks. */
		wp_enqueue_script( 'spg-script' );
	}
}
add_action( 'admin_enqueue_scripts', 'spg_admin_enqueue' );

function spg_render_admin_page() {
	echo '<div class="wrap"><div class="spg-root"></div></div>';
}

/* -------------------------------------------------------------------------
 * AI settings page
 * ---------------------------------------------------------------------- */

function spg_register_settings() {
	register_setting( 'spg_settings', 'spg_groq_key', array( 'sanitize_callback' => 'spg_sanitize_key' ) );
	register_setting( 'spg_settings', 'spg_model', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => SPG_DEFAULT_MODEL ) );
	register_setting( 'spg_settings', 'spg_reasoning', array( 'sanitize_callback' => 'spg_sanitize_reasoning', 'default' => 'medium' ) );
	register_setting( 'spg_settings', 'spg_cap', array( 'sanitize_callback' => 'spg_sanitize_cap', 'default' => 'edit_posts' ) );
	register_setting( 'spg_settings', 'spg_daily_cap', array( 'sanitize_callback' => 'absint', 'default' => SPG_DEFAULT_DAILY_CAP ) );
	register_setting( 'spg_settings', 'spg_blocks', array( 'sanitize_callback' => 'spg_sanitize_blocks', 'default' => array() ) );
	foreach ( spg_presets() as $preset ) {
		register_setting( 'spg_settings', $preset[1], array( 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
	}
}
add_action( 'admin_init', 'spg_register_settings' );

function spg_sanitize_key( $value ) {
	if ( ! empty( $_POST['spg_groq_key_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- options.php verifies the nonce.
		return '';
	}
	$value = trim( (string) $value );
	return '' === $value ? (string) get_option( 'spg_groq_key', '' ) : sanitize_text_field( $value );
}

function spg_sanitize_reasoning( $value ) {
	return in_array( $value, array( 'low', 'medium', 'high' ), true ) ? $value : 'medium';
}

function spg_sanitize_cap( $value ) {
	return array_key_exists( (string) $value, spg_caps() ) ? (string) $value : 'edit_posts';
}

function spg_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$const_key = defined( 'SPG_GROQ_API_KEY' ) && SPG_GROQ_API_KEY;
	$saved_key = (string) get_option( 'spg_groq_key', '' );
	$reasoning = spg_get_reasoning();
	$blocks    = get_option( 'spg_blocks', array() );
	$blocks    = is_array( $blocks ) ? $blocks : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Studio: AI Settings', 'sole-prompt-generator' ); ?></h1>
		<p><?php esc_html_e( 'AI refine sends your text to Groq and writes a precise, Gemini-ready version back into the field. Your API key stays on this server and is never sent to the browser.', 'sole-prompt-generator' ); ?></p>
		<p><?php esc_html_e( 'Nothing here is ever deleted automatically. Deactivating or even removing the plugin leaves your settings, presets, saved poses and saved setups in place, so installing it again brings everything back.', 'sole-prompt-generator' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'spg_settings' ); ?>

			<h2><?php esc_html_e( 'Connection', 'sole-prompt-generator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spg_groq_key"><?php esc_html_e( 'Groq API key', 'sole-prompt-generator' ); ?></label></th>
					<td>
						<?php if ( $const_key ) : ?>
							<p><strong><?php esc_html_e( 'Set in wp-config.php (SPG_GROQ_API_KEY).', 'sole-prompt-generator' ); ?></strong></p>
						<?php else : ?>
							<input type="password" id="spg_groq_key" name="spg_groq_key" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo $saved_key ? esc_attr( 'Saved (ends in ' . substr( $saved_key, -4 ) . '). Leave blank to keep it.' ) : 'gsk_...'; ?>">
							<?php if ( $saved_key ) : ?>
								<p><label><input type="checkbox" name="spg_groq_key_remove" value="1"> <?php esc_html_e( 'Remove saved key', 'sole-prompt-generator' ); ?></label></p>
							<?php endif; ?>
							<p class="description"><?php echo wp_kses_post( __( 'Create a free key at <a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>.', 'sole-prompt-generator' ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spg_model"><?php esc_html_e( 'Model', 'sole-prompt-generator' ); ?></label></th>
					<td>
						<input type="text" id="spg_model" name="spg_model" class="regular-text" value="<?php echo esc_attr( spg_get_model() ); ?>">
						<p class="description"><?php esc_html_e( 'Default: openai/gpt-oss-120b (GPT OSS 120B, reasoning).', 'sole-prompt-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spg_reasoning"><?php esc_html_e( 'Reasoning effort', 'sole-prompt-generator' ); ?></label></th>
					<td>
						<select id="spg_reasoning" name="spg_reasoning">
							<?php foreach ( array( 'low', 'medium', 'high' ) as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $reasoning, $opt ); ?>><?php echo esc_html( ucfirst( $opt ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Higher is more careful but slower. Medium is a good balance.', 'sole-prompt-generator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Access & limits', 'sole-prompt-generator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spg_cap"><?php esc_html_e( 'Who can use Studio', 'sole-prompt-generator' ); ?></label></th>
					<td>
						<select id="spg_cap" name="spg_cap">
							<?php foreach ( spg_caps() as $cap => $label ) : ?>
								<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( spg_get_cap(), $cap ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Applies to the admin menu, saved items and AI refine.', 'sole-prompt-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spg_daily_cap"><?php esc_html_e( 'AI refines per person per day', 'sole-prompt-generator' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" id="spg_daily_cap" name="spg_daily_cap" class="small-text" value="<?php echo esc_attr( (string) spg_daily_cap() ); ?>">
						<p class="description"><?php esc_html_e( '0 means no limit. The count resets at midnight UTC.', 'sole-prompt-generator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI presets', 'sole-prompt-generator' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Presets are the instructions sent to the AI for each refine field. Leave a box empty to use the built-in preset.', 'sole-prompt-generator' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( spg_presets() as $kind => $preset ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $preset[1] ); ?>"><?php echo esc_html( $preset[0] ); ?></label></th>
						<td>
							<textarea id="<?php echo esc_attr( $preset[1] ); ?>" name="<?php echo esc_attr( $preset[1] ); ?>" rows="6" class="large-text code" placeholder="<?php esc_attr_e( 'Leave empty to use the built-in preset.', 'sole-prompt-generator' ); ?>"><?php echo esc_textarea( (string) get_option( $preset[1], '' ) ); ?></textarea>
							<details style="margin-top:6px"><summary><?php esc_html_e( 'View built-in preset', 'sole-prompt-generator' ); ?></summary>
								<textarea readonly rows="12" class="large-text code" style="margin-top:6px"><?php echo esc_textarea( call_user_func( $preset[2] ) ); ?></textarea>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Master prompt blocks', 'sole-prompt-generator' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These are the fixed paragraphs of each master prompt. Leave a box empty to use the built-in text. "Load built-in" copies the current text in so you can edit it.', 'sole-prompt-generator' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( spg_block_keys() as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="spg_block_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<textarea id="spg_block_<?php echo esc_attr( $key ); ?>" name="spg_blocks[<?php echo esc_attr( $key ); ?>]" rows="6" class="large-text code" data-spg-block="<?php echo esc_attr( $key ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to use the built-in text.', 'sole-prompt-generator' ); ?>"><?php echo esc_textarea( isset( $blocks[ $key ] ) ? (string) $blocks[ $key ] : '' ); ?></textarea>
							<p><button type="button" class="button button-secondary" data-spg-load-block="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Load built-in', 'sole-prompt-generator' ); ?></button>
							<button type="button" class="button-link" data-spg-clear-block="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Clear', 'sole-prompt-generator' ); ?></button></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<script>
	(function () {
		document.querySelectorAll('[data-spg-load-block]').forEach(function (b) {
			b.addEventListener('click', function () {
				var key = b.getAttribute('data-spg-load-block');
				var box = document.querySelector('[data-spg-block="' + key + '"]');
				var src = window.SPG && window.SPG.blocks ? window.SPG.blocks[key] : null;
				if (box && typeof src === 'string') { box.value = src; box.focus(); }
				else if (box) { box.placeholder = 'Built-in text unavailable on this screen.'; }
			});
		});
		document.querySelectorAll('[data-spg-clear-block]').forEach(function (b) {
			b.addEventListener('click', function () {
				var box = document.querySelector('[data-spg-block="' + b.getAttribute('data-spg-clear-block') + '"]');
				if (box) { box.value = ''; box.focus(); }
			});
		});
	})();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Shared text helpers
 * ---------------------------------------------------------------------- */

function spg_clip( $text, $max ) {
	$text = trim( sanitize_textarea_field( (string) $text ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/* -------------------------------------------------------------------------
 * REST routes
 * ---------------------------------------------------------------------- */

function spg_lib_kinds() {
	return array(
		'sole_pose', 'hand_pose', 'feet_pose', 'carousel_pose', 'selfie_pose', 'quote_text',
		'sole_setup', 'hand_setup', 'feet_setup', 'feet_carousel_setup', 'selfie_setup', 'quote_setup',
	);
}

function spg_register_rest() {
	register_rest_route(
		'spg/v1',
		'/refine',
		array(
			'methods'             => 'POST',
			'callback'            => 'spg_rest_refine',
			'permission_callback' => 'spg_can_edit',
			'args'                => array(
				'kind'     => array( 'type' => 'string', 'required' => true ),
				'idea'     => array( 'type' => 'string', 'default' => '' ),
				'current'  => array( 'type' => 'string', 'default' => '' ),
				'context'  => array( 'type' => 'object', 'default' => array() ),
				'variants' => array( 'type' => 'integer', 'default' => 1 ),
			),
		)
	);

	$kinds = implode( '|', spg_lib_kinds() );
	register_rest_route(
		'spg/v1',
		'/library/(?P<kind>' . $kinds . ')',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response( spg_lib_get( $req['kind'] ) );
				},
				'permission_callback' => 'spg_can_edit',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'spg_rest_lib_save',
				'permission_callback' => 'spg_can_edit',
			),
		)
	);
	register_rest_route(
		'spg/v1',
		'/library/(?P<kind>' . $kinds . ')/import',
		array(
			'methods'             => 'POST',
			'callback'            => 'spg_rest_lib_import',
			'permission_callback' => 'spg_can_edit',
		)
	);
	register_rest_route(
		'spg/v1',
		'/library/(?P<kind>' . $kinds . ')/(?P<id>[a-z0-9]+)',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'spg_rest_lib_delete',
			'permission_callback' => 'spg_can_edit',
		)
	);
}
add_action( 'rest_api_init', 'spg_register_rest' );

/* ---- AI refine ---- */

function spg_rest_refine( WP_REST_Request $req ) {
	$kind   = (string) $req->get_param( 'kind' );
	$preset = spg_get_preset( $kind );
	if ( '' === $preset ) {
		return new WP_Error( 'spg_kind', __( 'Unknown refine type.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
	}

	$key = spg_get_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'spg_no_key', __( 'No Groq API key yet. Add it in Studio → AI Settings.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
	}

	list( $used, $cap ) = spg_refine_usage();
	if ( $cap && $used >= $cap ) {
		return new WP_Error(
			'spg_daily_cap',
			sprintf(
				/* translators: %d: number of refines allowed per day. */
				__( 'You have used all %d AI refines for today. The count resets at midnight UTC.', 'sole-prompt-generator' ),
				$cap
			),
			array( 'status' => 429 )
		);
	}

	$idea     = spg_clip( $req->get_param( 'idea' ), 3000 );
	$current  = spg_clip( $req->get_param( 'current' ), 4000 );
	$ctx      = (array) $req->get_param( 'context' );
	$variants = (int) $req->get_param( 'variants' );
	$variants = ( $variants > 1 ) ? 3 : 1;

	if ( '' === $idea && '' === $current ) {
		return new WP_Error( 'spg_empty', __( 'Write something first.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
	}

	$lines = array();
	if ( '' !== $idea ) {
		$lines[] = "MY IDEA:\n" . $idea;
		if ( '' !== $current ) {
			$lines[] = "CURRENT TEXT (replace it; reuse only what fits my idea):\n" . $current;
		}
	} else {
		$lines[] = "Refine and improve this existing text. Keep the same content, but make it more precise for Gemini:\n" . $current;
	}

	$scene = array();
	foreach ( array_slice( $ctx, 0, 14, true ) as $label => $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$scene[] = '- ' . spg_clip( $label, 60 ) . ': ' . spg_clip( $value, 500 );
		}
	}
	if ( $scene ) {
		$lines[] = "SCENE CONTEXT (already written elsewhere in the prompt; stay consistent with it, do not repeat it):\n" . implode( "\n", $scene );
	}

	if ( $variants > 1 ) {
		$lines[] = "GIVE ME 3 DIFFERENT OPTIONS. Write option 1, then a line containing only ---, then option 2, then a line containing only ---, then option 3. Each option must follow every rule above on its own and must be meaningfully different from the other two. Do not number, title or comment on them.";
	}

	$model = spg_get_model();
	$body  = array(
		'model'                 => $model,
		'messages'              => array(
			array( 'role' => 'system', 'content' => $preset ),
			array( 'role' => 'user', 'content' => implode( "\n\n", $lines ) ),
		),
		'temperature'           => $variants > 1 ? 0.9 : 0.7,
		'max_completion_tokens' => $variants > 1 ? 8192 : 4096,
	);
	if ( 0 === strpos( $model, 'openai/gpt-oss' ) ) {
		$body['reasoning_effort']  = spg_get_reasoning();
		$body['include_reasoning'] = false;
	}

	$res = wp_remote_post(
		SPG_GROQ_ENDPOINT,
		array(
			'timeout' => 90,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'spg_http', __( 'Could not reach Groq: ', 'sole-prompt-generator' ) . $res->get_error_message(), array( 'status' => 502 ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( 200 !== $code ) {
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
		if ( 401 === $code ) {
			$msg = __( 'Groq rejected the API key. Check it in AI Settings.', 'sole-prompt-generator' );
		} elseif ( 429 === $code ) {
			$msg = __( 'Groq rate limit reached. Wait a moment and try again.', 'sole-prompt-generator' );
		}
		return new WP_Error( 'spg_groq', $msg, array( 'status' => 502 ) );
	}

	$text = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
	$text = preg_replace( '/<think>.*?<\/think>/s', '', $text );

	$parts = ( $variants > 1 ) ? preg_split( '/^\s*-{3,}\s*$/m', $text ) : array( $text );
	$texts = array();
	foreach ( $parts as $part ) {
		$part = spg_tidy_reply( $part );
		if ( '' !== $part ) {
			$texts[] = $part;
		}
	}

	if ( ! $texts ) {
		return new WP_Error( 'spg_empty_reply', __( 'The AI returned an empty answer. Try again.', 'sole-prompt-generator' ), array( 'status' => 502 ) );
	}

	$left = spg_refine_bump();

	return rest_ensure_response(
		array(
			'text'  => $texts[0],
			'texts' => $texts,
			'used'  => $left,
			'cap'   => $cap,
		)
	);
}

function spg_tidy_reply( $text ) {
	$text = preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( (string) $text ) );
	$text = preg_replace( '/^\s*\**\s*(POSE DETAIL|POSE|Stance|Pose\/Activity|Henna|Location\/Background|Location|Background|Clothing)\s*:\s*\**\s*/i', '', $text );
	return trim( $text, " \t\n\r\"" );
}

/* ---- Library: saved poses and saved setups, per person ---- */

function spg_lib_meta_key( $kind ) {
	return 'spg_lib_' . $kind;
}

/* Before 3.0.0 every library was one site-wide option. Copy it once, per user. */
function spg_lib_legacy_option( $kind ) {
	return 'sole_pose' === $kind ? 'spg_saved_poses' : 'spg_lib_' . $kind;
}

function spg_lib_normalize( $items ) {
	$out = array();
	foreach ( is_array( $items ) ? $items : array() as $item ) {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			continue;
		}
		if ( ! array_key_exists( 'value', $item ) && isset( $item['pose'] ) ) {
			$item['value'] = $item['pose'];
			unset( $item['pose'] );
		}
		$out[] = $item;
	}
	return $out;
}

function spg_lib_get( $kind ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	$meta = get_user_meta( $user_id, spg_lib_meta_key( $kind ), true );
	if ( is_array( $meta ) ) {
		return spg_lib_normalize( $meta );
	}

	/* First visit after the upgrade: seed this person from the old shared list. */
	$legacy = get_option( spg_lib_legacy_option( $kind ), array() );
	$items  = spg_lib_normalize( $legacy );
	update_user_meta( $user_id, spg_lib_meta_key( $kind ), $items );
	return $items;
}

function spg_lib_put( $kind, $items ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}
	update_user_meta( $user_id, spg_lib_meta_key( $kind ), array_values( $items ) );
}

function spg_lib_clean_value( $kind, $value ) {
	if ( '_pose' === substr( $kind, -5 ) || '_text' === substr( $kind, -5 ) ) {
		return is_string( $value ) ? spg_clip( $value, 6000 ) : '';
	}
	if ( ! is_array( $value ) || strlen( (string) wp_json_encode( $value ) ) > 40000 ) {
		return null;
	}
	$clean = array();
	foreach ( array_slice( $value, 0, 80, true ) as $k => $v ) {
		$k = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $k );
		if ( '' === $k ) {
			continue;
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
			$clean[ $k ] = $v;
		} elseif ( is_string( $v ) ) {
			$clean[ $k ] = spg_clip( $v, 6000 );
		}
	}
	return $clean ? $clean : null;
}

function spg_rest_lib_save( WP_REST_Request $req ) {
	$kind  = (string) $req['kind'];
	$name  = spg_clip( sanitize_text_field( (string) $req->get_param( 'name' ) ), 80 );
	$value = spg_lib_clean_value( $kind, $req->get_param( 'value' ) );
	if ( '' === $name || null === $value || '' === $value ) {
		return new WP_Error( 'spg_lib_empty', __( 'It needs a name and some content.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
	}

	$items    = spg_lib_get( $kind );
	$replaced = false;
	foreach ( $items as $i => $item ) {
		if ( isset( $item['name'] ) && 0 === strcasecmp( $item['name'], $name ) ) {
			$items[ $i ]['value']   = $value;
			$items[ $i ]['updated'] = time();
			$replaced               = true;
			break;
		}
	}
	if ( ! $replaced ) {
		if ( count( $items ) >= SPG_LIB_MAX ) {
			return new WP_Error( 'spg_lib_full', __( 'This library is full. Delete a few items first.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
		}
		array_unshift(
			$items,
			array(
				'id'      => strtolower( wp_generate_password( 12, false, false ) ),
				'name'    => $name,
				'value'   => $value,
				'updated' => time(),
			)
		);
	}
	spg_lib_put( $kind, $items );

	return rest_ensure_response( array( 'replaced' => $replaced, 'items' => $items ) );
}

function spg_rest_lib_import( WP_REST_Request $req ) {
	$kind    = (string) $req['kind'];
	$incoming = $req->get_param( 'items' );
	if ( ! is_array( $incoming ) || ! $incoming ) {
		return new WP_Error( 'spg_import_empty', __( 'That file had nothing to import.', 'sole-prompt-generator' ), array( 'status' => 400 ) );
	}

	$items   = spg_lib_get( $kind );
	$added   = 0;
	$updated = 0;
	$skipped = 0;

	foreach ( array_slice( $incoming, 0, SPG_LIB_MAX ) as $row ) {
		if ( ! is_array( $row ) ) {
			$skipped++;
			continue;
		}
		$name  = spg_clip( sanitize_text_field( (string) ( isset( $row['name'] ) ? $row['name'] : '' ) ), 80 );
		$value = spg_lib_clean_value( $kind, isset( $row['value'] ) ? $row['value'] : null );
		if ( '' === $name || null === $value || '' === $value ) {
			$skipped++;
			continue;
		}
		$hit = false;
		foreach ( $items as $i => $item ) {
			if ( isset( $item['name'] ) && 0 === strcasecmp( $item['name'], $name ) ) {
				$items[ $i ]['value']   = $value;
				$items[ $i ]['updated'] = time();
				$hit                    = true;
				$updated++;
				break;
			}
		}
		if ( $hit ) {
			continue;
		}
		if ( count( $items ) >= SPG_LIB_MAX ) {
			$skipped++;
			continue;
		}
		array_unshift(
			$items,
			array(
				'id'      => strtolower( wp_generate_password( 12, false, false ) ),
				'name'    => $name,
				'value'   => $value,
				'updated' => time(),
			)
		);
		$added++;
	}

	spg_lib_put( $kind, $items );

	return rest_ensure_response(
		array(
			'added'   => $added,
			'updated' => $updated,
			'skipped' => $skipped,
			'items'   => $items,
		)
	);
}

function spg_rest_lib_delete( WP_REST_Request $req ) {
	$kind  = (string) $req['kind'];
	$id    = (string) $req['id'];
	$items = array_values(
		array_filter(
			spg_lib_get( $kind ),
			function ( $item ) use ( $id ) {
				return $item['id'] !== $id;
			}
		)
	);
	spg_lib_put( $kind, $items );
	return rest_ensure_response( array( 'items' => $items ) );
}

/* -------------------------------------------------------------------------
 * Shortcodes: [studio] (and the old [sole_prompt_generator])
 *
 * [studio]                     every generator, starting on the home tiles
 * [studio tool="sole"]         opens the Sole generator straight away
 * [studio tool="hand" home="no"]  opens Hand and hides the "All prompts" link
 * ---------------------------------------------------------------------- */

function spg_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'tool' => '',
			'home' => 'yes',
		),
		is_array( $atts ) ? $atts : array(),
		'studio'
	);
	spg_enqueue_assets( $atts );
	return '<div class="spg-root"></div>';
}
add_shortcode( 'studio', 'spg_shortcode' );
add_shortcode( 'sole_prompt_generator', 'spg_shortcode' );
