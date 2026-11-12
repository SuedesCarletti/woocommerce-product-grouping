# Agrupamento de Produtos por Cor/Voltagem

Plugin WooCommerce que vincula produtos que são a mesma peça em cores/voltagens
diferentes, sem migrar o catálogo para produto variável nativo. Cada produto
continua sendo um post/SKU/estoque independente — o plugin só adiciona um
vínculo entre eles.

## O que já está pronto (esta fase)

- **Taxonomia** `ec_grupo_variacao` (não pública, uso interno): cada grupo é
  1 termo; um produto pertence a no máximo 1 grupo.
  `includes/class-ec-agrupamento-taxonomy.php`
- **Admin**: caixa "Grupo de Variações (Cor/Voltagem)" na edição do produto
  (WooCommerce → Produtos → editar), com busca/autocomplete para vincular,
  lista dos produtos do grupo com botão "Remover do grupo", e avisos
  automáticos de (a) combinação cor+voltagem repetida no grupo e (b) produto
  do grupo sem cor nem voltagem preenchida.
  `includes/class-ec-agrupamento-admin.php`
- **Frontend**: seletor de cor (bolinhas com cor real) e voltagem (botões)
  na página do produto, com navegação para a combinação correspondente e
  ocultação de combinações inexistentes no grupo.
  `includes/class-ec-agrupamento-frontend.php` → `render_selector()`
- **Utilitário de listagem**: `EC_Agrupamento_Frontend::dedupe_by_group($ids)`
  recebe uma lista de IDs de produto e devolve 1 só por grupo (o de maior
  estoque, desempate por `total_sales`). `get_group_color_dots($product_id)`
  devolve os dados para desenhar os pontinhos de cor no card.

Por padrão o seletor da página de produto é enganchado em
`woocommerce_single_product_summary` (prioridade 11, logo após o preço), o
que funciona em temas WooCommerce que usam os hooks padrão. No tema
`ecomall-child` (templates customizados) a integração já foi feita à mão —
ver o README daquele repositório.

## Nomenclatura do atributo cor/voltagem

O catálogo mistura produtos cadastrados com o atributo em português (`cor`,
`voltagem`, valores como "Branco"/"220V") e produtos cadastrados com a
nomenclatura padrão do WooCommerce em inglês (`color`, `voltage`, valores
como "White"/"220V"). Por isso `EC_Agrupamento_Taxonomy::get_cor($product)` e
`::get_voltagem($product)` tentam os dois nomes de atributo, nessa ordem,
antes de considerar o produto sem essa informação — e o mapa de cor→hex
(`get_color_hex_map()`) já traz as duas nomenclaturas. Pra adicionar outro
idioma/nome (ex: um attribute "colour" britânico), use os filtros
`ec_agrupamento_cor_attribute_names` / `ec_agrupamento_voltagem_attribute_names`
(cada um recebe/retorna um array de nomes candidatos).

**Atenção ao cadastrar produtos**: o atributo precisa ser adicionado na aba
"Atributos" do produto (global ou customizado, com um dos nomes acima) e
salvo com o botão **"Salvar atributos"** daquela aba antes de atualizar o
produto — esse passo é separado do botão de atualizar o produto e é fácil de
esquecer.

## Fora de escopo desta fase

- Migração para produto variável nativo do WooCommerce.
- Estoque/preço combinado entre variações do grupo.
- Importação em massa de grupos via CSV.
