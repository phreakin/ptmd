🗂️ 1. Add All Images to Project (Clean Structure)

Use this inside your existing /assets folder:

/assets
/brand
/logos
ptmd-primary-seal.png
ptmd-seal-monochrome.png
ptmd-acronym-evidence.png
/thumbnails
thumbnail-hidden-truth.png
thumbnail-what-really-happened.png
/watermarks
ptmd-watermark-white.png
ptmd-watermark-dark.png
🎯 2. Watermark Versions (IMPORTANT)

You need 2 watermark styles for video:

Light (for dark footage)
White logo
60% opacity
Bottom right corner
Dark (for bright footage)
Black/gray logo
40% opacity
Bottom right corner
CSS Overlay Example (for web video player)
.video-watermark {
position: absolute;
bottom: 15px;
right: 15px;
width: 120px;
opacity: 0.6;
pointer-events: none;
}
🎬 3. Video Overlay System (THIS IS HUGE)

These overlays make your content feel like a real network, not just clips.

🔴 A. Lower Third (Name/Topic Bar)

Use when introducing topics or key moments

Design:

Left: red bar (#C1121F)
Text: white
Accent: yellow underline

Text Example:

CASE FILE: TWITTER COLLAPSE
🟡 B. Highlight Overlay

Used for key lines or punchlines

Yellow highlight bar (#FFD60A)
Black text
Slight animation (fade + slide)
🔵 C. Data Overlay

Used for timelines or facts

Teal (#2EC4B6)
Minimal, clean
Looks analytical
⚫ D. “EVIDENCE” Stamp Overlay

Signature move

Red stamp animation
Slight rotation + impact effect

Use sparingly:

Big reveal moments
Contradictions
Receipts
🎥 4. Intro Sequences (Per Platform)

Each platform needs a slightly different intro length + pacing

▶️ YouTube (Main Documentary)

Length: 5–8 seconds

Structure:

Dark screen
Subtle audio (heartbeat/static)
Logo fades in
Tagline appears:

“We Follow the Story Until It Breaks…
Or Until It Stops Making Sense.”

End with quick glitch cut → video starts

📱 TikTok / Reels / Shorts

Length: 1–2 seconds (MAX)

Skip long intros.

Instead:

Flash logo (0.5 sec)
Immediate hook line

Example:

“Something about this story doesn’t add up…”

🐦 X (Twitter)

Length: 0–1 second

No intro.
Start with:

Strong visual
Text overlay immediately
📘 Facebook

Length: 2–3 seconds

Slightly slower than TikTok:

Logo flash
Then hook
🎞️ 5. Plug-and-Play Overlay HTML (For Your Site)
<div class="video-container">
  <video controls>
    <source src="case.mp4" type="video/mp4">
  </video>

<img src="/assets/brand/watermarks/ptmd-watermark-white.png"
class="video-watermark">
</div>
🎨 6. Thumbnail System (Keep This Consistent)

Every thumbnail should follow:

BIG TEXT (3–5 words max)
ONE emotional face or focal image
ONE accent color (yellow or red)
DARK background

Examples you already have:

“THE HIDDEN TRUTH?”
“WHAT REALLY HAPPENED?”

👉 This is your growth engine.

🧠 7. What You Just Built

You now have:

Full brand asset system
Video identity (huge)
Cross-platform strategy baked into visuals
A setup that can scale into:
YouTube channel
Website
Social network presence