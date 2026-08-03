#!/usr/bin/env python3
"""
Appel DIRECT à Bedrock (boto3), sans LiteLLM.
→ Prouve si l’erreur vient d’AWS (compte / quota) ou de la config proxy.

Usage: depuis ce dossier, .venv actif:  python3 diagnose_bedrock.py
Région:   AWS_REGION_NAME=eu-west-1 python3 diagnose_bedrock.py
Modèle:  BEDROCK_TEST_MODEL=us.anthropic.claude-opus-4-7 python3 diagnose_bedrock.py
(Défaut: Opus 4.6 us.anthropic.claude-opus-4-6-v1)

Codes: 0=OK  | 1=erreur locale (.env, deps)  | 2=throttle/429 AWS  | 3=autre ClientError
"""
import os
import sys

def main() -> int:
    try:
        from dotenv import load_dotenv
    except ImportError:
        print("ERREUR: pip install python-dotenv boto3", file=sys.stderr)
        return 1

    _region_cli = os.environ.get("AWS_REGION_NAME")
    load_dotenv(override=True)
    if _region_cli:
        os.environ["AWS_REGION_NAME"] = _region_cli
    region = os.environ.get("AWS_REGION_NAME", "us-east-1")
    for k in ("AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"):
        if not os.environ.get(k):
            print(f"ERREUR: {k} manquant dans .env", file=sys.stderr)
            return 1

    try:
        import boto3
    except ImportError:
        print("ERREUR: pip install boto3", file=sys.stderr)
        return 1

    # Opus 4.6 (profil us) — **sans** « :0 » (invalide sur Converse + profils).
    # Override 4.7: BEDROCK_TEST_MODEL=us.anthropic.claude-opus-4-7 python3 ...
    model_id = os.environ.get("BEDROCK_TEST_MODEL", "us.anthropic.claude-opus-4-6-v1")
    client = boto3.client("bedrock-runtime", region_name=region)

    print(f"Région: {region}\nModèle: {model_id}\nAppel: converse() ...\n")

    try:
        from botocore.exceptions import ClientError

        r = client.converse(
            modelId=model_id,
            messages=[{"role": "user", "content": [{"text": "Réponds uniquement: OK"}]}],
            inferenceConfig={"maxTokens": 256, "temperature": 0.1},
        )
        out = r.get("output", {})
        text = ""
        for block in out.get("message", {}).get("content", []):
            if "text" in block:
                text += block["text"]
        print("--- SUCCÈS ---\n", text[:500] if text else r)
        return 0
    except ClientError as e:
        err = e.response.get("Error", {}) if e.response else {}
        code = err.get("Code", "")
        msg = err.get("Message", str(e))
        print("--- AWS répond: erreur (pas LiteLLM ici) ---\n", f"Code: {code}\n", msg, "\n")
        if "Throttl" in code or "Throttl" in msg or "too many" in msg.lower() or "429" in str(e):
            return 2
        return 3
    except Exception as e:  # noqa: BLE001
        msg = str(e)
        print("--- ERREUR ---\n", msg, "\n")
        if "Throttl" in msg or "429" in msg or "too many" in msg.lower():
            return 2
        return 3

if __name__ == "__main__":
    sys.exit(main())
