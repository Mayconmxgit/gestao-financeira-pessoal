using System.Diagnostics;
using System.Net.Sockets;
using System.Text;

Console.OutputEncoding = Encoding.UTF8;
Console.Title = "Vida Financeira";

var opcoes = Opcoes.Ler(args);

if (opcoes.DeveIniciarIndependente)
{
    var executavelAtual = Environment.ProcessPath;
    var argumentosAtuais = string.Equals(
        Path.GetFileNameWithoutExtension(executavelAtual),
        "dotnet",
        StringComparison.OrdinalIgnoreCase)
        ? new[] { Environment.GetCommandLineArgs()[0] }.Concat(args).ToArray()
        : args;
    InicializacaoIndependente.Iniciar(executavelAtual, argumentosAtuais);
    Console.WriteLine("Vida Financeira iniciada em um processo independente.");
    return;
}

var caminhos = Caminhos.Criar(AppContext.BaseDirectory);
var servicos = new List<ServicoExecutado>();
using var cancelamento = new CancellationTokenSource();

Console.CancelKeyPress += (_, evento) =>
{
    evento.Cancel = true;
    cancelamento.Cancel();
};

try
{
    Console.WriteLine("Vida Financeira — inicialização completa");
    ValidarArquivos(caminhos);
    await ValidarAmbiente(caminhos);
    if (opcoes.SomenteDiagnosticarCaminhos)
    {
        await DiagnosticarMySql(caminhos);
        return;
    }
    await GarantirMySql(caminhos);

    if (!opcoes.SomenteVerificar && await AplicacaoJaEstaAtiva())
    {
        Console.WriteLine();
        Console.WriteLine("A aplicação já está em execução: http://localhost:3000");
        if (!opcoes.SemNavegador)
            Process.Start(new ProcessStartInfo("http://localhost:3000") { UseShellExecute = true });
        return;
    }

    await AplicarMigracoes(caminhos);

    if (opcoes.SomenteVerificar)
    {
        Console.WriteLine("Ambiente verificado com sucesso.");
        return;
    }

    await GarantirPortasLivres();
    servicos.Add(IniciarServico(caminhos.Php, caminhos.Backend, "API",
        ["artisan", "serve", "--host=127.0.0.1", "--port=8000"]));
    servicos.Add(IniciarServico(caminhos.Php, caminhos.Backend, "AGENDADOR",
        ["artisan", "schedule:work", "--no-interaction"]));
    servicos.Add(IniciarServico(caminhos.Node, caminhos.Frontend, "FRONTEND",
        ["node_modules/next/dist/bin/next", "dev", "--hostname", "localhost", "--port", "3000"]));

    await EsperarHttp("http://127.0.0.1:8000/up", "API", TimeSpan.FromSeconds(30), cancelamento.Token);
    await EsperarHttp("http://localhost:3000", "frontend", TimeSpan.FromSeconds(60), cancelamento.Token);

    Console.WriteLine();
    Console.WriteLine("Aplicação pronta: http://localhost:3000");
    Console.WriteLine("API pronta:       http://127.0.0.1:8000");
    Console.WriteLine("Serviços ativos:  MySQL, API, agendador e frontend");
    Console.WriteLine("Pressione Ctrl+C para encerrar os serviços da aplicação.");

    if (!opcoes.SemNavegador)
        Process.Start(new ProcessStartInfo("http://localhost:3000") { UseShellExecute = true });

    await Supervisionar(servicos, cancelamento.Token);
}
catch (OperationCanceledException) when (cancelamento.IsCancellationRequested)
{
    Console.WriteLine("Encerrando a aplicação...");
}
catch (Exception erro)
{
    Console.ForegroundColor = ConsoleColor.Red;
    Console.Error.WriteLine("Falha ao iniciar: " + erro.Message);
    Console.ResetColor();
    if (!Console.IsInputRedirected && !opcoes.SemPausa)
    {
        Console.WriteLine("Pressione Enter para fechar.");
        Console.ReadLine();
    }
    Environment.ExitCode = 1;
}
finally
{
    EncerrarServicos(servicos);
}

static void ValidarArquivos(Caminhos caminhos)
{
    var obrigatorios = new Dictionary<string, string>
    {
        [Path.Combine(caminhos.Backend, "artisan")] = "Backend Laravel não encontrado.",
        [Path.Combine(caminhos.Backend, ".env")] = "Arquivo backend/.env não encontrado.",
        [Path.Combine(caminhos.Backend, "vendor", "autoload.php")] = "Dependências PHP ausentes. Execute composer install em backend.",
        [Path.Combine(caminhos.Frontend, "node_modules", "next", "dist", "bin", "next")] = "Dependências do frontend ausentes. Execute npm.cmd install em frontend.",
        [caminhos.Php] = $"PHP não encontrado em {caminhos.Php}. Defina LIFE_FINANCE_PHP.",
        [caminhos.Node] = $"Node.js não encontrado em {caminhos.Node}. Defina LIFE_FINANCE_NODE.",
    };

    foreach (var item in obrigatorios.Where(item => !File.Exists(item.Key)))
        throw new InvalidOperationException(item.Value);
}

static async Task ValidarAmbiente(Caminhos caminhos)
{
    Console.WriteLine("[1/5] Verificando PHP e Node.js...");
    var php = await Executar(caminhos.Php,
        ["-r", "exit(extension_loaded('pdo_mysql') && extension_loaded('openssl') ? 0 : 1);"], caminhos.Backend);
    if (php.Codigo != 0) throw new InvalidOperationException("PHP precisa das extensões pdo_mysql e openssl.");

    var node = await Executar(caminhos.Node, ["--version"], caminhos.Frontend);
    if (node.Codigo != 0) throw new InvalidOperationException("Node.js não pôde ser executado.");
}

static async Task GarantirMySql(Caminhos caminhos)
{
    Console.WriteLine("[2/5] Verificando MySQL...");
    if (await PortaAberta(3307))
    {
        Console.WriteLine("      MySQL pronto.");
        return;
    }

    await ValidarInstalacaoMySql(caminhos);
    var inicio = CriarProcessoMySql(caminhos, validar: false);
    using var processo = Process.Start(inicio) ?? throw new InvalidOperationException("Não foi possível iniciar o MySQL.");
    await EsperarMySql(processo, caminhos.LogMySql, TimeSpan.FromSeconds(30));
    Console.WriteLine("      MySQL pronto.");
}

static async Task DiagnosticarMySql(Caminhos caminhos)
{
    await ValidarInstalacaoMySql(caminhos);
    Console.WriteLine("Diagnóstico MySQL:");
    Console.WriteLine($"  Servidor: {caminhos.MySql}");
    Console.WriteLine($"  Dados:    {caminhos.DadosMySql}");
    Console.WriteLine("Instalação e parâmetros do MySQL validados com sucesso.");
}

static async Task ValidarInstalacaoMySql(Caminhos caminhos)
{
    if (!File.Exists(caminhos.MySql))
        throw new InvalidOperationException($"Servidor MySQL não encontrado em {caminhos.MySql}. Defina LIFE_FINANCE_MYSQL.");
    if (!Directory.Exists(caminhos.DadosMySql))
        throw new InvalidOperationException($"Pasta de dados do MySQL não encontrada em {caminhos.DadosMySql}.");

    var validacao = await ExecutarMySql(caminhos, validar: true, TimeSpan.FromSeconds(15));
    if (validacao.Codigo != 0)
        throw new InvalidOperationException("A instalação local do MySQL não passou na validação. " + UltimaLinha(validacao.Texto));
}

static ProcessStartInfo CriarProcessoMySql(Caminhos caminhos, bool validar)
{
    var inicio = new ProcessStartInfo(caminhos.MySql)
    {
        UseShellExecute = false,
        CreateNoWindow = true,
        WindowStyle = ProcessWindowStyle.Hidden,
        RedirectStandardOutput = validar,
        RedirectStandardError = validar,
    };
    foreach (var argumento in ArgumentosMySql(caminhos, validar)) inicio.ArgumentList.Add(argumento);
    return inicio;
}

static string[] ArgumentosMySql(Caminhos caminhos, bool validar) =>
[
    "--no-defaults",
    $"--basedir={Path.GetDirectoryName(Path.GetDirectoryName(caminhos.MySql))}",
    $"--datadir={caminhos.DadosMySql}",
    "--port=3307",
    "--bind-address=127.0.0.1",
    "--character-set-server=utf8mb4",
    "--collation-server=utf8mb4_unicode_ci",
    $"--log-error={caminhos.LogMySql}",
    "--mysqlx=0",
    "--local-infile=0",
    .. (validar ? new[] { "--validate-config" } : Array.Empty<string>()),
];

static async Task<(int Codigo, string Texto)> ExecutarMySql(Caminhos caminhos, bool validar, TimeSpan limite)
{
    using var processo = Process.Start(CriarProcessoMySql(caminhos, validar))
        ?? throw new InvalidOperationException("Não foi possível executar o MySQL.");
    var saida = processo.StandardOutput.ReadToEndAsync();
    var erro = processo.StandardError.ReadToEndAsync();
    await processo.WaitForExitAsync().WaitAsync(limite);
    return (processo.ExitCode, await saida + await erro);
}

static async Task<bool> AplicacaoJaEstaAtiva()
{
    var api = HttpDisponivel("http://127.0.0.1:8000/up");
    var frontend = HttpDisponivel("http://localhost:3000");
    var resultados = await Task.WhenAll(api, frontend);
    return resultados.All(ativo => ativo);
}

static async Task EsperarMySql(Process processo, string log, TimeSpan limite)
{
    var inicio = Stopwatch.StartNew();
    while (inicio.Elapsed < limite)
    {
        if (await PortaAberta(3307)) return;
        if (processo.HasExited)
            throw new InvalidOperationException($"MySQL encerrou durante a inicialização com código {processo.ExitCode}. {UltimaLinhaLog(log)}");
        await Task.Delay(500);
    }
    throw new TimeoutException($"MySQL não ficou disponível em {limite.TotalSeconds:0} segundos. {UltimaLinhaLog(log)}");
}

static string UltimaLinhaLog(string arquivo) => File.Exists(arquivo)
    ? UltimaLinha(File.ReadAllText(arquivo))
    : "O MySQL não produziu um log de erro.";

static async Task AplicarMigracoes(Caminhos caminhos)
{
    Console.WriteLine("[3/5] Atualizando banco de dados...");
    var resultado = await Executar(caminhos.Php,
        ["artisan", "migrate", "--force", "--no-interaction", "--no-ansi"], caminhos.Backend, TimeSpan.FromMinutes(2));
    if (resultado.Codigo != 0)
        throw new InvalidOperationException("Não foi possível conectar ou migrar o banco. " + UltimaLinha(resultado.Texto));
    Console.WriteLine("      Banco atualizado.");
}

static async Task GarantirPortasLivres()
{
    Console.WriteLine("[4/5] Verificando portas da aplicação...");
    var ocupadas = new List<int>();
    if (await PortaAberta(8000)) ocupadas.Add(8000);
    if (await PortaAberta(3000, "localhost")) ocupadas.Add(3000);
    if (ocupadas.Count > 0)
        throw new InvalidOperationException($"Porta(s) {string.Join(", ", ocupadas)} ocupada(s). Encerre a instância anterior e tente novamente.");
}

static ServicoExecutado IniciarServico(string programa, string pasta, string nome, string[] argumentos)
{
    if (nome == "API") Console.WriteLine("[5/5] Iniciando serviços da aplicação...");
    var inicio = new ProcessStartInfo(programa)
    {
        WorkingDirectory = pasta,
        UseShellExecute = false,
        RedirectStandardOutput = true,
        RedirectStandardError = true,
        CreateNoWindow = true,
    };
    foreach (var argumento in argumentos) inicio.ArgumentList.Add(argumento);
    var processo = Process.Start(inicio) ?? throw new InvalidOperationException($"Falha ao iniciar {nome}.");
    processo.OutputDataReceived += (_, evento) => EscreverLog(nome, evento.Data, erro: false);
    processo.ErrorDataReceived += (_, evento) => EscreverLog(nome, evento.Data, erro: true);
    processo.BeginOutputReadLine();
    processo.BeginErrorReadLine();
    return new(nome, processo);
}

static void EscreverLog(string servico, string? mensagem, bool erro)
{
    if (string.IsNullOrWhiteSpace(mensagem)) return;
    if (erro) Console.Error.WriteLine($"[{servico}] {mensagem}");
    else Console.WriteLine($"[{servico}] {mensagem}");
}

static async Task Supervisionar(IReadOnlyCollection<ServicoExecutado> servicos, CancellationToken token)
{
    while (!token.IsCancellationRequested)
    {
        var encerrado = servicos.FirstOrDefault(servico => servico.Processo.HasExited);
        if (encerrado is not null)
            throw new InvalidOperationException($"O serviço {encerrado.Nome} encerrou com código {encerrado.Processo.ExitCode}.");
        await Task.Delay(500, token);
    }
}

static void EncerrarServicos(IEnumerable<ServicoExecutado> servicos)
{
    foreach (var servico in servicos.Reverse())
    {
        try
        {
            if (!servico.Processo.HasExited) servico.Processo.Kill(entireProcessTree: true);
            servico.Processo.WaitForExit(5000);
        }
        catch (InvalidOperationException) { }
        finally { servico.Processo.Dispose(); }
    }
}

static async Task EsperarHttp(string url, string nome, TimeSpan limite, CancellationToken token)
{
    var inicio = Stopwatch.StartNew();
    while (inicio.Elapsed < limite)
    {
        token.ThrowIfCancellationRequested();
        if (await HttpDisponivel(url)) return;
        await Task.Delay(500, token);
    }
    throw new TimeoutException($"{nome} não respondeu em {limite.TotalSeconds:0} segundos.");
}

static async Task<bool> HttpDisponivel(string url)
{
    using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(3) };
    try
    {
        using var resposta = await http.GetAsync(url);
        return resposta.IsSuccessStatusCode;
    }
    catch (Exception erro) when (erro is HttpRequestException or TaskCanceledException) { return false; }
}

static async Task<bool> PortaAberta(int porta, string host = "127.0.0.1")
{
    using var cliente = new TcpClient();
    try
    {
        await cliente.ConnectAsync(host, porta).WaitAsync(TimeSpan.FromSeconds(2));
        return true;
    }
    catch (Exception erro) when (erro is SocketException or TimeoutException or OperationCanceledException) { return false; }
}

static async Task<(int Codigo, string Texto)> Executar(
    string programa, string[] argumentos, string pasta, TimeSpan? limite = null)
{
    var inicio = new ProcessStartInfo(programa)
    {
        WorkingDirectory = pasta,
        UseShellExecute = false,
        RedirectStandardOutput = true,
        RedirectStandardError = true,
        CreateNoWindow = true,
    };
    foreach (var argumento in argumentos) inicio.ArgumentList.Add(argumento);
    using var processo = Process.Start(inicio) ?? throw new InvalidOperationException($"Falha ao executar {programa}.");
    var saida = processo.StandardOutput.ReadToEndAsync();
    var erro = processo.StandardError.ReadToEndAsync();
    await processo.WaitForExitAsync().WaitAsync(limite ?? TimeSpan.FromSeconds(30));
    return (processo.ExitCode, await saida + await erro);
}

static string UltimaLinha(string texto) =>
    texto.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).LastOrDefault()
    ?? "Consulte os logs para mais detalhes.";

sealed record ServicoExecutado(string Nome, Process Processo);

sealed record Opcoes(
    bool SemNavegador,
    bool SemPausa,
    bool SomenteVerificar,
    bool SomenteDiagnosticarCaminhos,
    bool ProcessoIndependente)
{
    public bool DeveIniciarIndependente =>
        OperatingSystem.IsWindows()
        && !ProcessoIndependente
        && !SomenteVerificar
        && !SomenteDiagnosticarCaminhos;

    public static Opcoes Ler(string[] argumentos) => new(
        argumentos.Contains("--sem-navegador", StringComparer.OrdinalIgnoreCase),
        argumentos.Contains("--sem-pausa", StringComparer.OrdinalIgnoreCase),
        argumentos.Contains("--verificar", StringComparer.OrdinalIgnoreCase),
        argumentos.Contains("--diagnosticar-caminhos", StringComparer.OrdinalIgnoreCase),
        argumentos.Contains("--processo-independente", StringComparer.OrdinalIgnoreCase));
}

sealed record Caminhos(
    string Raiz, string Backend, string Frontend, string Php, string Node,
    string MySql, string DadosMySql, string LogMySql)
{
    public static Caminhos Criar(string raiz)
    {
        var perfil = ResolverPerfilUsuario();
        return new(
            raiz,
            Path.Combine(raiz, "backend"),
            Path.Combine(raiz, "frontend"),
            Environment.GetEnvironmentVariable("LIFE_FINANCE_PHP") ?? @"C:\xampp\php\php.exe",
            Environment.GetEnvironmentVariable("LIFE_FINANCE_NODE")
                ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "nodejs", "node.exe"),
            ResolverMySql(),
            Path.Combine(perfil, "LifeFinanceMySQL", "data"),
            Path.Combine(perfil, "LifeFinanceMySQL", "mysql-error.log"));
    }

    private static string ResolverPerfilUsuario()
    {
        var candidatos = new[]
        {
            Environment.GetEnvironmentVariable("USERPROFILE"),
            Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
        };

        return candidatos.FirstOrDefault(pasta => !string.IsNullOrWhiteSpace(pasta) && Path.IsPathFullyQualified(pasta))
            ?? throw new InvalidOperationException("Não foi possível localizar o perfil do usuário. Defina USERPROFILE.");
    }

    private static string ResolverMySql()
    {
        var arquivos = new List<string?> { Environment.GetEnvironmentVariable("LIFE_FINANCE_MYSQL") };
        foreach (var pasta in new[]
        {
            Environment.GetEnvironmentVariable("ProgramFiles"),
            Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),
        }.Where(pasta => !string.IsNullOrWhiteSpace(pasta)).Distinct(StringComparer.OrdinalIgnoreCase))
        {
            arquivos.Add(Path.Combine(pasta!, "MySQL", "MySQL Server 8.0", "bin", "mysqld.exe"));
        }
        arquivos.Add(@"C:\xampp\mysql\bin\mysqld.exe");

        return arquivos.FirstOrDefault(arquivo => !string.IsNullOrWhiteSpace(arquivo) && File.Exists(arquivo))
            ?? arquivos.First(arquivo => !string.IsNullOrWhiteSpace(arquivo))!;
    }
}
