#!/usr/bin/env node
/**
 * Project triage for tecteb-seller.
 *
 * Runs the vendored upstream detector UNCHANGED and then merges the two
 * corrections this repository needs (see ../SKILL.md):
 *
 *  - the plugin header is written docblock style (` * Plugin Name:`), which
 *    the upstream regex `/^\s*Plugin Name:/im` cannot match, so the repo is
 *    reported as kind=unknown;
 *  - Playwright is in tools/browser/package.json, not in a root package.json,
 *    so the upstream tooling scan does not see it.
 *
 * Every change is listed under `corrections`, with the upstream value kept, so
 * this never silently disagrees with the tool it wraps. `--raw` prints the
 * upstream output untouched.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '../../../..');
const upstream = path.join(repoRoot, '.claude/skills/wp-project-triage/scripts/detect_wp_project.mjs');

if (!fs.existsSync(upstream)) {
  console.error(`upstream detector not found at ${upstream}\n` +
    'Install it with the command in .claude/skills/UPSTREAM.md.');
  process.exit(2);
}

const raw = JSON.parse(execFileSync(process.execPath, [upstream], { cwd: repoRoot, encoding: 'utf8' }));
if (process.argv.includes('--raw')) {
  console.log(JSON.stringify(raw, null, 2));
  process.exit(0);
}

const corrections = [];

/** The header WordPress itself accepts, including the docblock form. */
const pluginHeader = (() => {
  for (const entry of fs.readdirSync(repoRoot, { withFileTypes: true })) {
    if (!entry.isFile() || !entry.name.toLowerCase().endsWith('.php')) continue;
    const head = fs.readFileSync(path.join(repoRoot, entry.name), 'utf8').slice(0, 8192);
    const m = head.match(/^[ \t\/*#@]*Plugin Name:\s*(.+?)\s*$/im);
    if (m) return { file: entry.name, name: m[1] };
  }
  return null;
})();

if (pluginHeader && raw.project.primary !== 'wp-plugin') {
  corrections.push({
    field: 'project.primary',
    upstream: raw.project.primary,
    corrected: 'wp-plugin',
    why: `${pluginHeader.file} carries a docblock-style plugin header ("${pluginHeader.name}"), which the upstream regex /^\\s*Plugin Name:/im cannot match.`,
  });
  raw.project.kind = ['wp-plugin'];
  raw.project.primary = 'wp-plugin';
  raw.project.detectedPluginName = pluginHeader.name;
  raw.project.notes = [...(raw.project.notes || []), 'classification corrected by .claude/skills/tmc-wp-skills'];
}

const browserPkg = path.join(repoRoot, 'tools/browser/package.json');
if (fs.existsSync(browserPkg)) {
  const deps = JSON.parse(fs.readFileSync(browserPkg, 'utf8')).devDependencies || {};
  if (deps.playwright && raw.tooling?.tests?.hasPlaywright === false) {
    corrections.push({
      field: 'tooling.tests.hasPlaywright',
      upstream: false,
      corrected: true,
      why: 'Playwright and @axe-core/playwright are declared in tools/browser/package.json; the upstream scan only reads a root package.json.',
    });
    raw.tooling.tests.hasPlaywright = true;
    raw.tooling.tests.playwrightAt = 'tools/browser';
  }
}

raw.recommendations = raw.recommendations || {};
raw.recommendations.commands = [
  'bash tools/run-all-tests.sh',
  'node tools/browser/check-wpadmin.mjs   # needs the disposable WordPress running',
  'bash tools/build.sh',
];
raw.recommendations.notes = [
  'Routing per the decision tree: wp-plugin → wp-plugin-development (+ wp-rest-api, wp-performance).',
  'Owner constraints in CLAUDE.md override any general WordPress advice from the skills.',
];
raw.corrections = corrections;
console.log(JSON.stringify(raw, null, 2));
