import { readFileSync } from "node:fs";

const stylesheet = readFileSync(new URL("../app/globals.css", import.meta.url), "utf8");
const colors = new Map(
  [...stylesheet.matchAll(/--color-([\w-]+):\s*#([a-f\d]{6});/gi)]
    .map(([, name, hex]) => [name, hex]),
);

const textOnBackground = {
  paper: ["ink", "muted", "primary", "success", "warning-ink", "danger", "info"],
  surface: ["ink", "muted", "primary", "success", "warning-ink", "danger", "info"],
  primary: ["surface"],
  "primary-hover": ["surface"],
  "primary-tint": ["ink", "primary"],
  "gold-tint": ["warning-ink"],
  "dark-paper": ["dark-primary", "surface"],
  "dark-surface": ["dark-primary", "surface"],
};

function luminance(name) {
  const hex = colors.get(name);
  if (!hex) throw new Error(`Missing color token: ${name}`);
  const channels = hex.match(/../g).map((channel) => {
    const srgb = Number.parseInt(channel, 16) / 255;
    return srgb <= 0.04045 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

let checked = 0;
for (const [background, textColors] of Object.entries(textOnBackground)) {
  const backdrop = luminance(background);
  for (const foreground of textColors) {
    const text = luminance(foreground);
    const ratio = (Math.max(text, backdrop) + 0.05) / (Math.min(text, backdrop) + 0.05);
    checked++;
    if (ratio < 4.5) {
      console.error(`${foreground} on ${background}: ${ratio.toFixed(2)} (minimum 4.5)`);
      process.exitCode = 1;
    }
  }
}

if (!process.exitCode) console.log(`${checked} text/background pairs meet WCAG AA (4.5:1).`);
