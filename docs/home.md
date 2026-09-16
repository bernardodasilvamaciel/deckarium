# Página inicial

## Entrada e navegação

`app/index.php` apresenta a página inicial em `/` quando não há filtros ativos nem parâmetros `catalog`, `view` ou `page`. A navegação marca **Início** como página atual. A busca envia `q` para a mesma rota; os atalhos para Dragões, Lendárias e catálogo completo levam à listagem existente. `/?catalog=1#catalogo` abre o catálogo com paginação de 36 cartas.

A inicial inclui busca, escultura interativa, links para coleção/decks/status, edições recentes e uma prévia de até **seis cartas únicas**, seguida do link para o catálogo completo. Os filtros e a alternância entre cartas únicas e impressões continuam disponíveis. `home.php` é um fragmento incluído pelo controlador, não uma rota independente.

## Dados reais

As cartas e edições vêm da tabela PostgreSQL `cards`, sem números demonstrativos. A prévia usa a mesma seleção do catálogo: agrupa por `COALESCE(oracle_id,id)`, escolhe a impressão mais recente e desempata por idioma inglês, presença de imagem e identificador. Ordena os resultados por lançamento, nome e identificador.

As quatro edições recentes são agrupadas por `set_code`, considerando registros com data de lançamento até a data atual. Exibem nome, código, data e quantidade de cartas únicas. As consultas de resumo usam `catalogCached`, com validade padrão de 300 segundos e revisão da sincronização na chave. Um acervo vazio mostra os estados vazios existentes e um caminho para consultar o status; a página não promete que todas as imagens estejam disponíveis offline.

## Escultura e procedência

`app/assets/dragon.js` constrói uma escultura procedural de dragão, com materiais de cobre e ouro, asas, chifres, cauda e um pequeno tesouro. É um estudo estilizado inspirado na referência de Smaug fornecida pelo usuário; **não é um modelo oficial nem uma reconstrução realista**. A geometria é gerada no código, sem arquivo de modelo externo.

O renderizador usa **Three.js 0.170.0**, distribuído localmente em `app/assets/vendor/three.module.min.js`. A licença MIT dos autores do Three.js está em `app/assets/vendor/three.LICENSE`. Não há dependência de CDN em tempo de execução para o 3D.

O raster novo `app/assets/smaug-reference.png` é a referência de carta fornecida pelo usuário e serve como imagem estática de fallback. Foi copiado do PNG anexado, preservando os pixels, e recebeu metadados de procedência pelo utilitário `embed-prompt` do Impeccable. Não é uma imagem criada por IA nem arte original do projeto; a procedência do arquivo não transfere os direitos da arte da carta. As cartas do catálogo continuam usando o fluxo existente de dados e imagens do Scryfall.

Sem JavaScript, durante a inicialização ou se o módulo/WebGL falhar, a referência permanece visível. Uma falha de inicialização atualiza a mensagem de status; a perda do contexto WebGL também restaura a referência e orienta recarregar. Busca e navegação independem do renderizador.

## Acessibilidade e movimento

- A busca tem rótulo visível; o HTML mantém títulos, regiões e navegação com nomes acessíveis.
- A imagem de referência tem texto alternativo. O canvas é ocultado da árvore de acessibilidade, e o estágio mantém uma descrição textual da escultura.
- Os botões de girar e pausar são controles HTML nativos: navegue com Tab e ative com Enter ou Espaço. Girar por botão também pausa a rotação automática. O foco usa o tratamento global existente.
- **Pausar/Animar** informa o estado por texto e `aria-pressed`. Arrastar com ponteiro gira o modelo; o canvas permite rolagem vertical por toque.
- `prefers-reduced-motion: reduce` inicia o modelo parado e mudanças dessa preferência são observadas. O usuário pode iniciar a animação explicitamente.
- A animação automática para quando o estágio sai da área visível ou a aba fica oculta. O tamanho acompanha o contêiner e a densidade de pixels é limitada a 1,5.
- Em telas de até 760px, texto e escultura ficam em uma coluna; atalhos viram linhas e edições permanecem em duas colunas.

## Verificação

Com Docker Compose instalado, inicie a aplicação e o banco em `http://localhost:8080`:

```powershell
docker compose up -d --build
```

O teste requer Node.js, Playwright com `chromium`, Google Chrome instalado e acervo sincronizado com pelo menos 36 cartas únicas, incluindo resultados para Smaug e Dragon. Se Playwright estiver fora da resolução padrão do Node, aponte `NODE_PATH` para a pasta `node_modules` que o contém.

```powershell
New-Item -ItemType Directory -Force .impeccable/review | Out-Null
node tests/home-smoke.cjs
```

`tests/home-smoke.cjs` verifica inicialização do 3D, estado inicial com movimento reduzido, botões, seis cartas na inicial, busca, catálogo com 36 cartas, filtro de dragões, indicação da navegação atual, ausência de overflow horizontal a 390px e fallback quando o módulo Three.js é bloqueado. Também registra erros JavaScript da página principal. Salva capturas desktop, viewport desktop e mobile em `.impeccable/review/`.

Complemente o smoke test com inspeção visual das capturas e navegação por teclado. Mudanças de preferência de movimento, pausa ao ocultar a aba/sair do viewport e perda real de contexto WebGL são comportamentos implementados, mas não são verificações automatizadas desse teste.

## Escopo de design

Esta é uma extensão da interface existente: reutiliza papel marfim, controles verdes e tipografia Spectral. Cobre, ouro e palco escuro pertencem à escultura. `home.css` e o módulo 3D são carregados somente na inicial. A direção da superfície está em `.impeccable/surfaces/app-index-php.md`; esta documentação não altera seu contrato nem os tokens globais.

Existe uma divergência de nomenclatura anterior a esta extensão: `PRODUCT.md` e `DESIGN.md` dizem **MTG Local**, enquanto README, interface e brief da superfície usam **Deckarium**. Foi registrada sem renomear o produto ou reescrever os arquivos globais de design.
