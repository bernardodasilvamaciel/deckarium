# MTG Local

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Uso pessoal para pesquisar cartas de Magic e comparar upgrades de decks.

## Product Purpose

Consultar o catálogo local do Scryfall, explorar edições e avaliar cartas que entram e saem de decks. Pesquisa rápida e imagens nítidas são prioridades confirmadas.

## Operating Context

Aplicação local em português, PHP com PostgreSQL via Docker Compose. Navegação em desktop e celular. Metadados sincronizados do Scryfall; imagens armazenadas no disco local.

## Capabilities and Constraints

- Preservar pesquisa por nome/texto Oracle e edição, cartas únicas e todas as impressões, detalhes, edições, upgrades e status.
- Distinguir carta lógica (oracle_id) de impressão (id).
- Baixar todas as imagens disponíveis em tamanho normal, incluindo versos; downloads retomáveis, preservando arquivos existentes.
- Reformular todo o front-end e melhorar o desempenho das páginas.
- Não prometer catálogo integralmente offline antes do download terminar.

## Brand Commitments

Nome MTG Local; interface em português; artes reais das cartas são conteúdo central.

## Evidence on Hand

README.md, app/, database/init.sql e dados locais do Scryfall. Conteúdo real de upgrades já cadastrado.

## Product Principles

- Colocar a pesquisa e os resultados à frente de conteúdo exploratório.
- Manter legibilidade e qualidade das imagens em todos os tamanhos de tela.
- Evitar consultas e transferências redundantes.
- Expor estados vazios, indisponibilidade e progresso com clareza.
