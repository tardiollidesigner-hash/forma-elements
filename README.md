# Forma Elements

Elementor + PRO Elements em um único plugin, com a identidade Forma UI, tradução automática e atualizações automáticas.

## Como funciona

```
A cada 6 horas o robô (GitHub Actions):
  1. confere se saiu Elementor (wordpress.org) ou PRO Elements (GitHub) novo
  2. se saiu, monta o forma-elements.zip
  3. instala num WordPress descartável em português, com ElementsKit, Royal Addons,
     Essential Addons e Happy Addons, e testa tudo (veja scripts/smoke-test.sh)
  4. só se o teste passar, publica a release vX.Y.Z
Os sites com o Forma Elements veem "Atualização disponível" no painel.
```

Se o teste falhar, nada é publicado, o GitHub manda um e-mail, e o robô tenta de novo na próxima rodada.

## O que o plugin faz

- **Visual Forma UI:** logo "forma" + etiqueta "Elements" no editor e no painel, ícone Forma no menu e na tela de
  carregamento, editor sempre escuro com as cores e a fonte (DM Sans) do UI Kit Forma.
- **Tradução automática:** baixa sozinho a tradução oficial do Elementor (translate.wordpress.org) no idioma do site,
  na ativação e a cada versão nova. O PRO Elements não tem tradução pública, então a tradução dele vem junto no pacote
  (abaixo).
- **Marca:** "Elementor" e "PRO Elements" viram "Forma Elements" nos textos, inclusive nos do editor (JavaScript).
- **Addons:** ElementsKit, Royal Addons, Essential Addons, Happy Addons e outros funcionam normalmente, inclusive os
  que exigem o Elementor pelo cabeçalho "Requires Plugins".

## Tradução do PRO Elements

As traduções ficam em `translations/pro-elements/pt_BR.json` (hoje 2.973 de 2.975 textos; os 2 restantes são
instruções técnicas para assistentes de IA e ficam em inglês de propósito). A cada versão nova, o robô roda
`scripts/translate.py`, que:

1. lista os textos do PRO Elements;
2. mantém o que já está traduzido, então **cada texto é traduzido uma vez só**;
3. copia o que existe igual na tradução oficial do Elementor, sem custo e com a mesma terminologia.

Textos realmente novos ficam em inglês até serem traduzidos. Para conferir quantos faltam, veja a linha
"textos do PRO Elements traduzidos" no log do robô, ou rode o `--export` abaixo.

**Traduzir textos novos sem custo (com o Claude no chat):**

```bash
python scripts/translate.py --elementor <versão> --pro <versão> --export pasta-de-trabalho
```

Isso gera arquivos `faltando-pt_BR-NN.json`. Peça ao Claude para traduzir cada um num `traduzido-pt_BR-NN.json`
(`{"posição": "tradução"}`) e importe:

```bash
python scripts/translate.py --elementor <versão> --pro <versão> --import pasta-de-trabalho/traduzido-pt_BR-*.json
```

O importador descarta traduções que perdem marcadores (`%s`, tags HTML, `{{variáveis}}`). Depois é só dar push:
o robô gera uma versão nova. Também dá pra corrigir qualquer tradução editando o JSON direto.

**Opcional, automático e pago:** com o secret `ANTHROPIC_API_KEY` configurado no GitHub, o robô manda os textos novos
para a API do Claude sozinho (centavos por versão). Sem o secret, nada é cobrado e o robô funciona normalmente.

**Outros idiomas:** acrescente o código do idioma em `ai_locales` no `upstream.json` (ex.: `["pt_BR", "es_ES"]`).

## Estrutura

| Caminho | O que é |
|---|---|
| `forma-elements.php` | Arquivo principal do plugin |
| `includes/loader.php` | Carrega o Elementor e o PRO Elements do pacote e bloqueia conflito com o Elementor oficial |
| `includes/compat.php` | Compatibilidade com addons |
| `includes/branding.php` | Troca a marca nos textos da interface (PHP e JavaScript) |
| `includes/ui.php` + `assets/` | Visual Forma UI (logo, cores, fonte, editor escuro) |
| `includes/i18n.php` | Tradução automática |
| `includes/updater.php` | Atualização automática pelas releases do GitHub |
| `scripts/build.py` | Monta o pacote (`check`, `dev`, `build`, `record`) |
| `scripts/translate.py` + `scripts/i18n_strings.py` | Tradução do PRO Elements com IA |
| `translations/pro-elements/` | Traduções já feitas (o robô atualiza) |
| `scripts/smoke-test.sh` | Teste automático num WordPress descartável |
| `upstream.json` | Versões que estão na última release publicada |
| `.github/workflows/release.yml` | O robô |

O código do Elementor e do PRO Elements **não fica no repositório**: ele é baixado na hora do build e vai para
`bundled/` dentro do zip.

## Tarefas comuns

- **Publicar uma versão agora:** aba *Actions* → *Release* → *Run workflow* → marque *force*.
- **Mudar o código do Forma Elements:** é só dar push na `main`. O robô gera uma versão nova com a mudança.
- **Testar no seu computador:** `python scripts/build.py dev` baixa o Elementor e o PRO Elements para dentro da
  pasta (fora do Git). Aí a própria pasta funciona como plugin num WordPress local.
- **Montar o zip no seu computador:** `python scripts/build.py build --version 1.0.0 --elementor 4.3.5 --pro 4.3.2`
  (o resultado fica em `dist/forma-elements.zip`).

## Migrando um site que já usa Elementor

1. Desative o Elementor e o PRO Elements (ou o Elementor Pro). **Não exclua antes de testar.**
2. Instale e ative o Forma Elements. As páginas continuam as mesmas, porque os dados são os mesmos.
3. Depois de conferir o site, pode excluir os plugins antigos.

Se o Elementor oficial estiver ativo, o Forma Elements fica pausado e mostra um aviso, em vez de derrubar o site.

## Limitações conhecidas

- A tela inicial do construtor ("Início rápido") mostra conteúdo que vem do servidor do Elementor, só em inglês.
- Textos novos do PRO Elements (de versões futuras) ficam em inglês até serem traduzidos (veja "Tradução do PRO Elements").
- Avisos promocionais do Elementor (ex.: IA "Angie") ainda aparecem.
- Com muitos addons juntos, o editor precisa de pelo menos 512 MB de memória no PHP (limitação do próprio Elementor).
- Os serviços em nuvem do Elementor Pro (kits Pro, biblioteca Pro) não fazem parte do PRO Elements.

## Licença

GPL-3.0-or-later. Inclui o Elementor (© Elementor Ltd.) e o PRO Elements (© PROElements.org), ambos GPL. A única
alteração no código deles é o atualizador próprio do PRO Elements (`updater/updater.php`), trocado por uma classe
vazia no build. Fonte DM Sans sob a licença SIL OFL 1.1 (`assets/fonts/OFL.txt`). "Elementor" é marca registrada
da Elementor Ltd. e não é usada no nome deste produto.
