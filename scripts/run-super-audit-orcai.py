#!/usr/bin/env python3
"""
Appelle l'API Messages (Anthropic-compatible, ex. Orcai) avec le prompt super-audit.

  python3 scripts/run-super-audit-orcai.py                    # passe 1 simple
  python3 scripts/run-super-audit-orcai.py --continue          # passe 2 (continuation)
  python3 scripts/run-super-audit-orcai.py --ultra             # ULTRA_PLAN + ULTRA_REVIEW + boucles jusqu'à quota tokens

Variables utiles:
  ORCAI_SUPER_AUDIT_MODEL           — défaut claude-opus-4-7-20250514
  ORCAI_SUPER_AUDIT_MAX_TOKENS      — défaut 131072 ; « 0 » ou « unlimited » = plafond effectif max (voir ci‑dessous)
  ORCAI_SUPER_AUDIT_MAX_TOKENS_CAP  — défaut 262144 (aucune API n'est vraiment « illimitée » ; le proxy peut tronquer)
  ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS — avec --ultra : cible cumul input+output (défaut 100000)
  ORCAI_SUPER_AUDIT_MAX_PASSES       — avec --ultra : nombre max de tours API (défaut 24)
  ORCAI_OUTPUT_EFFORT               — si supporté par le proxy : max | xhigh | high | medium | low (sinon repli auto)

Note: une seule réponse >100k tokens de sortie est rare ; le mode --ultra enchaîne plusieurs tours pour approcher
la consommation cumulée demandée (voir docs/orchestration/ORCAI_AUDIT_ENDPOINT.md).
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT_PATH = ROOT / "reports/audit/CLAUDE_SUPER_AUDIT_RESPONSE_OPUS47_2026-04-28.md"
PROMPT_PATH = ROOT / "reports/audit/CLAUDE_SUPER_AUDIT_NEXT_ORCHESTRATION_PROMPT_2026-04-28.md"


def post_messages_via_curl(url: str, headers: dict[str, str], body: bytes) -> str:
    """Fallback when Python's SSL stack cannot verify the endpoint (common on macOS framework Python)."""
    curl = shutil.which("curl")
    if not curl:
        raise RuntimeError("curl introuvable — installe curl ou corrige les certificats SSL Python")
    with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
        f.write(body)
        path = f.name
    try:
        cmd = [
            curl,
            "-sS",
            "--max-time",
            "2400",
            "-X",
            "POST",
            url,
            "-d",
            f"@{path}",
        ]
        for k, v in headers.items():
            cmd.extend(["-H", f"{k}: {v}"])
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=2450)
        if proc.returncode != 0:
            raise RuntimeError(proc.stderr or proc.stdout or f"curl exit {proc.returncode}")
        return proc.stdout
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass


def load_env_orcai() -> None:
    p = ROOT / ".env.orcai"
    if not p.is_file():
        print("Manque .env.orcai", file=sys.stderr)
        sys.exit(1)
    for raw in p.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip())


def build_wrapper_base() -> str:
    return (
        "Contexte: cet appel est une API sans outils — tu ne lis pas les fichiers du dépôt en direct. "
        "Réponds au brief comme si tu orchestrais l'audit: structure complète demandée, hypothèses explicites, "
        "sections « à valider avec accès machine », et ne pas inventer de verdict PASS sur du code non lu.\n\n"
        "EXIGENCE D'EXHAUSTIVITÉ (obligatoire): le brief demande une analyse très profonde, pas un résumé. "
        "Produis une réponse LONGUE et détaillée: développe chaque section attendue du brief (verdicts, tableaux par zone, "
        "risques, backlog, missions) avec des paragraphes de justification par ligne importante, des sous-sections, "
        "des critères d'acceptation actionnables, et des annexes (dépendances, ordre de missions suggéré, risques transverses). "
        "Évite les synthèses d'une page; privilégie la complétude. Si tu manques de contexte fichier, détaille quand même "
        "les questions de validation et les preuves à obtenir.\n\n"
        "--- BRIEF ---\n\n"
    )


def build_ultra_plan_prefix() -> str:
    return (
        "## MODE: ULTRA PLAN (effort maximal)\n\n"
        "Tu produis la phase **ULTRA_PLAN**: décomposition exécutable pour Codex, sans simplifier. "
        "Inclure: graphe de dépendances entre chantiers, phases numérotées, missions **CV1-LOT-…** ou équivalent avec "
        "allowlist fichiers, critères d'acceptation mesurables, tests à ajouter, et risques par phase. "
        "Structure Markdown très dense (tableaux + sous-sections). Minimum **longueur**: exploite au maximum le budget de sortie.\n\n"
    )


def build_ultra_review_user_msg() -> str:
    return """\
## MODE: ULTRA REVIEW (effort maximal — relecture adverse)

Sans répéter la partie ULTRA_PLAN telle quelle, produis la phase **ULTRA_REVIEW**:
- Attaque les hypothèses fragiles, les trous de preuve, les contradictions possibles avec les invariants FoodKing.
- Tableau « trou / gravité / action / propriétaire » (minimum 40 lignes si pertinent).
- Liste des **fake PASS** potentiels (tests trop faibles ou décorrelation runtime).
- Orchestration corrective: quoi refaire en premier, avec rationale.

Réponse longue, structurée, Markdown."""


CONTINUE_USER_MSG = """\
Même contrainte: API sans lecture du dépôt.

Ta réponse précédente (partie 1) est trop courte au regard du brief — le modèle s'arrête souvent vers ~8k tokens.

Produit uniquement la PARTIE 2 — ne répète pas la partie 1. Développe de façon exhaustive:
- Chaque zone encore NOT_VALIDATED ou PARTIAL (C3–C6, C9–C10, D3–D13 si peu couverts): risques métier, preuves à obtenir, tests à ajouter, missions Codex numérotées avec critères d'acceptation mesurables.
- Orchestration multi-sprint: phases, dépendances, gates, définition de done avant hardware UAT.
- Liste priorisée des 15–30 prochaines actions avec owners suggérés (Codex / QA / humain).

Réponse longue et structurée en Markdown."""


def ultra_follow_up_prompt(index: int) -> str:
    """Prompts rotatifs pour enchainer jusqu'à quota tokens cumulé."""
    themes = [
        (
            "PARTIE SUITE — Stress & multi-surface",
            "Développe exclusivement: scénarios C3–C5 (runtime simultané, charge stock, charge queue number). "
            "Pour chaque scénario: préconditions, étapes, métriques, seuils PASS/FAIL, scripts/outillage suggérés.",
        ),
        (
            "PARTIE SUITE — Fiscal / ledger / Z / NF525",
            "Développe exclusivement C6 + chaîne transactions/outbox/HMAC/Z-report: états, transitions interdites, "
            "jeux de tests, données minimales reproductibles, checklist pré-production.",
        ),
        (
            "PARTIE SUITE — Dashboard / AuthZ / rôles",
            "Développe C9–C10 et matrice rôles: endpoints à vérifier par rôle, tests API, risques d'élévation.",
        ),
        (
            "PARTIE SUITE — Realtime / broadcast / reconnexion",
            "Développe C8 + résilience réseau: contrats vs runtime, filets de secours, observabilité minimale.",
        ),
        (
            "PARTIE SUITE — Delivery / géo / frais",
            "Développe C7 en profondeur: cas limites, cohérence multi-canal, tests d'intégration suggérés.",
        ),
        (
            "PARTIE SUITE — Design D3–D13 & dette UX",
            "Tableau campagne par campagne: écart design vs risque prod, quick wins vs dette, critères visuels mesurables.",
        ),
        (
            "PARTIE SUITE — Backlog consolidation",
            "Une liste unique **priorisée** (P0–P3) fusionnant tout le précédent: id, livrable, dépendance, risque si on skip.",
        ),
        (
            "PARTIE SUITE — Plan de tests industrialisable",
            "Pyramidage tests (unit/int/e2e/load), environnements, données de test, critères de non-régression avant UAT matériel.",
        ),
    ]
    title, body = themes[(index - 3) % len(themes)]
    return f"## {title} (tour {index})\n\n{body}\n\nRéponds de façon exhaustive (Markdown). Ne résume pas."


def extract_body_after_header(md: str) -> str:
    marker = "\n---\n\n"
    idx = md.find(marker)
    if idx == -1:
        return md.strip()
    return md[idx + len(marker) :].lstrip()


def messages_content_chars(messages: list[dict[str, str]]) -> int:
    """Caractères UTF-8 approximatifs du prompt (somme des contenus user/assistant)."""
    return sum(len(m.get("content") or "") for m in messages)


def effective_input_tokens(
    data: dict[str, Any],
    full_request_chars: int,
) -> tuple[int, str]:
    """
    Entrée « réelle » pour stats cumulées.

    Plusieurs proxies Anthropic-compat (dont certains tiers) renvoient **input_tokens** absent,
    à **0**, ou **plafonné ~8k** alors que le prompt multi-messages fait plusieurs dizaines de k caractères.
    Dans ces cas on préfère une estimation chars→tokens (~÷4) pour le cumul, et on le note en commentaire.
    """
    u = data.get("usage") or {}
    raw_in = u.get("input_tokens")
    est = max(1, full_request_chars // 4)

    if raw_in is None:
        return est, f"input_tokens absent API → estim≈{est} ({full_request_chars} chars)"

    try:
        api_in = int(raw_in)
    except (TypeError, ValueError):
        return est, f"input_tokens invalide → estim≈{est}"

    if api_in == 0:
        return est, f"input_tokens=0 API → estim≈{est} ({full_request_chars} chars)"

    # Heuristique sous-compte / plafond ~8k typique sur proxies tiers
    if full_request_chars >= 24000 and api_in <= 9216 and api_in + 800 < est:
        return est, (
            f"API input_tokens={api_in} mais prompt≈{full_request_chars} chars (~{est} tok estimés) — "
            f"suspect plafond/sous-compte (~8k côté metering); cumul sur estimé chars"
        )

    return api_in, f"input_tokens={api_in} (API)"


def resolve_max_tokens_cap() -> int:
    raw = os.environ.get("ORCAI_SUPER_AUDIT_MAX_TOKENS_CAP", "262144").strip()
    try:
        return max(4096, int(raw))
    except ValueError:
        return 262144


def resolve_max_tokens_request() -> int:
    """« unlimited » / 0 → plafond effectif élevé (le fournisseur tronquera si besoin)."""
    cap = resolve_max_tokens_cap()
    v = os.environ.get("ORCAI_SUPER_AUDIT_MAX_TOKENS", "").strip().lower()
    if v in ("", "0", "unlimited", "max", "none"):
        return min(cap, int(os.environ.get("ORCAI_SUPER_AUDIT_MAX_TOKENS_DEFAULT", "131072")))
    try:
        return min(cap, max(4096, int(v)))
    except ValueError:
        return min(cap, 131072)


def merge_optional_effort(body: dict[str, Any]) -> dict[str, Any]:
    # Défaut « max » si unset — surcharge ORCAI_OUTPUT_EFFORT= pour désactiver (effort géré par le proxy si supporté).
    effort = os.environ.get("ORCAI_OUTPUT_EFFORT", "max").strip()
    if effort.lower() in ("", "none", "false", "0"):
        return body
    out = dict(body)
    out["output_config"] = {"effort": effort}
    return out


def request_headers(key: str) -> dict[str, str]:
    h = {
        "Content-Type": "application/json",
        "anthropic-version": "2023-06-01",
        "x-api-key": key,
    }
    beta = os.environ.get("ORCAI_ANTHROPIC_BETA", "").strip()
    if beta:
        h["anthropic-beta"] = beta
    return h


def call_messages(
    base: str,
    key: str,
    body: dict[str, Any],
    *,
    retry_strip_effort: bool = True,
) -> dict[str, Any]:
    """POST /v1/messages ; si 400 avec effort, retente sans output_config."""
    attempt_body = body
    payload = json.dumps(attempt_body).encode("utf-8")
    hdrs = request_headers(key)
    req = urllib.request.Request(
        f"{base}/v1/messages",
        data=payload,
        headers=hdrs,
        method="POST",
    )
    raw: str
    try:
        with urllib.request.urlopen(req, timeout=2400) as resp:
            raw = resp.read().decode("utf-8")
    except urllib.error.HTTPError as e:
        err_txt = e.read().decode("utf-8", errors="replace")
        if e.code == 400 and retry_strip_effort and "output_config" in attempt_body:
            stripped = {k: v for k, v in attempt_body.items() if k != "output_config"}
            print(
                "[run-super-audit-orcai] HTTP 400 — nouvel essai sans output_config (effort non supporté par le proxy).",
                file=sys.stderr,
            )
            return call_messages(base, key, stripped, retry_strip_effort=False)
        print(err_txt, file=sys.stderr)
        raise SystemExit(e.code if isinstance(e.code, int) else 1)
    except urllib.error.URLError as e:
        err = str(e.reason) if e.reason else str(e)
        if "CERTIFICATE" in err or "SSL" in err:
            try:
                raw = post_messages_via_curl(f"{base}/v1/messages", hdrs, payload)
            except RuntimeError as re:
                print(re, file=sys.stderr)
                raise SystemExit(1)
        else:
            print(err, file=sys.stderr)
            raise SystemExit(1)

    data = json.loads(raw)
    if data.get("type") == "error":
        if retry_strip_effort and "output_config" in attempt_body:
            stripped = {k: v for k, v in attempt_body.items() if k != "output_config"}
            print(
                "[run-super-audit-orcai] Erreur API — nouvel essai sans output_config.",
                file=sys.stderr,
            )
            return call_messages(base, key, stripped, retry_strip_effort=False)
        print(json.dumps(data, ensure_ascii=False, indent=2), file=sys.stderr)
        raise SystemExit(1)
    return data


def blocks_to_text(data: dict[str, Any]) -> str:
    parts: list[str] = []
    for block in data.get("content", []):
        if block.get("type") == "text":
            parts.append(block.get("text", ""))
    return "\n\n".join(parts) if parts else ""


def output_tokens_only(data: dict[str, Any]) -> int:
    u = data.get("usage") or {}
    try:
        return int(u.get("output_tokens") or 0)
    except (TypeError, ValueError):
        return 0


def run_ultra(
    base: str,
    key: str,
    brief: str,
    model: str,
    max_tokens: int,
) -> tuple[str, int, int]:
    """Retourne (markdown_document_cumulative, total_input, total_output)."""
    min_total = int(os.environ.get("ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS", "100000"))
    max_passes = int(os.environ.get("ORCAI_SUPER_AUDIT_MAX_PASSES", "24"))

    messages: list[dict[str, str]] = []
    chunks_md: list[str] = []
    total_in = 0
    total_out = 0

    # Tour 1 — ULTRA_PLAN + brief
    u1 = build_ultra_plan_prefix() + build_wrapper_base() + brief
    body1 = merge_optional_effort(
        {"model": model, "max_tokens": max_tokens, "messages": [{"role": "user", "content": u1}]}
    )
    data1 = call_messages(base, key, body1)
    t1 = blocks_to_text(data1)
    m1 = [{"role": "user", "content": u1}]
    c1 = messages_content_chars(m1)
    i1, note1 = effective_input_tokens(data1, c1)
    o1 = output_tokens_only(data1)
    total_in += i1
    total_out += o1
    chunks_md.append(
        f"# Tour 1 — ULTRA_PLAN\n\n"
        f"<!-- usage tour 1: {note1} | output≈{o1} | raw_usage={json.dumps(data1.get('usage'))} "
        f"stop={data1.get('stop_reason')} -->\n\n{t1}"
    )
    messages = [{"role": "user", "content": u1}, {"role": "assistant", "content": t1}]

    # Tour 2 — ULTRA_REVIEW
    u2 = build_ultra_review_user_msg()
    body2 = merge_optional_effort(
        {"model": model, "max_tokens": max_tokens, "messages": messages + [{"role": "user", "content": u2}]}
    )
    data2 = call_messages(base, key, body2)
    t2 = blocks_to_text(data2)
    m2 = [{"role": "user", "content": u1}, {"role": "assistant", "content": t1}, {"role": "user", "content": u2}]
    c2 = messages_content_chars(m2)
    i2, note2 = effective_input_tokens(data2, c2)
    o2 = output_tokens_only(data2)
    total_in += i2
    total_out += o2
    chunks_md.append(
        f"\n\n---\n\n# Tour 2 — ULTRA_REVIEW\n\n"
        f"<!-- usage tour 2: {note2} | output≈{o2} | raw_usage={json.dumps(data2.get('usage'))} "
        f"stop={data2.get('stop_reason')} -->\n\n{t2}"
    )
    messages.extend([{"role": "user", "content": u2}, {"role": "assistant", "content": t2}])

    n = 3
    while total_in + total_out < min_total and n <= max_passes:
        uk = ultra_follow_up_prompt(n)
        cand_messages = messages + [{"role": "user", "content": uk}]
        approx_chars = sum(len(m.get("content") or "") for m in cand_messages)
        if approx_chars > 450000:
            print(
                "[ultra] Arrêt: historique conversation trop volumineux (~context). "
                "Augmente MIN_TOTAL ou relance un second --ultra dans un nouveau fichier.",
                file=sys.stderr,
            )
            break
        bodyk = merge_optional_effort(
            {"model": model, "max_tokens": max_tokens, "messages": cand_messages}
        )
        datak = call_messages(base, key, bodyk)
        tk = blocks_to_text(datak)
        ck = messages_content_chars(cand_messages)
        ik, notek = effective_input_tokens(datak, ck)
        ok = output_tokens_only(datak)
        total_in += ik
        total_out += ok
        chunks_md.append(
            f"\n\n---\n\n# Tour {n} — SUITE ULTRA\n\n"
            f"<!-- usage tour {n}: {notek} | output≈{ok} | raw_usage={json.dumps(datak.get('usage'))} "
            f"prompt_chars≈{ck} stop={datak.get('stop_reason')} cumul≈{total_in + total_out} -->\n\n{tk}"
        )
        messages.extend([{"role": "user", "content": uk}, {"role": "assistant", "content": tk}])
        print(
            f"[ultra] tour {n} cumul tokens≈{total_in + total_out} (cible≥{min_total})",
            file=sys.stderr,
        )
        n += 1

    doc = "\n".join(chunks_md)
    return doc, total_in, total_out


def main() -> int:
    parser = argparse.ArgumentParser(description="Super-audit Orcai / Opus 4.x")
    parser.add_argument(
        "--continue",
        dest="continue_run",
        action="store_true",
        help="Passe 2: continuation simple (multi-tour léger).",
    )
    parser.add_argument(
        "--ultra",
        action="store_true",
        help="ULTRA_PLAN + ULTRA_REVIEW + boucles jusqu'à ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS (défaut 100k).",
    )
    args = parser.parse_args()

    load_env_orcai()
    base = os.environ.get("ANTHROPIC_BASE_URL", "https://api.orcai.cc").rstrip("/")
    key = os.environ.get("ANTHROPIC_AUTH_TOKEN", "")
    if not key:
        print("ANTHROPIC_AUTH_TOKEN manquant", file=sys.stderr)
        return 1

    if not PROMPT_PATH.is_file():
        print(f"Manque {PROMPT_PATH}", file=sys.stderr)
        return 1

    brief = PROMPT_PATH.read_text(encoding="utf-8")
    model = os.environ.get("ORCAI_SUPER_AUDIT_MODEL", "claude-opus-4-7-20250514")
    max_tokens = resolve_max_tokens_request()

    if args.ultra:
        doc_body, ti, to = run_ultra(base, key, brief, model, max_tokens)
        header = (
            f"# Réponse API — Super audit ULTRA (Orcai / Opus 4.7)\n\n"
            f"- **Mode**: `--ultra` (ULTRA_PLAN + ULTRA_REVIEW + suites)\n"
            f"- **Modèle**: `{model}`\n"
            f"- **max_tokens / requête**: {max_tokens} (plafond effectif ; pas d'« illimité » côté API)\n"
            f"- **ORCAI_OUTPUT_EFFORT**: `{os.environ.get('ORCAI_OUTPUT_EFFORT', 'max')}` (ignoré si proxy non compatible)\n"
            f"- **Tokens cumulés (script)** : input+output par tour — l’**input** utilise le **`usage` API** si crédible, "
            f"sinon une **estimation chars÷4** (certains proxies affichent un plafond ~**8k** `input_tokens` alors que le prompt multi-tour est bien plus gros). Voir `docs/orchestration/ORCAI_AUDIT_ENDPOINT.md`.\n"
            f"- **Totaux cumulés** : ≈ **{ti + to}** (input≈{ti}, output≈{to})\n"
            f"- **Cible** `ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS`: {os.environ.get('ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS', '100000')}\n\n---\n\n"
        )
        OUT_PATH.write_text(header + doc_body, encoding="utf-8")
        print(f"ULTRA: écrit {OUT_PATH.relative_to(ROOT)} — cumul ≈ {ti + to} tokens")
        return 0

    wrapper = build_wrapper_base()

    if args.continue_run:
        if not OUT_PATH.is_file():
            print(f"Passe --continue: fichier manquant {OUT_PATH} — lance d'abord sans --continue.", file=sys.stderr)
            return 1
        existing = OUT_PATH.read_text(encoding="utf-8")
        part1_body = extract_body_after_header(existing)
        body = merge_optional_effort(
            {
                "model": model,
                "max_tokens": max_tokens,
                "messages": [
                    {"role": "user", "content": wrapper + brief},
                    {"role": "assistant", "content": part1_body},
                    {"role": "user", "content": CONTINUE_USER_MSG},
                ],
            }
        )
        label = "Passe 2 (continuation)"
    else:
        body = merge_optional_effort(
            {"model": model, "max_tokens": max_tokens, "messages": [{"role": "user", "content": wrapper + brief}]}
        )
        label = "Passe 1"

    data = call_messages(base, key, body)
    out_text = blocks_to_text(data)
    if not out_text.strip():
        print("Réponse vide (pas de blocs texte).", file=sys.stderr)
        return 1

    stop_reason = data.get("stop_reason", "")
    usage = data.get("usage", {})

    if args.continue_run:
        sep = (
            "\n\n---\n\n## Partie 2 — développement exhaustif (suite multi-tour)\n\n"
            f"- **stop_reason**: `{stop_reason}`\n"
            f"- **Usage** (API): `{json.dumps(usage, ensure_ascii=False)}`\n\n---\n\n"
        )
        new_doc = existing.rstrip() + sep + out_text
        OUT_PATH.write_text(new_doc, encoding="utf-8")
        print(f"{label}: append → {OUT_PATH.relative_to(ROOT)} ({len(new_doc)} caractères total)")
    else:
        header = (
            f"# Réponse API — Super audit (Orcai / Opus 4.7)\n\n"
            f"- **Étape**: {label}\n"
            f"- **Modèle**: `{model}`\n"
            f"- **max_tokens / requête**: {max_tokens}\n"
            f"- **ORCAI_OUTPUT_EFFORT**: `{os.environ.get('ORCAI_OUTPUT_EFFORT', '') or '(non défini)'}`\n"
            f"- **stop_reason**: `{stop_reason}`\n"
            f"- **Usage** (API): `{json.dumps(usage, ensure_ascii=False)}`\n"
            f"- **Limite**: pas de lecture fichier via API.\n"
            f"- **Suite**: `python3 scripts/run-super-audit-orcai.py --continue` ou `--ultra` pour quota élevé.\n\n---\n\n"
        )
        OUT_PATH.write_text(header + out_text, encoding="utf-8")
        print(f"{label}: écrit {OUT_PATH.relative_to(ROOT)} ({len(header + out_text)} caractères)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
