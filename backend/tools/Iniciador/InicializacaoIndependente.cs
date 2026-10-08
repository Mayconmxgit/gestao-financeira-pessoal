using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;

public static class InicializacaoIndependente
{
    private const uint ProcessoCriarProcesso = 0x0080;
    private const uint CriarNovoConsole = 0x00000010;
    private const uint CriarSemJanela = 0x08000000;
    private const uint FlagInicializacaoEstendida = 0x00080000;
    private static readonly IntPtr AtributoProcessoPai = (IntPtr)0x00020000;

    public static void Iniciar(string? executavel, IReadOnlyCollection<string> argumentos)
    {
        if (string.IsNullOrWhiteSpace(executavel) || !File.Exists(executavel))
            throw new InvalidOperationException("Não foi possível localizar o executável do iniciador.");

        var argumentosHospedados = argumentos
            .Where(argumento => !string.Equals(
                argumento,
                "--processo-independente",
                StringComparison.OrdinalIgnoreCase))
            .Append("--processo-independente");

        IniciarProcesso(executavel, argumentosHospedados, mostrarJanela: true);
    }

    public static int IniciarProcesso(
        string executavel,
        IEnumerable<string> argumentos,
        bool mostrarJanela)
    {
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("A inicialização independente requer Windows.");
        if (!File.Exists(executavel))
            throw new FileNotFoundException("Executável não encontrado.", executavel);

        using var explorer = ObterExplorerDaSessao();
        var processoPai = AbrirProcessoPai(explorer.Id);
        var listaAtributos = IntPtr.Zero;
        try
        {
            listaAtributos = CriarListaAtributos(ref processoPai);
            return CriarProcesso(executavel, argumentos, mostrarJanela, listaAtributos);
        }
        finally
        {
            LiberarListaAtributos(listaAtributos);
            FecharHandle(processoPai);
        }
    }

    private static Process ObterExplorerDaSessao()
    {
        var sessaoAtual = Process.GetCurrentProcess().SessionId;
        return Process.GetProcessesByName("explorer")
            .FirstOrDefault(processo => processo.SessionId == sessaoAtual)
            ?? throw new InvalidOperationException("O Explorer do Windows não está disponível nesta sessão.");
    }

    private static IntPtr AbrirProcessoPai(int identificador)
    {
        var processoPai = OpenProcess(ProcessoCriarProcesso, false, identificador);
        if (processoPai == IntPtr.Zero)
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Não foi possível acessar o Explorer do Windows.");
        return processoPai;
    }

    private static IntPtr CriarListaAtributos(ref IntPtr processoPai)
    {
        nuint tamanho = 0;
        _ = InitializeProcThreadAttributeList(IntPtr.Zero, 1, 0, ref tamanho);
        var listaAtributos = Marshal.AllocHGlobal(checked((int)tamanho));
        var inicializada = false;
        try
        {
            if (!InitializeProcThreadAttributeList(listaAtributos, 1, 0, ref tamanho))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Não foi possível preparar o processo independente.");
            inicializada = true;
            DefinirProcessoPai(listaAtributos, ref processoPai);
            return listaAtributos;
        }
        catch
        {
            if (inicializada) DeleteProcThreadAttributeList(listaAtributos);
            Marshal.FreeHGlobal(listaAtributos);
            throw;
        }
    }

    private static void DefinirProcessoPai(IntPtr listaAtributos, ref IntPtr processoPai)
    {
        if (!UpdateProcThreadAttribute(
                listaAtributos,
                0,
                AtributoProcessoPai,
                ref processoPai,
                (nuint)IntPtr.Size,
                IntPtr.Zero,
                IntPtr.Zero))
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Não foi possível definir o processo independente.");
    }

    private static int CriarProcesso(
        string executavel,
        IEnumerable<string> argumentos,
        bool mostrarJanela,
        IntPtr listaAtributos)
    {
        var inicializacao = NovaInformacaoInicializacao(listaAtributos);
        var linhaComando = new StringBuilder(MontarLinhaComando(executavel, argumentos));
        var flags = FlagInicializacaoEstendida | (mostrarJanela ? CriarNovoConsole : CriarSemJanela);
        if (!CreateProcessW(
                executavel,
                linhaComando,
                IntPtr.Zero,
                IntPtr.Zero,
                false,
                flags,
                IntPtr.Zero,
                Path.GetDirectoryName(executavel),
                ref inicializacao,
                out var processoCriado))
            throw new Win32Exception(Marshal.GetLastWin32Error(), "O Windows recusou a inicialização independente.");

        FecharHandle(processoCriado.Thread);
        FecharHandle(processoCriado.Processo);
        return processoCriado.IdentificadorProcesso;
    }

    private static InformacaoInicializacaoEstendida NovaInformacaoInicializacao(IntPtr listaAtributos) => new()
    {
        InformacaoInicializacao = new InformacaoInicializacao
        {
            Tamanho = Marshal.SizeOf<InformacaoInicializacaoEstendida>(),
        },
        ListaAtributos = listaAtributos,
    };

    private static void LiberarListaAtributos(IntPtr listaAtributos)
    {
        if (listaAtributos == IntPtr.Zero) return;
        DeleteProcThreadAttributeList(listaAtributos);
        Marshal.FreeHGlobal(listaAtributos);
    }

    public static string MontarLinhaComando(string executavel, IEnumerable<string> argumentos) =>
        string.Join(' ', new[] { EscaparArgumento(executavel) }.Concat(argumentos.Select(EscaparArgumento)));

    private static string EscaparArgumento(string argumento)
    {
        if (argumento.Length == 0) return "\"\"";
        if (!argumento.Any(caractere => char.IsWhiteSpace(caractere) || caractere == '"')) return argumento;

        var resultado = new StringBuilder().Append('"');
        var barras = 0;
        foreach (var caractere in argumento)
        {
            if (caractere == '\\')
            {
                barras++;
                continue;
            }

            if (caractere == '"')
            {
                resultado.Append('\\', barras * 2 + 1).Append('"');
                barras = 0;
                continue;
            }

            resultado.Append('\\', barras).Append(caractere);
            barras = 0;
        }

        return resultado.Append('\\', barras * 2).Append('"').ToString();
    }

    private static void FecharHandle(IntPtr handle)
    {
        if (handle != IntPtr.Zero) _ = CloseHandle(handle);
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr OpenProcess(uint acesso, bool herdarHandle, int identificadorProcesso);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool InitializeProcThreadAttributeList(
        IntPtr listaAtributos,
        int quantidadeAtributos,
        int flags,
        ref nuint tamanho);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool UpdateProcThreadAttribute(
        IntPtr listaAtributos,
        uint flags,
        IntPtr atributo,
        ref IntPtr valor,
        nuint tamanho,
        IntPtr valorAnterior,
        IntPtr tamanhoRetornado);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateProcessW(
        string aplicacao,
        StringBuilder linhaComando,
        IntPtr atributosProcesso,
        IntPtr atributosThread,
        bool herdarHandles,
        uint flags,
        IntPtr ambiente,
        string? pastaTrabalho,
        ref InformacaoInicializacaoEstendida inicializacao,
        out InformacaoProcesso processo);

    [DllImport("kernel32.dll")]
    private static extern void DeleteProcThreadAttributeList(IntPtr listaAtributos);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseHandle(IntPtr handle);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct InformacaoInicializacao
    {
        public int Tamanho;
        public string? Reservado;
        public string? AreaTrabalho;
        public string? Titulo;
        public int X;
        public int Y;
        public int LarguraX;
        public int AlturaY;
        public int CaracteresX;
        public int CaracteresY;
        public int AtributoPreenchimento;
        public int Flags;
        public short MostrarJanela;
        public short Reservado2;
        public IntPtr Reservado3;
        public IntPtr EntradaPadrao;
        public IntPtr SaidaPadrao;
        public IntPtr ErroPadrao;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct InformacaoInicializacaoEstendida
    {
        public InformacaoInicializacao InformacaoInicializacao;
        public IntPtr ListaAtributos;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct InformacaoProcesso
    {
        public IntPtr Processo;
        public IntPtr Thread;
        public int IdentificadorProcesso;
        public int IdentificadorThread;
    }
}
