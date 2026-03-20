#!/usr/bin/env node
/** Minimal JS linter: checks for syntax errors in <script> blocks extracted from HTML files. */
import { readFileSync, readdirSync, statSync } from "fs";
import { join, extname } from "path";

const ROOT = new URL("..", import.meta.url).pathname;
const HTML_DIRS = [".", "admin", "batteries", "copters", "water"];
let errors = 0;

function findHtmlFiles(dir) {
  const results = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) continue;
    if (extname(entry) === ".html") results.push(full);
  }
  return results;
}

for (const dir of HTML_DIRS) {
  const absDir = join(ROOT, dir);
  for (const file of findHtmlFiles(absDir)) {
    const content = readFileSync(file, "utf-8");
    const scriptRegex = /<script[^>]*>([\s\S]*?)<\/script>/gi;
    let match;
    while ((match = scriptRegex.exec(content)) !== null) {
      const code = match[1].trim();
      if (!code || match[0].includes("type=")) continue;
      try {
        new Function(code);
      } catch (e) {
        console.error(`  ERROR in ${file}: ${e.message}`);
        errors++;
      }
    }
  }
}

if (errors > 0) {
  console.error(`\nLint failed: ${errors} error(s) found.`);
  process.exit(1);
} else {
  console.log("Lint passed: no syntax errors found.");
}
