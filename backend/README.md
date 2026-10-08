# Vida Financeira — backend pessoal

API Laravel 12 do MVP de finanças pessoais. O banco local usa MySQL 8. O módulo de empresas e os recursos futuros descritos na documentação não fazem parte desta entrega.

## Executar localmente

No Windows deste projeto, dê dois cliques em `Iniciar Vida Financeira.exe` na pasta `Life finance`. O executável inicia um processo independente do terminal ou do Codex, verifica dependências e o `.env`, inicia a instância MySQL exclusiva deste projeto (porta 3307), aplica migrações pendentes e inicia frontend, API e agendador. Fechar o Codex ou o terminal usado para abrir o iniciador não encerra a aplicação. Se a aplicação já estiver ativa, uma nova abertura reutiliza a instância existente e apenas abre a interface, sem tentar iniciar serviços duplicados. A interface abre em `http://localhost:3000` e a API fica em `http://127.0.0.1:8000`. Execute `npm.cmd install` na pasta `frontend` antes da primeira inicialização. Pressione Ctrl+C na janela do processo independente para encerrar frontend, API e agendador. O MySQL continua ligado, pois pode ser usado por outros processos. O executável usa o .NET 10 instalado neste computador; seu código-fonte fica em `backend/tools/Iniciador`.

Para recompilar após mudar o código do iniciador, execute na pasta `Life finance`:

```powershell
dotnet publish backend/tools/Iniciador/Iniciador.csproj -c Release -r win-x64 -p:PublishSingleFile=true --self-contained false -o backend/tools/Iniciador/publicado
Copy-Item 'backend/tools/Iniciador/publicado/Iniciador.exe' 'Iniciar Vida Financeira.exe' -Force
```

Execução manual, se preferir:

Requisitos: PHP 8.2+ com `pdo_mysql`, MySQL 8 e Composer. Nesta instalação, a instância isolada fica em `%USERPROFILE%\LifeFinanceMySQL`, na porta 3307; o banco `vida_financeira` e o usuário exclusivo já foram criados. Para uma nova instalação, crie a instância, o banco e o usuário antes de migrar. Na pasta `backend`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Preencha `DB_*`, `FRONTEND_ORIGEM` e `JWT_SEGREDO_BASE64` no `.env` ignorado pelo Git. O segredo JWT deve conter 32 bytes aleatórios em Base64 e ser diferente de `APP_KEY`. A instalação local deste workspace já tem um `.env` configurado e migrações aplicadas; não substitua esse arquivo ao seguir os comandos acima. O servidor HTTP deve publicar apenas `public/` e usar HTTPS fora do ambiente local.

O iniciador não depende de um arquivo `my.ini`. Ele fornece diretamente ao MySQL os parâmetros da instância isolada em `%USERPROFILE%\LifeFinanceMySQL\data`, limitada a `127.0.0.1:3307`, e valida esses parâmetros com o próprio servidor antes de iniciá-lo. Essa localização é visível ao processo independente iniciado pelo Explorer. O servidor é procurado em `Program Files\MySQL` e `C:\xampp\mysql`; instalações em outro caminho podem definir `LIFE_FINANCE_MYSQL`.

Para validar a instalação e os parâmetros, sem iniciar o MySQL ou executar migrações, use `Iniciar Vida Financeira.exe --diagnosticar-caminhos --sem-pausa --sem-navegador`.

O iniciador mantém `php artisan schedule:work` ativo para remover tokens de recuperação expirados. A recuperação de senha exige um transporte de e-mail real (`MAIL_MAILER=smtp` e respectivos parâmetros); o transporte `log` local a deixa indisponível, para impedir que links de recuperação sejam gravados em logs.

## Autenticação e respostas

Prefixo de todas as rotas: `/api/v1`. Cadastre com `POST /usuarios` (`nome`, `email`, `senha`), entre com `POST /entrar` e envie `Authorization: Bearer <token>` nas rotas protegidas. O JWT de acesso dura 15 minutos; `POST /renovar` recebe `token` e renova a sessão por até 7 dias de inatividade. `POST /sair` revoga os tokens anteriores. `GET /usuarios/eu` retorna o usuário. `POST /recuperar-acesso` recebe `email`; `POST /redefinir-senha` recebe `email`, `token`, `senha`.

Erros de validação retornam HTTP 422 e um objeto `erros`. Outros erros retornam `codigo`, `mensagem` e, quando aplicável, `trace_id`. Os limites de requisição podem retornar HTTP 429. Dados de usuários diferentes são isolados em todas as rotas financeiras.

## Contrato financeiro

| Método e rota | Corpo/uso |
| --- | --- |
| `GET /contas` | Lista contas do usuário. |
| `POST /contas` | `nome`, `tipo` (`corrente`, `poupanca`, `cartao_credito`, `dinheiro`, `outro`), `moeda` ISO de 3 letras, `saldo_inicial`, `data_saldo` (`AAAA-MM-DD`), `instituicao` opcional. |
| `PATCH /contas/{id}` | Altera `nome`, `instituicao` ou `situacao` (`ativa`, `arquivada`). |
| `POST /contas/{id}/ajustes` | `data`, `valor` assinado e `motivo`. |
| `GET /categorias` / `POST /categorias` | Lista ou cria categoria com `nome` e `tipo` (`receita`, `despesa`). |
| `GET /transacoes` | Filtros opcionais `conta_id`, `moeda`, `inicio`, `fim`, `ordem` (`asc` ou `desc`), `limite` (até 100), `apos`. Retorna `proximo_cursor`. |
| `POST /transacoes` | `conta_id`, `data`, `valor` assinado, `descricao`, `natureza` (`entrada`, `saida`, `compra_cartao`), `categoria_id` opcional. |
| `PATCH /transacoes/{id}/classificacao` | `categoria_id` (ou `null`). |
| `POST /transacoes/{id}/estornos` | `data` e `motivo`; cria lançamento inverso vinculado. |
| `POST /transacoes/{id}/contrapartida` | `conta_contrapartida_id`, `natureza` (`transferencia`, `pagamento_fatura`) para lançamento importado. |
| `POST /transferencias` | `origem_id`, `destino_id`, `data`, `valor` positivo, `descricao`. Pagamento ao cartão é tratado como fatura. |
| `GET /resumo` | `inicio` e `fim` obrigatórios; `conta_id` ou `moeda` opcionais. Retorna saldos, realizado e compromissos do mês seguinte por conta e consolidado. Não soma moedas diferentes. |
| `GET /emprestimos` / `GET /emprestimos/{id}` | Lista contratos e consulta parcelas, saldo principal, total programado e próximo vencimento. |
| `POST /emprestimos` | Cadastra valor recebido, credor, conta, datas, taxas, juros, quantidade e sistema `price`, `sac` ou `manual`; gera o cronograma e a entrada da dívida atomicamente. |
| `POST /emprestimos/{id}/parcelas/{parcelaId}/pagamentos` | Registra conta e data do pagamento; separa amortização de juros e taxas. |
| `DELETE /emprestimos/{id}/parcelas/{parcelaId}/pagamentos` | Desfaz o pagamento da parcela; remove movimentações geradas pelo empréstimo e preserva movimentações previamente associadas. |
| `POST /emprestimos/{id}/quitacao` | Quita o principal pendente com juros e taxas informados para a liquidação antecipada. |

Valores monetários são strings decimais com ponto e até duas casas, como `"-125.40"`; a API usa aritmética decimal. Uma compra no cartão é despesa na data da compra. A quitação da fatura é transferência e não cria outra despesa. Transferências, ajustes, empréstimos recebidos e amortização de principal não contam como receita ou despesa. Juros e taxas pagos entram na categoria de despesa `Juros e taxas`. Movimentações sem categoria aparecem como pendentes no resumo.

Lançamentos manuais podem usar datas futuras. Eles ficam registrados no mês informado e não afetam o saldo de períodos anteriores. `compromissos_proximo_mes` soma as parcelas pendentes de empréstimos e todas as ocorrências de pagamentos recorrentes previstas para o mês seguinte ao período consultado; essa projeção não cria movimentações nem altera saldos.

## Importação OFX/CSV/PDF

1. Envie `POST /importacoes` como `multipart/form-data`, com `conta_id`, `arquivo` (`.ofx`, `.csv` ou PDF textual do Itaú, até 5 MiB) e `instituicao` opcional. O retorno contém `id` do lote.
2. Consulte `GET /importacoes/{id}/previa`. Cada linha informa se está pronta, inválida, duplicada por identificador bancário ou com possível duplicidade por conteúdo. `GET /importacoes` lista os lotes.
3. Corrija uma linha inválida com `PATCH /importacoes/{id}/linhas/{linhaId}` (`data`, `valor`, `descricao`, `identificador_bancario` e `documento` opcionais).
4. Confirme com `POST /importacoes/{id}/confirmacao`, informando `instituicao` e, quando necessário, `decisoes: [{linha_id, decisao, transacao_candidata_id?, categoria_id?, motivo?}]`. As decisões são `importar`, `vincular`, `separada` ou `ignorar`. Um identificador bancário já importado é ignorado automaticamente; uma possível duplicidade sem ID exige decisão. A confirmação é atômica.
5. Use `POST /importacoes/{id}/cancelamento` para descartar a prévia, ou `POST /importacoes/{id}/reversao` com `confirmar: true` e `motivo` para estornar um lote confirmado. A reversão preserva o histórico.

O CSV padrão aceita `;` ou `,`, cabeçalhos `data`, `descricao`, `valor` e opcionais `id_bancario`, `documento`. Datas aceitas: `AAAA-MM-DD`, `DD/MM/AAAA`. Valores aceitam `-123.45` ou `-123,45`. O OFX aceita lançamentos `STMTTRN` com `DTPOSTED`, `TRNAMT`, `NAME`/`MEMO` e `FITID` opcional. O PDF precisa ter texto selecionável e o layout de extrato de conta do Itaú; imagens escaneadas e outros layouts são rejeitados. Até 5.000 lançamentos por arquivo.

`GET /usuarios/eu/exportacao` exporta dados pessoais e financeiros em JSON, incluindo empréstimos e parcelas. `DELETE /usuarios/eu` exige confirmação da senha e apaga os dados do usuário. `GET /usuarios?apos=ID&limite=20` é exclusivo de administrador e retorna paginação por cursor; não há rota pública para promover usuários.

## Assinaturas

O módulo de assinaturas nasce desativado. Para habilitar o checkout, configure `MERCADO_PAGO_ASSINATURAS_ATIVO=true`, `MERCADO_PAGO_ACCESS_TOKEN`, `MERCADO_PAGO_WEBHOOK_SECRET` e uma `MERCADO_PAGO_BACK_URL` HTTPS. A aplicação nunca recebe dados de cartão: cria uma assinatura pendente e redireciona para a URL validada do Mercado Pago. O webhook assinado é idempotente e o estado local só é atualizado após consulta à API oficial.

Planos com valor `0.00` são gratuitos: sua ativação é imediata, não depende do gateway e fica registrada com provedor `gratuito`. Valores negativos e formatos monetários inválidos continuam sendo recusados.

As rotas autenticadas `GET /planos`, `GET /assinatura` e `POST /assinatura/checkout` atendem o cliente. O prefixo `/admin/assinaturas` exige `papel=administrador` e oferece resumo, planos, assinaturas, eventos, reconciliação e concessão de cortesia auditada. Preços já contratados são preservados como snapshot. `ASSINATURAS_ACESSO_OBRIGATORIO=true` ativa o bloqueio das rotas financeiras para usuários sem acesso; o padrão é `false`.

Antes de habilitar em produção, aplique as migrações em ambiente isolado, cadastre os planos no painel e registre no Mercado Pago o webhook `POST /api/v1/webhooks/mercado-pago/assinaturas`. Não armazene tokens no repositório.

## Verificação

`php artisan test --no-ansi` cobre autenticação, importação, conciliação, resumo, cartão, empréstimos, estorno, recuperação, privacidade, assinaturas e a indisponibilidade dos antigos endpoints de IA. Execute as mesmas suítes em um banco MySQL isolado antes de publicar. A entrega real de e-mail depende das credenciais SMTP configuradas no ambiente.
