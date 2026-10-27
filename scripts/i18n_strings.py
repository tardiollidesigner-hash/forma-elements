"""Textos traduzíveis: extração do código (PHP e JavaScript), leitura de .po e escrita de .mo.

Chaves seguem o formato do WordPress: "contexto\\x04texto" quando há contexto, senão só o texto.
"""

import json
import re
import struct
from dataclasses import dataclass
from pathlib import Path

# Literal de string PHP/JS, com aspas simples ou duplas.
STR = r"""'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*\""""
# `__(`, `(0,r.__)(` (JS compilado) e afins.
CALL = r"""\b({names})\)?\(\s*"""
ARG = rf"""({STR})\s*,\s*"""
LAST = rf"""({STR})\s*\)"""
NUMBER = r"""[^,()]+?\s*,\s*"""

SINGLE = re.compile(CALL.format(names="__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e") + ARG + LAST)
CONTEXT = re.compile(CALL.format(names="_x|_ex|esc_html_x|esc_attr_x") + ARG + ARG + LAST)
PLURAL = re.compile(CALL.format(names="_n") + ARG + ARG + NUMBER + LAST)
PLURAL_CONTEXT = re.compile(CALL.format(names="_nx") + ARG + ARG + NUMBER + ARG + LAST)

CONTEXT_SEPARATOR = "\x04"

# Fórmulas de plural do gettext por idioma; o resto usa a do inglês.
PLURAL_FORMS = {
    "pt_BR": "nplurals=2; plural=(n > 1);",
    "fr_FR": "nplurals=2; plural=(n > 1);",
}
DEFAULT_PLURAL_FORMS = "nplurals=2; plural=(n != 1);"


@dataclass
class Message:
    key: str
    text: str
    plural: str | None = None
    context: str | None = None
    in_php: bool = False
    in_js: bool = False


def message_key(text, context=None):
    return f"{context}{CONTEXT_SEPARATOR}{text}" if context else text


def unquote(literal):
    body = literal[1:-1]
    if literal[0] == '"':
        try:
            return json.loads(f'"{body}"')
        except ValueError:
            return body.replace('\\"', '"').replace("\\\\", "\\")
    return body.replace("\\'", "'").replace("\\\\", "\\")


def extract(root, domain, skip_dirs=("vendor", "node_modules")):
    """Todos os textos do domínio `domain` em arquivos .php e .js abaixo de `root`."""
    messages = {}

    def add(text, plural, context, kind):
        if not text:
            return
        key = message_key(text, context)
        message = messages.setdefault(key, Message(key, text, plural, context))
        message.plural = message.plural or plural
        setattr(message, kind, True)

    for path in Path(root).rglob("*"):
        if path.suffix not in (".php", ".js") or any(part in skip_dirs for part in path.parts):
            continue
        kind = "in_php" if path.suffix == ".php" else "in_js"
        source = path.read_text(encoding="utf-8", errors="ignore")

        for _, text, dom in SINGLE.findall(source):
            if unquote(dom) == domain:
                add(unquote(text), None, None, kind)
        for _, text, context, dom in CONTEXT.findall(source):
            if unquote(dom) == domain:
                add(unquote(text), None, unquote(context), kind)
        for _, single, plural, dom in PLURAL.findall(source):
            if unquote(dom) == domain:
                add(unquote(single), unquote(plural), None, kind)
        for _, single, plural, context, dom in PLURAL_CONTEXT.findall(source):
            if unquote(dom) == domain:
                add(unquote(single), unquote(plural), unquote(context), kind)

    return messages


def read_po(path):
    """Lê um .po e devolve {chave: [traduções]} (uma por forma de plural). Ignora entradas vazias."""
    entries = {}
    current, field = {}, None

    def flush():
        msgid = current.get("msgid")
        translations = [current[k] for k in sorted(current) if k.startswith("msgstr")]
        if msgid and any(translations):
            entries[message_key(msgid, current.get("msgctxt"))] = translations

    for line in Path(path).read_text(encoding="utf-8").splitlines() + [""]:
        line = line.strip()
        if not line or line.startswith("#"):
            if current and not line:
                flush()
                current, field = {}, None
            continue
        if line.startswith('"'):
            current[field] = current.get(field, "") + json.loads(line)
            continue
        name, _, value = line.partition(" ")
        if name.startswith("msgstr["):
            name = f"msgstr{int(name[7:-1]):02d}"
        if name == "msgid" and "msgid" in current:
            flush()
            current = {}
        field = name
        current[field] = json.loads(value)

    return entries


def write_mo(path, translations, locale):
    """Grava um .mo (formato gettext) com {chave: (texto, plural ou None, [traduções])}."""
    header = (
        "Content-Type: text/plain; charset=UTF-8\n"
        f"Language: {locale}\n"
        f"Plural-Forms: {PLURAL_FORMS.get(locale, DEFAULT_PLURAL_FORMS)}\n"
    )
    pairs = [("", header)]
    for key, (plural, values) in translations.items():
        original = key if plural is None else f"{key}\x00{plural}"
        pairs.append((original, "\x00".join(values)))
    pairs.sort(key=lambda pair: pair[0].encode("utf-8"))

    originals = [o.encode("utf-8") for o, _ in pairs]
    values = [t.encode("utf-8") for _, t in pairs]
    count = len(pairs)
    table_size = 7 * 4 + count * 16
    offsets, data = [], b""
    for blob in originals + values:
        offsets.append((len(blob), table_size + len(data)))
        data += blob + b"\x00"

    output = struct.pack("<7I", 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
    for length, offset in offsets:
        output += struct.pack("<2I", length, offset)
    Path(path).write_bytes(output + data)
