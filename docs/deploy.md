# Deploy (CI/CD com runner self-hosted)

O deploy roda quando uma **tag começando com `v`** é enviada ao GitHub:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Também dá para rodar manualmente em **Actions > Deploy > Run workflow**, escolhendo a tag em *Use workflow from*
(execuções a partir de branches são ignoradas).

No servidor, o workflow faz:

1. checkout da tag;
2. build da imagem `deckarium-app` (código embutido);
3. cria as pastas de dados em `/opt/apps/deckarium/data/storage` e ajusta o dono das pastas de topo;
4. `php -l` em todos os arquivos PHP dentro da imagem nova;
5. aplica `database/init.sql` e `database/performance.sql` no **PostgreSQL do próprio servidor** (scripts idempotentes);
6. `docker compose -f docker-compose.prod.yml up -d --wait` — só troca o container se tudo acima passou;
7. health check em `http://127.0.0.1:8000/`.

O container usa a **rede do host** (`network_mode: host`): o Apache escuta **somente em 127.0.0.1:8000**, para o
cloudflared, e o banco é acessado em `127.0.0.1:5432`, sem mexer em `listen_addresses`/`pg_hba.conf`.
Esse ambiente é separado do `docker-compose.yml` de desenvolvimento (porta 8080).

## Layout no servidor

| Caminho | Conteúdo | Dono |
|---|---|---|
| `/opt/deploy/actions-runner` | runner do GitHub Actions | `deploy` |
| `/opt/apps/deckarium/.env` | senhas e configuração | `deploy` (600) |
| `/opt/apps/deckarium/auth_bootstrap.php` | admin inicial (opcional) | `deploy` (600) |
| `/opt/apps/deckarium/data/storage` | bulk do Scryfall e imagens (~10 GB) | `www-data` (uid 33), ajustado pelo deploy |
| PostgreSQL do host | banco `POSTGRES_DB`, usuário `POSTGRES_USER` | já existente |
| `/opt/docker` | imagens, cache de build e camadas do Docker (recomendado, ver passo 5) | `root` |

A pasta de dados pode ser trocada com `DECKARIUM_DATA_DIR=/outro/caminho` no `.env`; a pasta do `.env`, com a
variável de repositório `DEPLOY_DIR` (Settings > Secrets and variables > Actions > Variables).

## 1. Usuário `deploy`

```bash
getent passwd deploy                          # confirme que existe e tem home
sudo usermod -aG docker deploy
sudo -u deploy docker ps                      # precisa funcionar sem erro de permissão
sudo passwd -l deploy                         # sem login por senha
```

> Estar no grupo `docker` equivale a acesso root na máquina. Não dê sudo ao `deploy`.

## 2. Registrar o runner com o usuário `deploy`

```bash
sudo mkdir -p /opt/deploy/actions-runner
sudo chown -R deploy:deploy /opt/deploy
sudo -iu deploy
cd /opt/deploy/actions-runner
```

Em **Settings > Actions > Runners > New self-hosted runner > Linux**, copie os comandos de *Download* e
*Configure* e rode-os ali, como `deploy`. Depois `exit` para voltar ao administrador.

Se já existia um runner registrado com outro usuário, remova antes com
`./config.sh remove --token <TOKEN>` (token em Settings > Actions > Runners > (runner) > Remove).

## 3. Rodar o runner como serviço (segundo plano)

```bash
cd /opt/deploy/actions-runner
sudo ./svc.sh install deploy
sudo ./svc.sh start
sudo ./svc.sh status
journalctl -u 'actions.runner.*' -f           # logs
```

No GitHub o runner deve aparecer como **Idle**. Se mudar grupos do `deploy`, reinicie:
`sudo ./svc.sh stop && sudo ./svc.sh start`. Requer Compose v2.17+ (`docker compose version`).

## 4. Pasta de configuração

```bash
sudo mkdir -p /opt/apps/deckarium
sudo nano /opt/apps/deckarium/.env
```

Conteúdo mínimo:

```dotenv
# Banco no PostgreSQL do servidor (já criado)
POSTGRES_DB=mtg
POSTGRES_USER=mtg
POSTGRES_PASSWORD=senha_do_usuario_no_postgres
# DB_HOST=127.0.0.1
# DB_PORT=5432

SCRYFALL_BULK_TYPE=default_cards
SCRYFALL_USER_AGENT=Deckarium/1.0
# DECKARIUM_DATA_DIR=/opt/apps/deckarium/data
# APP_LISTEN=127.0.0.1:8000
```

### Permissões no PostgreSQL

O deploy cria/atualiza as tabelas com o usuário do `.env`. Ele precisa ser dono do banco (no PostgreSQL 15+ o
schema `public` não aceita `CREATE` de outros usuários) e a extensão `pg_trgm` precisa estar disponível
(Debian/Ubuntu: pacote `postgresql-contrib`, já incluso nas versões recentes). Confira uma vez:

```bash
sudo -u postgres psql -c "ALTER DATABASE mtg OWNER TO mtg;"
sudo -u postgres psql -d mtg -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
psql "postgresql://mtg@127.0.0.1:5432/mtg" -c "select current_user, version();"   # pede a senha
```

O último comando precisa conectar por **TCP em 127.0.0.1 com senha**, que é como o container conecta. Se falhar
com `Peer`/`ident`, ajuste o `pg_hba.conf` para `host mtg mtg 127.0.0.1/32 scram-sha-256` e recarregue
(`sudo systemctl reload postgresql`).

```bash
sudo cp auth_bootstrap.php /opt/apps/deckarium/   # opcional: lido só com a tabela users vazia
cd /opt/apps/deckarium
sudo chown deploy:deploy . .env && sudo chmod 700 . && sudo chmod 600 .env
[ -f auth_bootstrap.php ] && sudo chown deploy:deploy auth_bootstrap.php && sudo chmod 600 auth_bootstrap.php
# não use chown -R aqui: data/storage precisa continuar com o www-data
```

## 5. Mover o armazenamento do Docker para /opt (recomendado)

Os volumes do Deckarium já ficam em `/opt`, mas imagens, camadas e cache de build do Docker ficam por padrão
em `/var/lib/docker`, na partição `/` (28 GB). Para mover tudo para `/opt`:

```bash
cat /etc/docker/daemon.json 2>/dev/null        # se já existir, adicione a chave em vez de sobrescrever
sudo systemctl stop docker docker.socket       # para TODOS os containers da máquina
sudo mkdir -p /opt/docker
sudo rsync -aHAX --info=progress2 /var/lib/docker/ /opt/docker/
echo '{ "data-root": "/opt/docker" }' | sudo tee /etc/docker/daemon.json
sudo systemctl start docker
docker info --format '{{.DockerRootDir}}'      # deve mostrar /opt/docker
docker ps -a                                   # confira que tudo voltou
```

Depois de confirmar que tudo funciona: `sudo mv /var/lib/docker /var/lib/docker.old` e, dias depois,
`sudo rm -rf /var/lib/docker.old`.

## 6. Primeiro deploy e carga de dados

Crie e envie uma tag (`git tag v1.0.0 && git push origin v1.0.0`). Depois, no servidor:

```bash
docker exec -it deckarium-app php bin/sync_scryfall.php default_cards
docker exec -it deckarium-app php bin/download_images.php unique small 8
```

## 7. Cloudflared

Aponte o tunnel para `http://localhost:8000`:

```yaml
tunnel: <ID-DO-TUNNEL>
credentials-file: /root/.cloudflared/<ID-DO-TUNNEL>.json
ingress:
  - hostname: deckarium.seudominio.com
    service: http://localhost:8000
  - service: http_status:404
```

Instale como serviço: `sudo cloudflared service install`.

> Se o cloudflared rodar **dentro de um container**, use `network_mode: host` nele.

## Operação

```bash
docker ps --filter name=deckarium
docker logs -f deckarium-app
sudo -u postgres pg_dump mtg | gzip > /opt/apps/deckarium/backup-$(date +%F).sql.gz
du -sh /opt/apps/deckarium/data/*
```

Não apague `/opt/apps/deckarium/data`: é onde ficam o bulk e as imagens.
