#!/usr/bin/env python3
"""Tradução automática do PRO Elements.

  python scripts/translate.py --elementor E --pro P [--limit N]

Para cada idioma de upstream.json ("ai_locales"):
  1. lista os textos do PRO Elements da versão P;
  2. o que já está em translations/pro-elements/<idioma>.json fica como está (cada texto é traduzido uma vez só);
  3. o que existe igual na tradução oficial do Elementor E é copiado de lá (grátis e com a mesma terminologia);
  4. o resto vai para o Claude, em lotes, com a tradução do Elementor como glossário.

Sem ANTHROPIC_API_KEY o passo 4 é pulado: o pacote sai com o que já estiver traduzido.
"""

import argparse
import io
import json
import os
import re
import sys
import tempfile
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from build import PRO_ZIP, TRANSLATIONS_DIR, download, extract_single_folder, http_get, read_upstream  # noqa: E402
from i18n_strings import CONTEXT_SEPARATOR, extract, read_po  # noqa: E402

ELEMENTOR_PACK = "https://downloads.wordpress.org/translation/plugin/elementor/{version}/{locale}.zip"

MODEL = "claude-opus-5-5"
BATCH_SIZE = 120
GLOSSARY_MAX_LENGTH = 60

LANGUAGE_NAMES = {"pt_BR": "português do Brasil", "es_ES": "espanhol (Espanha)", "fr_FR": "francês"}

# Marcadores que precisam sair idênticos na tradução.
PLACEHOLDERS = re.compile(r"%(?:\d+\$)?[sdfu]|%%|<[^>]+>|\{\{[^}]*\}\}|\{[\w.]+\}|&[a-z]+;")

OUTPUT_SCHEMA = {
    "type": "object",
    "properties": {
        "items": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "integer"},
                    "translation": {"type": "string"},
                    "plural_translation": {"type": "string"},
                },
                "required": ["id", "translation", "plural_translation"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["items"],
    "additionalProperties": False,
}


def load_cache(locale):
    path = TRANSLATIONS_DIR / f"{locale}.json"
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}


def save_cache(locale, cache):
    TRANSLATIONS_DIR.mkdir(parents=True, exist_ok=True)
    path = TRANSLATIONS_DIR / f"{locale}.json"
    path.write_text(json.dumps(dict(sorted(cache.items())), ensure_ascii=False, indent=1) + "\n", encoding="utf-8")


def elementor_translations(version, locale):
    """Tradução oficial do Elementor (PHP .po + JavaScript .json) como {chave: [traduções]}."""
    try:
        data = http_get(ELEMENTOR_PACK.format(version=version, locale=locale))
    except Exception as error:  # noqa: BLE001 - sem pacote para esse idioma/versão: segue sem glossário
        print(f"  Sem tradução oficial do Elementor {version} para {locale}: {error}")
        return {}

    translations = {}
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as folder, zipfile.ZipFile(io.BytesIO(data)) as pack:
        pack.extractall(folder)
        for po in Path(folder).glob("*.po"):
            translations.update(read_po(po))
        for jed in Path(folder).glob("*.json"):
            locale_data = json.loads(jed.read_text(encoding="utf-8")).get("locale_data", {})
            for messages in locale_data.values():
                for key, values in messages.items():
                    if key and isinstance(values, list) and any(values):
                        translations.setdefault(key, values)
    return translations


def placeholders(text):
    return sorted(PLACEHOLDERS.findall(text or ""))


def system_prompt(locale, glossary):
    language = LANGUAGE_NAMES.get(locale, locale)
    terms = "\n".join(
        f"{json.dumps(source, ensure_ascii=False)} => {json.dumps(values[0], ensure_ascii=False)}"
        for source, values in glossary
    )
    return (
        f"Você traduz a interface de um construtor de sites para WordPress (PRO Elements, a versão Pro do Elementor) "
        f"do inglês para {language}. Os textos aparecem em botões, menus, rótulos de campos, avisos e descrições "
        f"para quem monta sites.\n\n"
        f"Regras:\n"
        f"- Use a mesma terminologia da tradução oficial do Elementor (glossário abaixo). Ex.: se o glossário traduz "
        f"\"Widget\" como \"Widget\", mantenha.\n"
        f"- Mantenha exatamente, sem traduzir nem mudar a ordem relativa quando possível: marcadores como %s, %1$s, %d, "
        f"%%, tags HTML (<a href=\"...\">, <strong>), {{{{variáveis}}}}, {{chaves}} e entidades como &nbsp;.\n"
        f"- Não traduza nomes de produtos e marcas (Elementor, PRO Elements, WordPress, WooCommerce, Google, etc.).\n"
        f"- Textos curtos de interface ficam curtos. Siga as maiúsculas do original só no início; no resto use a "
        f"capitalização natural de {language}.\n"
        f"- Quando houver \"context\", use-o só para escolher o sentido certo; não o traduza.\n"
        f"- Quando houver \"plural\", devolva a forma singular em translation e a plural em plural_translation; "
        f"senão, plural_translation fica vazio.\n"
        f"- Devolva um item para cada id recebido.\n\n"
        f"Glossário (tradução oficial do Elementor):\n{terms}"
    )


def translate_batch(client, system, batch):
    request = [
        {"id": index, "text": message.text}
        | ({"context": message.context} if message.context else {})
        | ({"plural": message.plural} if message.plural else {})
        for index, message in enumerate(batch)
    ]
    with client.beta.messages.stream(
        model=MODEL,
        max_tokens=64000,
        betas=["server-side-fallback-2026-07-01"],
        fallbacks="default",  # se o modelo recusar um lote, a API tenta de novo em outro modelo
        output_config={"effort": "low", "format": {"type": "json_schema", "schema": OUTPUT_SCHEMA}},
        system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
        messages=[{"role": "user", "content": json.dumps(request, ensure_ascii=False)}],
    ) as stream:
        response = stream.get_final_message()

    if response.stop_reason != "end_turn":
        print(f"  Lote ignorado (stop_reason={response.stop_reason}); tenta de novo na próxima versão.")
        return {}

    text = next(block.text for block in response.content if block.type == "text")
    usage = response.usage
    print(
        f"  Lote de {len(batch)}: entrada {usage.input_tokens} (+{usage.cache_read_input_tokens or 0} do cache), "
        f"saída {usage.output_tokens} tokens"
    )

    results = {}
    for item in json.loads(text)["items"]:
        if not 0 <= item["id"] < len(batch):
            continue
        message = batch[item["id"]]
        values = [item["translation"]]
        if message.plural:
            values.append(item["plural_translation"])
        sources = [message.text] + ([message.plural] if message.plural else [])
        if not all(value.strip() for value in values) or any(
            placeholders(source) != placeholders(value) for source, value in zip(sources, values)
        ):
            print(f"  Descartado (marcadores diferentes): {message.text!r} -> {values!r}")
            continue
        results[message.key] = values
    return results


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--elementor", required=True)
    parser.add_argument("--pro", required=True)
    parser.add_argument("--limit", type=int, help="traduz no máximo N textos com IA (para testes)")
    args = parser.parse_args()

    locales = read_upstream().get("ai_locales", [])
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as folder:
        pro_dir = Path(folder) / "pro-elements"
        extract_single_folder(download(PRO_ZIP.format(version=args.pro), f"pro-elements-{args.pro}.zip"), pro_dir)
        messages = extract(pro_dir, "elementor-pro")
    print(f"PRO Elements {args.pro}: {len(messages)} textos")

    client = None
    if os.environ.get("ANTHROPIC_API_KEY"):
        import anthropic

        client = anthropic.Anthropic()
    else:
        print("ANTHROPIC_API_KEY não configurada: só a tradução do Elementor será aproveitada.")

    for locale in locales:
        cache = load_cache(locale)
        reference = elementor_translations(args.elementor, locale)

        missing = [message for key, message in messages.items() if key not in cache]
        reused = 0
        for message in missing:
            values = reference.get(message.key)
            if values and len(values) >= (2 if message.plural else 1):
                cache[message.key] = values[: 2 if message.plural else 1]
                reused += 1
        missing = [message for message in missing if message.key not in cache]
        print(f"{locale}: {len(cache)} já traduzidos ({reused} novos vindos do Elementor), {len(missing)} faltando")

        if client and missing:
            glossary = sorted(
                (key, values)
                for key, values in reference.items()
                if CONTEXT_SEPARATOR not in key and len(key) <= GLOSSARY_MAX_LENGTH
            )
            system = system_prompt(locale, glossary)
            to_translate = missing[: args.limit] if args.limit else missing
            for start in range(0, len(to_translate), BATCH_SIZE):
                cache.update(translate_batch(client, system, to_translate[start : start + BATCH_SIZE]))
                save_cache(locale, cache)  # salva a cada lote: uma falha no meio não perde o que já foi pago

        save_cache(locale, cache)
        remaining = sum(1 for key in messages if key not in cache)
        print(f"{locale}: {len(messages) - remaining} de {len(messages)} textos do PRO Elements traduzidos")


if __name__ == "__main__":
    main()
