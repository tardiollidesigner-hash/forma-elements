#!/usr/bin/env python3
"""Robô de empacotamento do Forma Elements.

  check  [--force]                            confere se saiu Elementor ou PRO Elements novo
  dev    [--elementor E] [--pro P]            prepara a pasta do repositório para testes locais
  build  --version V --elementor E --pro P    monta dist/forma-elements.zip
  record --version V --elementor E --pro P    grava em upstream.json o que foi publicado

Usa só a biblioteca padrão do Python, para rodar igual no GitHub e no Windows.
"""

import argparse
import io
import json
import os
import re
import shutil
import sys
import tempfile
import urllib.request
import zipfile
from pathlib import Path

from i18n_strings import DEFAULT_PLURAL_FORMS, PLURAL_FORMS, extract, write_mo

ROOT = Path(__file__).resolve().parent.parent
UPSTREAM_FILE = ROOT / "upstream.json"
DIST = ROOT / "dist"
CACHE = ROOT / "build-cache"
TRANSLATIONS_DIR = ROOT / "translations" / "pro-elements"
SLUG = "forma-elements"

# Arquivos do repositório que vão para dentro do plugin.
PLUGIN_FILES = ["forma-elements.php", "includes", "assets", "readme.txt", "LICENSE"]
DEV_VERSION = "0.0.0-dev"

ELEMENTOR_INFO = "https://api.wordpress.org/plugins/info/1.0/elementor.json"
ELEMENTOR_ZIP = "https://downloads.wordpress.org/plugin/elementor.{version}.zip"
PRO_LATEST = "https://api.github.com/repos/proelements/proelements/releases/latest"
PRO_ZIP = "https://github.com/proelements/proelements/releases/download/v{version}/pro-elements.zip"
PUC_ZIP = "https://github.com/YahnisElsts/plugin-update-checker/archive/refs/tags/v{version}.zip"

PRO_UPDATER_STUB = """<?php
namespace ElementorPro\\Core\\Updater;

// Forma Elements: atualizador próprio do PRO Elements desligado. As atualizações chegam pelo Forma Elements.
if ( ! defined( 'ABSPATH' ) ) {
\texit;
}

class Updater {
\tpublic function __construct( $config = [] ) {}
}
"""


def http_get(url):
    headers = {"User-Agent": "forma-elements-build"}
    token = os.environ.get("GITHUB_TOKEN")
    if token and url.startswith("https://api.github.com/"):
        headers["Authorization"] = "Bearer " + token
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=120) as response:
        return response.read()


def read_upstream():
    return json.loads(UPSTREAM_FILE.read_text(encoding="utf-8"))


def latest_versions():
    elementor = json.loads(http_get(ELEMENTOR_INFO))["version"]
    pro = json.loads(http_get(PRO_LATEST))["tag_name"].lstrip("v")
    return elementor, pro


def next_version(current):
    if not current:
        return "1.0.0"
    major, minor, patch = (int(part) for part in current.split("."))
    return f"{major}.{minor}.{patch + 1}"


def set_outputs(values):
    lines = "".join(f"{key}={value}\n" for key, value in values.items())
    output_file = os.environ.get("GITHUB_OUTPUT")
    if output_file:
        with open(output_file, "a", encoding="utf-8") as handle:
            handle.write(lines)
    sys.stdout.write(lines)


def cmd_check(args):
    upstream = read_upstream()
    elementor, pro = latest_versions()
    changed = args.force or elementor != upstream["elementor"] or pro != upstream["pro_elements"]
    set_outputs({
        "changed": str(changed).lower(),
        "version": next_version(upstream["plugin_version"]),
        "elementor": elementor,
        "pro_elements": pro,
        "previous_elementor": upstream["elementor"] or "",
        "previous_pro_elements": upstream["pro_elements"] or "",
    })


def download(url, name):
    CACHE.mkdir(exist_ok=True)
    target = CACHE / name
    if not target.exists():
        print(f"Baixando {url}")
        target.write_bytes(http_get(url))
    return target


def extract_single_folder(zip_path, destination):
    """Extrai um zip cujo conteúdo está numa única pasta raiz, trocando o nome dessa pasta por `destination`."""
    with zipfile.ZipFile(zip_path) as archive:
        roots = {name.split("/", 1)[0] for name in archive.namelist()}
        if len(roots) != 1:
            raise SystemExit(f"{zip_path.name}: esperava uma pasta raiz, achei {sorted(roots)}")
        root = roots.pop() + "/"
        for member in archive.infolist():
            relative = member.filename[len(root):]
            if not relative or member.is_dir():
                continue
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(member) as source, open(target, "wb") as handle:
                shutil.copyfileobj(source, handle)


def require_file(path):
    if not path.is_file():
        raise SystemExit(f"Pacote inválido: {path} não existe")


def cmd_build(args):
    # A montagem acontece numa pasta temporária do sistema: no Windows, pastas do projeto
    # com milhares de arquivos recém-extraídos ficam travadas pelo indexador/antivírus.
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as staging:
        zip_path = assemble(args, Path(staging) / SLUG)
    print(f"Pacote: {zip_path} ({zip_path.stat().st_size / 1_048_576:.1f} MB)")
    print(f"Forma Elements {args.version} = Elementor {args.elementor} + PRO Elements {args.pro}")


def assemble(args, package):
    upstream = read_upstream()
    package.mkdir(parents=True)

    for item in PLUGIN_FILES:
        source = ROOT / item
        if source.is_dir():
            shutil.copytree(source, package / item)
        else:
            shutil.copy2(source, package / item)

    for name in ("forma-elements.php", "readme.txt"):
        path = package / name
        text = path.read_text(encoding="utf-8")
        if DEV_VERSION not in text:
            raise SystemExit(f"{name}: marcador {DEV_VERSION} não encontrado")
        path.write_text(text.replace(DEV_VERSION, args.version), encoding="utf-8")

    add_upstream_code(package, args.elementor, args.pro, upstream["update_checker"])

    DIST.mkdir(exist_ok=True)
    zip_path = DIST / f"{SLUG}.zip"
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(package.rglob("*")):
            if path.is_file():
                archive.write(path, path.relative_to(package.parent).as_posix())
    return zip_path


def add_upstream_code(package, elementor, pro, puc):
    """Coloca o Elementor, o PRO Elements e a biblioteca de atualização dentro de `package`."""
    extract_single_folder(
        download(ELEMENTOR_ZIP.format(version=elementor), f"elementor-{elementor}.zip"),
        package / "bundled" / "elementor",
    )
    extract_single_folder(
        download(PRO_ZIP.format(version=pro), f"pro-elements-{pro}.zip"),
        package / "bundled" / "pro-elements",
    )
    extract_single_folder(
        download(PUC_ZIP.format(version=puc), f"plugin-update-checker-{puc}.zip"),
        package / "vendor" / "plugin-update-checker",
    )

    require_file(package / "bundled" / "elementor" / "elementor.php")
    require_file(package / "bundled" / "pro-elements" / "pro-elements.php")
    require_file(package / "vendor" / "plugin-update-checker" / "plugin-update-checker.php")

    # Única alteração no código de terceiros: o atualizador próprio do PRO Elements consulta o GitHub
    # a cada tela do painel e, depois de QUALQUER atualização de plugin, move a pasta para
    # wp-content/plugins/pro-elements, o que quebraria o Forma Elements. Ele vira uma classe vazia.
    pro_updater = package / "bundled" / "pro-elements" / "updater" / "updater.php"
    require_file(pro_updater)
    if "namespace ElementorPro\\Core\\Updater;" not in pro_updater.read_text(encoding="utf-8"):
        raise SystemExit("O atualizador do PRO Elements mudou: revise o stub em scripts/build.py")
    pro_updater.write_text(PRO_UPDATER_STUB, encoding="utf-8")

    write_brand_strings(package / "bundled")
    compile_pro_translations(package / "bundled")


BRAND_WORDS = re.compile(r"Elementor|Pro Elements|PRO Elements")


def write_brand_strings(bundled):
    """Lista os textos do editor (JavaScript) que citam a marca, para o Forma Elements trocá-los
    mesmo quando não há tradução (site em inglês ou texto novo ainda não traduzido)."""
    data = {}
    for domain in ("elementor", "elementor-pro"):
        messages = extract(bundled, domain).values()
        data[domain] = sorted(m.key for m in messages if m.in_js and not m.plural and BRAND_WORDS.search(m.text))
    (bundled / "brand-strings.json").write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding="utf-8")
    print("Textos com a marca no editor: " + ", ".join(f"{d} {len(s)}" for d, s in data.items()))


def compile_pro_translations(bundled):
    """Transforma translations/pro-elements/<idioma>.json (feito por scripts/translate.py) em
    bundled/languages/elementor-pro-<idioma>.mo (PHP) e elementor-pro-<idioma>-js.json (editor)."""
    sources = sorted(TRANSLATIONS_DIR.glob("*.json"))
    if not sources:
        return

    messages = extract(bundled / "pro-elements", "elementor-pro")
    languages = bundled / "languages"
    languages.mkdir(exist_ok=True)

    for source in sources:
        locale = source.stem
        cache = json.loads(source.read_text(encoding="utf-8"))
        found = {key: (messages[key].plural, values) for key, values in cache.items() if key in messages}
        write_mo(languages / f"elementor-pro-{locale}.mo", found, locale)

        js = {"": {"domain": "messages", "lang": locale, "plural-forms": PLURAL_FORMS.get(locale, DEFAULT_PLURAL_FORMS)}}
        js.update({key: values for key, (_, values) in found.items() if messages[key].in_js})
        (languages / f"elementor-pro-{locale}-js.json").write_text(json.dumps(js, ensure_ascii=False), encoding="utf-8")
        print(f"Tradução do PRO Elements {locale}: {len(found)} de {len(messages)} textos")


def cmd_dev(args):
    """Deixa a própria pasta do repositório pronta para rodar num WordPress de teste.

    bundled/ e vendor/ ficam fora do Git (.gitignore). Rode de novo para trocar de versão.
    """
    upstream = read_upstream()
    elementor, pro = args.elementor, args.pro
    if not elementor or not pro:
        latest_elementor, latest_pro = latest_versions()
        elementor = elementor or latest_elementor
        pro = pro or latest_pro

    # Sobrescreve em vez de apagar: a pasta pode estar em uso pelo WordPress de teste.
    # Para trocar de versão do zero, apague bundled/ e vendor/ antes.
    add_upstream_code(ROOT, elementor, pro, upstream["update_checker"])
    print(f"Pronto para desenvolvimento: Elementor {elementor} + PRO Elements {pro}")


def cmd_record(args):
    upstream = read_upstream()
    upstream.update(plugin_version=args.version, elementor=args.elementor, pro_elements=args.pro)
    UPSTREAM_FILE.write_text(json.dumps(upstream, indent=2) + "\n", encoding="utf-8")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)

    check = commands.add_parser("check")
    check.add_argument("--force", action="store_true")
    check.set_defaults(handler=cmd_check)

    dev = commands.add_parser("dev")
    dev.add_argument("--elementor")
    dev.add_argument("--pro")
    dev.set_defaults(handler=cmd_dev)

    for name, handler in (("build", cmd_build), ("record", cmd_record)):
        command = commands.add_parser(name)
        command.add_argument("--version", required=True)
        command.add_argument("--elementor", required=True)
        command.add_argument("--pro", required=True)
        command.set_defaults(handler=handler)

    args = parser.parse_args()
    args.handler(args)


if __name__ == "__main__":
    main()
