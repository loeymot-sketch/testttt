# MCP / browser / skills tooling audit - 2026-04-26

## Scope

Audit des suggestions vues dans les captures: Playwright MCP, Auto Browser / `LvcidPsyche/auto-browser`, Camofox / Camoufox browser, browser-use, HackerAI, Claude Code skills, Ollama + Gemma 4, prompts XML/structured prompting, skills marketplaces, and "brain/wiki" agent systems.

This report is tooling governance only. No FoodKing product file, migration, routing file, or active MCP config was modified.

## Local inventory

- `~/.cursor/mcp.json` already has `graphiti` and `playwright`.
- Repo has `.cursor/mcp/playwright.json` configured for `@playwright/mcp@latest`, Chromium, `PLAYWRIGHT_BASE_URL=http://localhost:8000`.
- `npm view @playwright/mcp version` returned `0.0.70`.
- `npm view @playwright/test version` returned `1.59.1`; repo currently pins `^1.58.2`.
- Machine: Apple M1 Pro, 16 GB RAM, 10 CPU threads.
- `docker` is not installed.
- `ollama` is not installed.
- `claude` and `codex` binaries are present.
- `npm run verify:boucle` returned CONDITIONAL: binaries OK; API smoke not run by default.

## Verdict table

| Tool / idea | Verdict | Reason |
|---|---:|---|
| Playwright MCP | KEEP / PRIMARY for FoodKing E2E | Already configured. Best fit for local app QA, deterministic snapshots, screenshots, traceability, and plan-declared critical flows. |
| Playwright CLI | KEEP | Existing repo standard for E2E. Do not upgrade during active Caisse V1 cycle unless a dedicated plan asks for it. |
| Auto Browser (`LvcidPsyche/auto-browser`) | POC_ONLY | Interesting because it exposes a real browser over HTTP, starts visible by default, and can fit human-supervised web workflows. Not installed now because Docker is absent and FoodKing already has Playwright. |
| Camofox / `jo-inc/camofox-browser` | REJECT for FoodKing/general use | The project markets anti-detection, Cloudflare/Google bypass, cookie import, residential proxy use, and similar behavior. This is high legal/security risk and not needed for authorized local QA. |
| browser-use | WATCHLIST / use existing Browser Use plugin only | Useful browser-agent ecosystem, but overlaps Playwright and has cloud features around captcha/proxy/stealth. No new install now. |
| HackerAI | REJECT for this repo | Large pentesting assistant stack requiring multiple third-party accounts. It increases security surface and is unrelated to current FoodKing Caisse V1 QA. |
| Claude Code official skills | ADOPT selectively | Official skill mechanism is useful. Add only reviewed, minimal, local skills that do not override FoodKing SSOT. |
| Skills.sh / community skill marketplaces | REJECT bulk install | No bulk skill install. Skills can execute workflows and influence tool use; each skill needs source review and explicit scope. |
| Ollama + Gemma 4 | WATCHLIST | Useful for private/offline scratch work, not a replacement for FoodKing `codex-extension` / Claude audit. On 16 GB RAM, only small Gemma 4 variants are realistic. |
| Prompting advice from captures | PARTIAL ADOPT | Keep XML/source tags, explicit roles, output specs, negative constraints, source indexing, and error-handling contracts. Ignore unverifiable marketing metrics. |
| One-person business / AI agent prompt threads | REFERENCE_ONLY | Good examples of structured prompts. Not canonical FoodKing process. Do not store as a new memory source. |
| AI video automation via Higgsfield/Seedance/etc. | AUTHORIZED_USE_ONLY | Accept only official APIs or explicit user-controlled accounts where automation is permitted. No anti-bot, captcha bypass, cookie theft, or ToS evasion. |

## Source checks

- Microsoft `playwright-mcp`: official MCP server, browser automation via structured accessibility snapshots, local/isolated profiles, browser options, security notes.
- `LvcidPsyche/auto-browser`: HTTP browser server for AI agents; "real browser", API key auth, Docker-first install, not a browser-use fork, visible browser by default.
- `jo-inc/camofox-browser`: markets bypassing Cloudflare and Google, cookie import from Chrome, proxy/proxy-pool workflows, and stealth/anti-detection goals.
- `browser-use/browser-use`: broad browser automation ecosystem; local package exists, but MCP package `@browser-use/mcp` was not found on npm.
- Anthropic docs: MCP connects Claude Code to external tools/data; skills are organized instruction bundles; XML tags are recommended for prompt structure.
- MCP spec security notes: only install trusted servers, review code, pin versions, avoid secrets in arguments, and use least privilege.
- Ollama Gemma 4 page: exposes Gemma 4 variants and an `ollama launch claude --model gemma4` flow; this is local-model routing, not the same as Anthropic Claude model quality.
- HackerAI repo: AI-powered penetration testing assistant; requires OpenRouter, OpenAI, E2B, Convex, WorkOS, and web search provider accounts.

## FoodKing decision

1. Do not install a new browser MCP today.
2. Keep Playwright MCP as the approved browser automation MCP for FoodKing localhost QA.
3. Keep Browser Use plugin as an optional in-session inspection tool for local/authorized browser tasks.
4. Do not install Camofox/Camoufox or any stealth/anti-detection browser stack.
5. Do not install HackerAI in this repo.
6. Do not install marketplace/community skills in bulk.
7. Add a local `external-tooling-audit` skill so future MCP/skills/tool suggestions are reviewed consistently before install.

## Future safe POC plan

If the human explicitly wants a browser-agent POC later:

1. Create a dedicated task, e.g. `TOOLING-AUTO-BROWSER-POC-001`.
2. Keep scope outside product files: `reports/audit/`, `.cursor/mcp/*.example`, and optional local scripts only.
3. Install Docker first outside the repo if Auto Browser is still selected.
4. Run Auto Browser only against `localhost`, owned staging domains, or sites with explicit permission.
5. Disable/avoid stealth, proxy, captcha-bypass, cookie-import, or anti-bot evasion features.
6. Produce a compare report: Playwright MCP vs Auto Browser on one FoodKing read-only QA workflow.
7. Only after PASS, decide whether to add an example MCP config. Do not put secrets in repo config.

## Hard refusal boundary

I will not help configure tools to bypass anti-bot systems, evade website restrictions, import session cookies, avoid detection, or automate third-party services against their rules. The approved path is local FoodKing QA, owned/staging systems, official APIs, or explicit permission.

