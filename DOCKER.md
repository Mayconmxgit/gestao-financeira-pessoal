# Execução com Docker

## Preparação

1. Copie o arquivo de variáveis e gere os segredos:

   ```powershell
   Copy-Item .env.docker.example .env.docker
   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
   ```

2. Preencha `APP_KEY`, `JWT_SEGREDO_BASE64`, `DB_PASSWORD` e
   `MYSQL_ROOT_PASSWORD` no `.env.docker`.

3. Inicie os serviços:

   ```powershell
   docker compose up --build -d
   ```

A primeira inicialização aplica as migrações uma vez pelo serviço `migrate`.

## Portas

| Serviço | Endereço local | Observação |
| --- | --- | --- |
| Frontend Next.js | `http://localhost:3000` | Exposto para o navegador. |
| API Laravel | `http://127.0.0.1:8000` | Restrita à máquina local; o frontend usa a rede interna. |
| MySQL | `127.0.0.1:3307` | Restrito à máquina local; a porta interna do container é `3306`. |

Para trocar as portas do host, inclua `FRONTEND_PORT`, `BACKEND_HOST_PORT` ou
`MYSQL_HOST_PORT` no `.env.docker` antes de subir os containers.

## Operação

```powershell
docker compose ps
docker compose logs -f frontend backend scheduler
docker compose down
```

`docker compose down` preserva o banco e os arquivos privados. Para remover
os dados locais de forma irreversível, use `docker compose down --volumes`.

O banco e o armazenamento privado usam volumes Docker (`mysql_data` e
`backend_storage`). O agendador é um serviço único: não o escale sem trocar o
cache de arquivos por um armazenamento compartilhado, como Redis.
