# Sistema de Auditorias - Checklist Operacional

Aplicacao web em PHP para criacao e acompanhamento de auditorias internas com checklist em formato Kanban, controle de prazos, responsaveis e anexos em PDF.

## 1. Objetivo do projeto

Este projeto tem como objetivo centralizar a gestao de auditorias operacionais de forma simples e visual, permitindo:

- criar auditorias por linha/cliente;
- gerar checklist automaticamente a partir de templates;
- acompanhar o status de cada item em quadro Kanban;
- controlar prazo e prioridade dos itens;
- registrar observacoes e anexar PDF por item;
- finalizar auditorias quando todos os itens estiverem concluidos.

## 2. Aviso importante sobre os itens do SQL

Os itens presentes nos arquivos SQL (especialmente em `database/schema.sql` e `database/update_templates.sql`) sao **apenas exemplos de contexto industrial**.

Esses templates servem como base inicial para demonstracao e aceleracao do cadastro, mas:

- podem e devem ser adaptados para sua realidade;
- nao representam um padrao obrigatorio;
- nao substituem procedimentos internos da sua empresa;
- podem conter nomenclaturas especificas de um cenario industrial de referencia.

Se o seu processo for diferente, ajuste os titulos, categorias e ordem dos templates diretamente na tabela `checklist_templates` ou nos scripts SQL.

## 3. Principais funcionalidades

- Cadastro de auditoria com:
	- linha;
	- cliente;
	- criador/responsavel da auditoria;
	- data limite.
- Cria automaticamente itens de checklist com base em templates ativos.
- Visualizacao de auditorias em cards com barra de progresso.
- Modal detalhado por auditoria em formato Kanban.
- Drag-and-drop para mudar status dos itens.
- Cadastro manual de novos itens alem dos templates.
- Edicao de responsavel, prazo, prioridade, status e observacao.
- Upload e substituicao de PDF por item.
- Exclusao de itens e auditorias com remocao do arquivo PDF associado.
- Finalizacao de auditoria quando atingir 100% dos itens concluidos.

## 4. Tecnologias utilizadas

- PHP 8+ (sem framework)
- MySQL / MariaDB
- HTML5
- CSS3
- JavaScript (Vanilla JS)
- PDO para acesso ao banco
- Sessao PHP + token CSRF para protecao de formularios

## 5. Estrutura do projeto

```text
auditorias/
|- index.php                  # Controller + view principal (monolitico)
|- .env                       # Configuracoes locais de banco
|- .env.example               # Exemplo de variaveis de ambiente
|- assets/
|  |- app.js                  # Comportamento da UI (modais, drag-drop, AJAX)
|  |- style.css               # Estilos da interface
|- database/
|  |- schema.sql              # Estrutura inicial do banco + seed de templates
|  |- update_templates.sql    # Ajustes de templates para base ja existente
|- src/
|  |- bootstrap.php           # Conexao, bootstrap e utilitarios
|- public/                    # PDFs enviados pelos usuarios
```

## 6. Requisitos

- PHP 8.0 ou superior
- MySQL 5.7+ ou MariaDB 10.4+
- Servidor local (XAMPP recomendado)
- Extensoes PHP comuns habilitadas (`pdo_mysql`, `fileinfo`, etc.)

## 7. Configuracao do ambiente

### 7.1. Clone ou copie o projeto

Coloque a pasta do projeto em `htdocs` (XAMPP), por exemplo:

```text
c:/xampp/htdocs/auditorias
```

### 7.2. Configure o arquivo .env

Use o `.env.example` como base e preencha as credenciais:

```env
DB_HOST=
DB_PORT=
DB_USERNAME=
DB_PASSWORD=
DB_NAME=auditoria
```

### 7.3. Banco de dados

Nao e necessario importar manualmente em um cenario novo, pois o projeto:

1. conecta ao servidor MySQL;
2. cria o banco automaticamente (se nao existir);
3. aplica `database/schema.sql` na primeira execucao (quando nao encontra a tabela `audits`).

Para ambiente legado (ja existente), pode ser necessario executar `database/update_templates.sql` uma unica vez.

## 8. Como executar

1. Inicie Apache e MySQL no XAMPP.
2. Acesse no navegador:

```text
http://localhost/auditorias/
```

3. Clique em **Nova Auditoria** para criar o primeiro registro.
4. Abra o card da auditoria para visualizar o Kanban.
5. Mova os cards entre colunas para atualizar o status.

## 9. Fluxo de uso recomendado

1. Criar auditoria com data limite e responsavel principal.
2. Clicar em **Criar AUDITORIA** dentro do modal para carregar itens template.
3. Ajustar responsavel/prazo de cada item conforme necessidade real.
4. Adicionar itens extras quando necessario.
5. Atualizar status durante a execucao das atividades.
6. Anexar evidencias em PDF por item.
7. Finalizar auditoria ao concluir 100% dos itens.

## 10. Regras de negocio implementadas

- A data prevista do item nao pode ultrapassar a data limite da auditoria.
- Status permitidos: `nao_iniciado`, `em_andamento`, `em_revisao`, `concluido`, `bloqueado`.
- Prioridades permitidas: `baixa`, `media`, `alta`.
- Ao marcar item como concluido, o sistema registra data/hora de conclusao.
- Ao excluir item ou auditoria, PDFs vinculados sao removidos do disco.
- Operacoes de escrita exigem token CSRF valido.

## 11. Observacoes sobre anexos PDF

- Sao aceitos somente arquivos com MIME type `application/pdf`.
- Os arquivos sao armazenados em `public/` com nome unico.
- O PDF pode ser associado por item (e visualizado no card/modal).

## 12. Scripts SQL do diretorio database

- `schema.sql`:
	- cria estrutura completa do banco;
	- cria tabelas `audits`, `audit_items` e `checklist_templates`;
	- inclui seed inicial de templates.
- `update_templates.sql`:
	- corrige/atualiza nomes de templates para bases antigas.

Reforco: os textos de templates sao exemplos iniciais de um cenario industrial e devem ser customizados para o seu processo.

## 13. Seguranca e boas praticas

- Uso de `PDO` com prepared statements.
- Escape de saida com `htmlspecialchars`.
- Validacao de data e de status/prioridade no backend.
- Token CSRF por sessao.

## 14. Possiveis melhorias futuras

- Historico de mudancas por item (auditoria de alteracoes).
- Login e controle de permissao por perfil.
- Filtros por cliente, linha, status e periodo.
- Dashboard com indicadores e exportacao.
- API REST para integracao com outros sistemas.

## 15. Solucao de problemas rapida

- Erro `.env nao encontrado.`:
	- crie/copie o arquivo `.env` na raiz do projeto.
- Erro de conexao com banco:
	- revise host, porta, usuario e senha no `.env`.
- Falha de upload de PDF:
	- confirme permissao de escrita em `public/` e limites de upload do PHP.
- Caracteres com acento estranhos:
	- confirme `utf8mb4` no banco e no servidor.

---

Se este projeto for usado em producao, recomenda-se revisar validacoes, logging, autenticacao e backup de arquivos para atender requisitos de governanca e compliance.

