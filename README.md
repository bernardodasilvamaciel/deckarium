# Deckarium — coleção de Magic e oficina de decks

Projeto local para pesquisar cartas, comparar upgrades e manter um cache de imagens sem precisar baixar todas as impressões do Scryfall.

## Mudança principal da v4

O banco continua podendo guardar **todas as impressões** (`default_cards`), mas a aplicação trata duas coisas separadamente:

- **Carta lógica**: identificada pelo `oracle_id`.
- **Impressão**: identificada pelo `id` do Scryfall, com set, collector number e arte próprios.

Assim uma carta como `Command Tower`, que possui muitas reimpressões, continua com todas as versões no PostgreSQL, mas a listagem padrão mostra apenas **uma**. Na página da carta há uma seção **Outras impressões** para trocar de edição.

## Imagens: não baixe tudo

A v4 usa cache sob demanda:

- listagens e upgrades pedem imagem `small`;
- a imagem é baixada somente quando aparece na tela;
- a página de detalhes pede `normal` somente quando você abre aquela carta;
- reprints não são baixados automaticamente.

Isso evita baixar mais de 100 mil imagens normais sem necessidade.

## Iniciar

```bash
cp .env.example .env  # se existir no seu diretório
docker compose up -d --build
```

Site:

```text
http://localhost:8080
```

PostgreSQL:

```text
localhost:5435
```

## Sincronizar dados

Para manter todas as impressões no banco:

```bash
docker compose exec app php bin/sync_scryfall.php default_cards
```

O banco pode ter mais de 100 mil linhas; isso não significa que você precise baixar a imagem de cada linha.

Confira a relação entre impressões e cartas únicas:

```bash
docker compose exec db psql -U mtg -d mtg -c "SELECT count(*) AS impressoes, count(DISTINCT COALESCE(oracle_id,id)) AS cartas_unicas FROM cards;"
```

## Downloader rápido e deduplicado

Novo formato:

```bash
php bin/download_images.php MODO TAMANHO CONCORRENCIA [LIMITE]
```

### Recomendado

Uma imagem pequena por carta lógica, sem repetir reprints, com 8 downloads simultâneos:

```bash
docker compose exec app php bin/download_images.php unique small 8
```

Somente as cartas da página de upgrades:

```bash
docker compose exec app php bin/download_images.php upgrades small 8
```

Primeiras 5000 cartas únicas em tamanho normal:

```bash
docker compose exec app php bin/download_images.php unique normal 8 5000
```

### Não recomendado

Todas as impressões em tamanho normal:

```bash
docker compose exec app php bin/download_images.php all normal 8
```

Esse último modo é justamente o que pode gerar dezenas de milhares de downloads redundantes.

## O que acontece com imagens já baixadas

Nada é perdido. A v4 continua entendendo `local_image` e `local_image_back` do projeto anterior. O novo cache de thumbnails fica em:

```text
storage/images/small/
```

Imagens normais novas ficam em:

```text
storage/images/normal/
```

## Reprints na interface

Na página principal há duas opções:

- **Cartas únicas** — agrupa por `oracle_id`.
- **Todas as impressões** — mostra cada impressão separadamente.

Na página de detalhes, `card.php`, todas as impressões com o mesmo `oracle_id` aparecem em **Outras impressões**. Ao escolher outra edição, o `id` exato daquela impressão é usado e a arte correspondente é cacheada apenas se for aberta.

## Estratégia recomendada

Para este projeto, eu usaria:

1. `default_cards` no PostgreSQL para manter metadados de todas as impressões.
2. Interface em modo `Cartas únicas` por padrão.
3. Cache `small` automático durante a navegação.
4. `normal` apenas na página de detalhes.
5. Downloader `unique small 8` somente se você quiser um cache offline mais abrangente.

Isso preserva a informação completa sobre reprints sem pagar o custo de armazenamento e tempo de baixar a mesma carta dezenas de vezes.

## v5 — downloader com uso constante de memoria

Se `unique small` estourar `memory_limit=256M`, use a versao v5 do `app/bin/download_images.php`.
Ela nao usa `fetchAll()`, nao traz o JSON `raw` inteiro para o PHP e grava os downloads direto em arquivos `.part`.

```bash
docker compose exec app php bin/download_images.php unique small 8
```

Para testar primeiro com 1000 cartas:

```bash
docker compose exec app php bin/download_images.php unique small 8 1000
```

A execucao e retomavel: imagens existentes sao ignoradas.

## v6 — lançamentos recentes e navegação por edição

A página inicial agora possui duas áreas novas:

- **Cards lançados recentemente**: usa a primeira data de lançamento de cada `oracle_id`, então reimpressões antigas em sets novos não ocupam a lista de cartas realmente novas.
- **Edições recentes**: atalhos para os sets mais novos importados no banco.

Também foram adicionadas:

- `http://localhost:8080/editions.php` — edições agrupadas por ano de lançamento, com busca por nome/código.
- `http://localhost:8080/edition.php?set=eoc` — cartas de uma edição específica.

Na página de uma edição, o modo padrão agrupa variantes/reprints internos pelo `oracle_id`. Use **Todas as versões** para ver showcase, borderless e outras impressões separadamente.

Não há alteração de schema nesta versão. Se o projeto já está rodando com os volumes `./app:/var/www/html`, basta substituir/adicionar os arquivos da pasta `app` e atualizar o navegador.

## v6.1 - correção PostgreSQL/PDO
Corrige a consulta da home que usava o operador JSONB `?`. O PDO PostgreSQL pode interpretar esse caractere como placeholder posicional (`$1`) quando `ATTR_EMULATE_PREPARES=false`. A consulta agora usa `jsonb_exists(...)`, evitando o conflito.

## Oficina de decks Commander

Novo módulo em http://localhost:8080/decks.php. Importação de coleção, busca Oracle e seleção manual de cartas. Consulte [o guia do módulo](docs/deck-builder.md).

## Contas e acesso

O Deckarium tem contas de usuário. Qualquer visitante consulta o **catálogo** e as **edições**; **Minha coleção**, **Meus decks** e **Upgrades** exigem login, e cada conta enxerga apenas os próprios dados. **Status** e **Usuários** são exclusivos de administradores (inclusive os endpoints de download e sincronização).

- **Criar conta:** `/register.php` — nome completo, nome de usuário, email e senha (mínimo de 10 caracteres).
- **Entrar:** `/login.php` — aceita usuário ou email; "Manter conectado" guarda a sessão por 30 dias (sem ele, 12 horas de inatividade).
- **Minha conta:** `/account.php` — altera nome, usuário, email e senha. Trocar a senha encerra as outras sessões.
- **Usuários (admin):** `/users.php` — promove/rebaixa administradores, desativa contas e gera senhas temporárias (não há envio de email para recuperar senha).
- **Proteções:** senhas com `password_hash`, sessão regenerada no login, CSRF em todos os formulários, bloqueio de 15 minutos após 8 tentativas erradas e cookies `HttpOnly`/`SameSite=Lax`.

Na primeira requisição após atualizar, o app cria as tabelas `users`, `auth_attempts` e `app_migrations`, cria o administrador definido em `app/auth_bootstrap.php` (arquivo ignorado pelo Git; pode ser apagado depois do primeiro acesso) e transfere a coleção e os decks já existentes para ele. `builder_decks` e `builder_collection` passam a ter `user_id`.

Para rodar os testes de fluxo, informe uma conta: `$env:DECKARIUM_USER='...'; $env:DECKARIUM_PASSWORD='...'; node tests/deck-workflow.mjs`.

## Guia rápido para rodar localmente

Pré-requisitos: Docker Desktop com Compose habilitado e Git. No PowerShell:

~~~powershell
git clone <url-do-repositorio>
cd mtg-local-scryfall
Copy-Item .env.example .env -ErrorAction SilentlyContinue
docker compose up -d --build
~~~

Abra http://localhost:8080. O PostgreSQL fica disponível em localhost:5435 para ferramentas externas; dentro do Compose, o host do banco é db.

Para importar ou atualizar o catálogo Scryfall, abra **Status → Catálogo do Scryfall** e clique em **Baixar atualização**. O download e a importação rodam em segundo plano, com progresso na própria página (log em `storage/sync.log`). Pelo terminal, o comando continua disponível e aparece no mesmo painel:

~~~powershell
docker compose exec app php bin/sync_scryfall.php default_cards
~~~

Depois, em Minha coleção, importe um CSV com as colunas Name,Scryfall ID,Quantity. Em Meus decks, crie um planejamento ou importe uma lista do Moxfield. Escolha a comandante antes de adicionar cartas às candidatas; a seleção segue candidatas → avaliação → deck e finaliza automaticamente em 100 cartas.

Comandos úteis:

~~~powershell
docker compose ps
docker compose logs -f app
docker compose exec app php -l /var/www/html/decks.php
docker compose down
~~~

Os dados do PostgreSQL e as imagens ficam nos volumes/pastas configurados pelo docker-compose.yml. Não use docker compose down -v sem fazer backup, pois isso remove os volumes do banco.

O módulo de decks, sinergias do EDHREC, preços e fluxo de upgrades está documentado em [docs/deck-builder.md](docs/deck-builder.md).
