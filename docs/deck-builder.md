# Deckarium — oficina de decks

Acesse `http://localhost:8080/decks.php` ou **Meus decks** no menu. O módulo funciona no PHP/PostgreSQL existente, sem serviço de IA ou dados de popularidade.

## Fluxo

1. Importe a exportação completa CSV do ManaBox, com `Name`, `Scryfall ID` e `Quantity`. A importação substitui as quantidades da coleção, preservando os decks; arquivos inválidos preservam a coleção anterior. Impressões desconhecidas são armazenadas e sinalizadas.
2. Crie um deck, escolha seu comandante e registre a estratégia e os termos que deseja explorar.
3. Busque palavras ou frases literais do Oracle em inglês, separadas por ponto e vírgula: `sacrifice; land; graveyard`. **Todos os termos** usa AND; **Qualquer termo** usa OR. Ambas as faces são pesquisadas. Combine com nome, tipo, identidade de cor e **Só minha coleção**.
4. Adicione cartas às candidatas e mova-as entre candidatas, em avaliação e deck. Você define quantidade, função e observações.
5. Consulte a composição e exporte o deck ou as cópias faltantes. Somente o comandante e as cartas na etapa final entram na lista de compras; qualquer impressão da mesma carta conta como disponível.

## Limites desta versão

**Tipos e temas** aceita vários termos separados por ponto e vírgula, com correspondência em qualquer um deles no tipo ou no Oracle. Por exemplo, `pirate; assassin; vehicle; treasure` encontra esses tipos e cartas que mencionam esses temas, incluindo criação de Tesouros. Esse grupo é combinado com os filtros de Oracle, coleção e identidade. Para consultar suas impressões e quantidades fora de um deck, use **Minha coleção** no menu.

- Um comandante por deck; parceiros e Backgrounds ainda não são modelados.
- As correspondências são textuais e explicadas pelos termos encontrados. Não inferem sinergia ou interpretam a estratégia escrita.
- Funções são classificações manuais. A contagem de símbolos de mana é descritiva, não determina uma base de terrenos ideal.
- Há alertas básicos de identidade e duplicidade, sem validação completa de legalidade ou banimentos.
- Cópias usadas em outros decks não são reservadas; preços do CSV não são usados como cotação atual.

A seleção de comandantes considera a face frontal e inclui as criaturas lendárias, permissões explícitas no Oracle e veículos/espaçonaves lendários com poder e resistência, conforme o [boletim oficial de Edge of Eternities](https://magic.wizards.com/en/news/announcements/edge-of-eternities-update-bulletin).

## Persistência e teste

As tabelas `builder_decks`, `builder_items` e `builder_collection` são criadas automaticamente na primeira abertura. Faça backup delas junto com o banco. Importar a coleção não modifica o catálogo Scryfall.

Com o app em execução e a coleção de exemplo importada:

```powershell
node tests/deck-workflow.mjs
```

O teste HTTP cria e remove seu próprio deck temporário e verifica comandante, Oracle AND/OR, filtros, etapas, quantidades, exportação, compras e CSRF. Usa Hearthhull e Fabled Passage do catálogo/coleção do projeto. Para importar explicitamente um CSV completo antes do teste, passe seu caminho como argumento; isso substitui a coleção atual.
