# Vida Financeira — frontend pessoal

Interface Next.js do MVP pessoal. As chamadas do navegador passam pelas rotas locais `/api`: os tokens de acesso e renovação ficam em cookies `HttpOnly`, e o backend Laravel permanece em `127.0.0.1:8000`. O acesso curto é renovado pelo servidor por até 7 dias de inatividade, sem expor os tokens ao JavaScript do navegador.

## Iniciar

No Windows, execute `Iniciar Vida Financeira.exe` na pasta raiz. Ele inicia MySQL, API, agendador e frontend em um processo independente do Codex ou do terminal e abre `http://localhost:3000`.

Na primeira execução, instale as dependências:

```powershell
cd frontend
npm.cmd install
Copy-Item .env.example .env.local
```

O `.env.local` deste workspace já aponta para o backend local. Para iniciar separadamente:

```powershell
npm.cmd run dev
```

Use exatamente `http://localhost:3000`; `APP_ORIGIN` valida a origem das operações de escrita. Ajuste `BACKEND_URL` e `APP_ORIGIN` no ambiente antes de publicar em outro endereço. Sirva frontend e backend por HTTPS em produção, mantenha o backend acessível apenas pelo servidor Next.js e configure o e-mail SMTP no backend para recuperação de senha.

## Verificações

```powershell
npm.cmd run typecheck
npm.cmd run lint
npm.cmd run build
```

A tela **Empréstimos** cadastra contratos PRICE, SAC ou com total combinado, mostra o cronograma responsivo, destaca atrasos e permite registrar cada pagamento ou quitar o saldo antecipadamente. O valor recebido e a amortização do principal afetam o saldo da conta sem distorcer receitas e despesas; juros e taxas são apresentados separadamente.

A tela **Assinatura** apresenta o contrato atual e os planos publicados. A administração usa entrada e sessão independentes em `/admin/entrar`, sem compartilhar navegação ou cookies com a área pessoal. O painel oferece indicadores, cadastro e edição de planos, assinaturas, reconciliação com o provedor, eventos de webhook e cortesias auditadas. Toda autorização é repetida no backend.
