if (args.Length == 3 && string.Equals(args[0], "--verificar-pasta", StringComparison.OrdinalIgnoreCase))
{
    try
    {
        var atributos = File.GetAttributes(args[1]);
        File.WriteAllText(args[2], $"EXISTE|{atributos}");
    }
    catch (Exception erro)
    {
        File.WriteAllText(args[2], $"{erro.GetType().Name}|{erro.Message}|HRESULT={erro.HResult}");
    }
    return;
}

if (args.Contains("--integracao", StringComparer.OrdinalIgnoreCase))
{
    var cmd = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "cmd.exe");
    var processo = InicializacaoIndependente.IniciarProcesso(
        cmd,
        ["/d", "/c", "ping", "127.0.0.1", "-n", "30"],
        mostrarJanela: false);
    Console.WriteLine(processo);
    return;
}

if (args.Length == 3 && string.Equals(args[0], "--integracao-pasta", StringComparison.OrdinalIgnoreCase))
{
    var executavel = Environment.ProcessPath
        ?? throw new InvalidOperationException("Executável de teste não encontrado.");
    var processo = InicializacaoIndependente.IniciarProcesso(
        executavel,
        ["--verificar-pasta", args[1], args[2]],
        mostrarJanela: false);
    Console.WriteLine(processo);
    return;
}

var casos = new Dictionary<string, (string Executavel, string[] Argumentos, string Esperado)>
{
    ["caminho com espaços"] = (
        @"C:\Aplicativos locais\Iniciador.exe",
        ["--processo-independente"],
        "\"C:\\Aplicativos locais\\Iniciador.exe\" --processo-independente"),
    ["argumento vazio"] = (
        @"C:\Iniciador.exe",
        [""],
        "C:\\Iniciador.exe \"\""),
    ["aspas internas"] = (
        @"C:\Iniciador.exe",
        ["valor \"seguro\""],
        "C:\\Iniciador.exe \"valor \\\"seguro\\\"\""),
    ["barra antes da aspa final"] = (
        @"C:\Iniciador.exe",
        [@"C:\Pasta local\"],
        "C:\\Iniciador.exe \"C:\\Pasta local\\\\\""),
};

foreach (var (nome, caso) in casos)
{
    var atual = InicializacaoIndependente.MontarLinhaComando(caso.Executavel, caso.Argumentos);
    if (!string.Equals(atual, caso.Esperado, StringComparison.Ordinal))
        throw new InvalidOperationException($"Falha em '{nome}'. Esperado: {caso.Esperado}. Atual: {atual}.");
}

Console.WriteLine($"{casos.Count} testes do iniciador independente passaram.");
