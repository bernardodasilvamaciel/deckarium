# Deckarium — oficina de decks

Acesse `http://localhost:8080/decks.php` ou **Meus decks** no menu. O módulo funciona no PHP/PostgreSQL existente e mantém os dados externos de recomendação em cache.

## Fluxo

1. Importe a exportação completa CSV do ManaBox, com `Name`, `Scryfall ID`, `Quantity` e, quando disponível, `Foil`. A importação mantém versões normais e foil separadas, substitui as quantidades da coleção e preserva os decks; arquivos inválidos preservam a coleção anterior. Impressões desconhecidas são armazenadas e sinalizadas.
2. Planeje um deck do zero ou cole uma exportação textual do Moxfield. Cabeçalhos `Commander` e `Deck` são reconhecidos; listas simples no formato `1 Nome da carta` também funcionam. A importação escolhe primeiro a impressão exata presente na coleção.
3. Ao criar um deck, escolha imediatamente uma comandante na lista. A mesma busca e os mesmos filtros usados depois para explorar o catálogo já funcionam nessa etapa; apenas a ordenação por sinergia fica indisponível até existir uma comandante de referência.
4. Depois da escolha, registre a estratégia e busque palavras ou frases literais do Oracle em inglês, separadas por ponto e vírgula: `sacrifice; land; graveyard`. **Todos os termos** usa AND; **Qualquer termo** usa OR. Ambas as faces são pesquisadas. Combine nome, tipo, disponibilidade, identidade de cor, raridade, edição e custo no mesmo painel.
5. Adicione cartas às candidatas e aprove-as para o deck (ou devolva-as às candidatas). Você define quantidade, função e observações. A edição expandida mostra imagem, tipo, custo e texto Oracle.
6. A lista é finalizada automaticamente quando comandante + cartas aprovadas chegam a 100 cartas. Cada troca é planejada dentro da seleção, relacionando uma carta do deck com qualquer impressão do catálogo e indicando quando ela está disponível na coleção.
7. Consulte a composição e o valor estimado. Exporte o deck em texto, as cópias faltantes ou um CSV no padrão da Liga para pesquisar preços do deck completo ou somente do que falta.

## Recomendações do EDHREC

O módulo é dividido em **Biblioteca**, **Comandante e descobertas** e **Minha seleção**. Na biblioteca, cada deck tem exclusão com confirmação, sem apagar cartas da coleção. A seleção tem duas abas: **Candidatas** (as cartas guardadas para comparar, com notas e aprovação — a antiga etapa “Em avaliação” foi unificada a ela e os itens antigos migram sozinhos) e **No deck**, ambas agrupadas por tipo. Mover uma carta entre etapas preserva quantidade, função e observações. Em qualquer aba, **Selecionar várias** ativa a seleção múltipla: clicar numa carta passa a marcá-la, cada tipo ganha “Marcar …” e a barra fixa oferece marcar todas, marcar as de nota Avançar e mover tudo de uma vez (candidatas → deck ou de volta). O servidor aplica as mesmas regras do mover individual na ordem da tela: cartas não básicas com mais de 1 cópia não entram no deck e, quando as vagas acabam, o restante fica onde estava com um aviso.

O painel de exploração combina todos os filtros e usa **Ordenar resultados** para alternar entre sinergia, relevância, nome, novidade e disponibilidade. **Disponibilidade** permite mostrar tudo, somente a coleção ou somente o que falta. Os resultados são paginados em grupos de 24 e deduplicados pela identidade Oracle. Outra impressão da mesma carta conta como propriedade e como seleção já existente. Quando possível, a impressão da coleção é mostrada; fora dela, uma impressão física tem preferência. Cada resultado permite adicionar a carta às candidatas.

Com um comandante definido, **Atualizar do EDHREC** busca recomendações sob demanda e grava métrica, fonte e data no cache local. O sistema preserva se o valor recebido é `synergy` ou `lift`, pois as escalas não são equivalentes. Esses números representam associação e popularidade entre listas, não uma avaliação objetiva de força. Se a rede ou o EDHREC estiver indisponível, a cache anterior é preservada e o restante do construtor continua funcionando.

Além da ordenação por sinergia, o painel apresenta planos prováveis para o comandante (sacrifício, fichas, cemitério, ramp, blink, Voltron, controle e tribal), com cartas específicas filtradas pela identidade e pela coleção. Combos compatíveis conhecidos são mostrados quando todas as peças existem no catálogo, com indicação de quais estão disponíveis. O selo **GC** identifica a lista curada local de Game Changers e aparece na exploração, nos pacotes, nos combos e na seleção.

## Limites desta versão

**Tipo de carta** (Criatura, Instantânea, Feitiço, Artefato, Encantamento, Planeswalker, Terreno) filtra pelo tipo impresso em qualquer face; marcar vários mostra cartas de qualquer um deles, e o filtro é preservado ao adicionar candidatas e ao paginar. **Tipos e temas** aceita vários termos separados por ponto e vírgula, com correspondência em qualquer um deles no tipo ou no Oracle. Por exemplo, `pirate; assassin; vehicle; treasure` encontra esses tipos e cartas que mencionam esses temas, incluindo criação de Tesouros. Esse grupo é combinado com os filtros de Oracle, coleção e identidade. Para consultar suas impressões e quantidades fora de um deck, use **Minha coleção** no menu.

- Um comandante por deck; parceiros e Backgrounds ainda não são modelados.
- A busca local é textual e explicada pelos termos encontrados. As recomendações externas não interpretam a estratégia escrita.
- Funções são classificações manuais. A contagem de símbolos de mana é descritiva, não determina uma base de terrenos ideal.
- Há alertas básicos de identidade e duplicidade, sem validação completa de legalidade ou banimentos.
- Cópias usadas em outros decks não são reservadas; preços do CSV não são usados como cotação atual.
- Preços são os valores USD/EUR da impressão no Scryfall convertidos para BRL. A conversão usa \`USD_BRL_RATE\` (padrão 5,50) ou \`EUR_BRL_RATE\` (padrão 6,00), configuráveis no ambiente do app; “Preço indisponível” significa que aquela impressão não possui cotação.

A seleção de comandantes considera a face frontal e inclui as criaturas lendárias, permissões explícitas no Oracle e veículos/espaçonaves lendários com poder e resistência, conforme o [boletim oficial de Edge of Eternities](https://magic.wizards.com/en/news/announcements/edge-of-eternities-update-bulletin).

## Guia da comandante

Ao escolher uma comandante, o Deckarium consulta o EDHREC (se a cache tiver mais de 7 dias) e mostra um guia com quatro abas:

- **Planos de jogo:** temas mais jogados com a comandante e quantos decks os usam, completados pela leitura local do texto Oracle. Cada plano mostra cartas do catálogo na identidade, quantas estão na coleção e um atalho para explorar o plano.
- **Combos:** combos populares do EDHREC/Commander Spellbook compatíveis com a identidade de cor, com resultado, pré-requisitos, passo a passo, uso em decks e bracket; em seguida, os combos do catálogo interno.
- **Mecânicas:** habilidades da própria comandante e mecânicas lançadas nos últimos 24 meses que têm cartas na identidade, com explicação em português e o texto de lembrete do Oracle.
- **Novidades:** cartas novas que já aparecem nas listas da comandante (com botão para adicionar às candidatas) e comandantes parecidas.

Os dados ficam em `deck_commander_insights` e são atualizados pelo botão “Atualizar” do guia. `EDHREC_JSON_BASE` permite apontar para outro endereço em testes. Na busca, “Somente identidade da comandante” vem marcado por padrão depois que a comandante é escolhida.

## Índice de Encaixe

Na Minha seleção, cada carta recebe uma nota de 0 a 100 (`app/deck_scoring.php`):

`nota = 100 × regras × Σ(peso × componente) ÷ Σ(pesos) + bônus`

- **Encaixe com a comandante / Conexões com o deck:** o texto Oracle vira características "produz" (cria Tesouro, sacrifica, põe +1/+1…) e "se importa com" (sempre que uma criatura morrer, Piratas que você controla…). A pontuação soma os pares produz×procura nos dois sentidos, tribos (mais raras valem mais), palavras-chave e termos raros em comum (raridade calculada sobre o catálogo).
- **Função que falta:** ramp, compra, remoção pontual, remoção em massa, proteção, recursão, tutores, terrenos e plano de jogo, comparados com as metas do deck (só cartas no deck contam; as candidatas com a função são mostradas à parte).
- **Curva, exigência de cor** (para terrenos: cores da identidade que produzem e se entram virados), **intenção** (termos de "Minha intenção"), **EDHREC** (sinergia, inclusão, popularidade), **coleção** e **orçamento**.
- **Cartas de função** (terrenos, ramp, compra, remoção…) não dependem de sinergia: o peso dela cai e o da função e do EDHREC sobe, voltando ao normal quanto mais sinergia a carta tiver.
- **Regras:** fora da identidade, banida ou cópia repetida = bloqueada. Conforme o bracket: Game Changers (0 nos brackets 1–2, até 3 no 3), destruição de terrenos em massa (só bracket 4+), turnos extras e combos infinitos de duas cartas viram multiplicadores; combos conhecidos dão bônus.
- **Faixas:** Avançar (70+), Avaliar (45+), Segurar, Bloqueada — limites configuráveis.

"Ajustar fórmula" abre presets (Equilibrado, Sinergia primeiro, Base sólida, Coleção e orçamento, Otimizado), pesos, metas de função e curva, bracket, preço máximo e limites, com prévia ao vivo. A configuração fica em `builder_decks.scoring_config`. "Aplicar sugestões" aprova para o deck as candidatas com nota de avanço, até as vagas abertas e respeitando o limite de Game Changers.

## O que o deck precisa

Em **Comandante e descobertas**, logo após o guia, o painel compara as cartas aprovadas com as metas de função da fórmula (terrenos, ramp, compra, remoção pontual, remoção em massa, proteção, recursão, tutores). Cada função mostra quanto falta, quantas candidatas já a cumprem e seis cartas do catálogo na identidade da comandante que ainda não estão na seleção — primeiro por sinergia EDHREC, depois pela popularidade — com botão de adicionar. “Ver todas” abre o Explorar com o filtro **Função no deck**. O índice de funções (`deckNeedRoleIndex`, mesmas regex do Índice de Encaixe rodando no PostgreSQL) é gerado uma vez por sincronização do catálogo (~5 s) e aquecido ao escolher a comandante. Na Minha seleção fica só um link para esse painel.

## Exportação JSON

“Exportar deck em JSON (completo)” (`?export=json`) baixa `deckarium-<deck>-<id>.json` com o deck (estratégia, contagens, curva, símbolos, alertas, lista de compras), a fórmula e as metas, a comandante e todas as cartas do deck e das candidatas. Cada carta traz etapa, quantidade, função e notas; coleção (total, esta impressão, normal/foil, uso em outros decks, faltando); preço em R$; Game Changer; imagens; sinergia EDHREC; Índice de Encaixe com a decomposição; e o registro completo do Scryfall (`scryfall.raw`). Upgrades pendentes vão em `upgrades`.

## Persistência e teste

As tabelas `builder_decks`, `builder_items`, `builder_collection`, `deck_upgrades`, `deck_synergy` e `deck_commander_insights` são criadas automaticamente na primeira abertura. Faça backup delas junto com o banco. Importar a coleção não modifica o catálogo Scryfall.

Com o app em execução e a coleção de exemplo importada:

```powershell
node tests/deck-workflow.mjs
node tests/upgrades-workflow.mjs
node tests/deck-synergy-workflow.mjs
```

Os testes HTTP criam e removem seus próprios decks temporários. Eles verificam comandante, Oracle AND/OR, filtros, etapas, quantidades, exportação, compras, importação textual, escolha da impressão da coleção, registro de upgrade e CSRF. Para importar explicitamente um CSV completo antes do primeiro teste, passe seu caminho como argumento; isso substitui a coleção atual.

O teste de sinergia requer recomendações de Edward Kenway já atualizadas no cache local; ele não chama o EDHREC nem altera a coleção.
