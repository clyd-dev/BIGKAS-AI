<#
.SYNOPSIS
  Starts the BIGKAS-AI engine and the Laravel scheduler together, in ONE window.

.DESCRIPTION
  Replaces the two terminals you had to leave open:

    1. cd ml-service; venv\Scripts\activate; python app.py     (speech-to-text + model)
    2. php artisan schedule:work                                (scores batch assessments)

  Output from both is shown here, each line tagged [engine] or [scheduler].
  Press Ctrl+C or close the window to stop both: nothing is left running behind.

  The scheduler is what scores a batch (and re-scores readings saved while the
  engine was down). Single-child assessments are scored by the Analyze button
  itself and do not need it. If you will not use batch, run with -NoScheduler.

.PARAMETER NoScheduler
  Start the engine only, exactly as before.

.PARAMETER ReadyTimeoutSeconds
  How long to wait for the speech model to load before carrying on anyway.

.EXAMPLE
  .\start-bigkas.ps1
  .\start-bigkas.ps1 -NoScheduler
#>
[CmdletBinding()]
param(
    [switch]$NoScheduler,
    [int]$ReadyTimeoutSeconds = 240
)

$ErrorActionPreference = 'Stop'

$script:Root      = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:MlDir     = Join-Path $script:Root 'ml-service'
$script:Python    = Join-Path $script:MlDir 'venv\Scripts\python.exe'
$script:HealthUrl = 'http://127.0.0.1:5000/api/health'
$script:Owned     = New-Object System.Collections.ArrayList   # services this window started
$script:ChildrenInJob = $false                                # set once this window is in the cleanup job

# ---------------------------------------------------------------------------
# A Windows job object that kills everything in it when this window goes away.
# Closing the window with the X does NOT run a PowerShell finally block, so
# without this a python.exe holding port 5000 (and the speech model in memory)
# would be left behind, and the next start would find the port taken.
# ---------------------------------------------------------------------------
if (-not ('BigkasJob' -as [type])) {
    Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public static class BigkasJob
{
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
    static extern IntPtr CreateJobObject(IntPtr attrs, string name);
    [DllImport("kernel32.dll")]
    static extern bool SetInformationJobObject(IntPtr job, int infoClass, IntPtr info, uint size);
    [DllImport("kernel32.dll")]
    static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
    [DllImport("kernel32.dll")]
    static extern IntPtr GetCurrentProcess();

    [StructLayout(LayoutKind.Sequential)]
    struct BasicLimits
    {
        public long PerProcessUserTime; public long PerJobUserTime; public uint Flags;
        public UIntPtr MinWs; public UIntPtr MaxWs; public uint ActiveProcessLimit;
        public UIntPtr Affinity; public uint Priority; public uint Sched;
    }
    [StructLayout(LayoutKind.Sequential)]
    struct IoCounters { public ulong A, B, C, D, E, F; }
    [StructLayout(LayoutKind.Sequential)]
    struct ExtendedLimits
    {
        public BasicLimits Basic; public IoCounters Io;
        public UIntPtr ProcessMemory; public UIntPtr JobMemory; public UIntPtr PeakProcess; public UIntPtr PeakJob;
    }

    static IntPtr job = IntPtr.Zero;

    public static bool Create()
    {
        job = CreateJobObject(IntPtr.Zero, null);
        if (job == IntPtr.Zero) return false;
        ExtendedLimits ext = new ExtendedLimits();
        ext.Basic.Flags = 0x2000;   // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
        int size = Marshal.SizeOf(typeof(ExtendedLimits));
        IntPtr p = Marshal.AllocHGlobal(size);
        try
        {
            Marshal.StructureToPtr(ext, p, false);
            return SetInformationJobObject(job, 9, p, (uint)size);   // 9 = ExtendedLimitInformation
        }
        finally { Marshal.FreeHGlobal(p); }
    }

    public static bool Add(IntPtr process)
    {
        return job != IntPtr.Zero && AssignProcessToJobObject(job, process);
    }

    // The launcher joins the job itself, so everything it starts afterwards is
    // born inside it. Adding each child after Start() instead leaves a gap: the
    // venv's python.exe is a stub that starts the real interpreter within
    // milliseconds, and a grandchild started in that gap would escape the job.
    public static bool AddCurrent()
    {
        return job != IntPtr.Zero && AssignProcessToJobObject(job, GetCurrentProcess());
    }
}
"@
}

function Get-EngineHealth {
    # The Flask service's own report, or $null when nothing answers.
    try {
        return Invoke-RestMethod -Uri $script:HealthUrl -TimeoutSec 2 -ErrorAction Stop
    } catch {
        return $null
    }
}

function Test-PortInUse {
    param([int]$Port)
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $client.Connect('127.0.0.1', $Port)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Start-Child {
    # Starts one child with its output shown here under a coloured tag, and puts
    # it in the kill-on-close job. Returns a record the supervisor keeps.
    param(
        [string]$Name,
        [string]$File,
        [string]$Arguments,
        [string]$WorkingDirectory,
        [string]$Color
    )

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = $File
    $psi.Arguments              = $Arguments
    $psi.WorkingDirectory       = $WorkingDirectory
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
    $psi.StandardErrorEncoding  = [System.Text.Encoding]::UTF8
    # Python buffers stdout when it is a pipe, which would hold a log line back
    # for minutes. This makes it arrive when it is written.
    $psi.EnvironmentVariables['PYTHONUNBUFFERED'] = '1'
    $psi.EnvironmentVariables['PYTHONIOENCODING'] = 'utf-8'
    # Laravel decides to colour its output from the terminal it thinks it has,
    # and writes raw escape codes ("[37;44m") into a piped window. NO_COLOR is
    # the standard way to ask for plain text, and schedule:work passes it on to
    # the schedule:run it starts every minute.
    $psi.EnvironmentVariables['NO_COLOR'] = '1'

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $psi

    $tag  = '[{0}]' -f $Name
    $data = @{ Tag = $tag.PadRight(12); Color = $Color }

    $handler = {
        $line = $Event.SourceEventArgs.Data
        if ($null -ne $line -and $line.Length -gt 0) {
            Write-Host ($Event.MessageData.Tag + ' ' + $line) -ForegroundColor $Event.MessageData.Color
        }
    }

    $subscriptions = @(
        (Register-ObjectEvent -InputObject $process -EventName OutputDataReceived -MessageData $data -Action $handler),
        (Register-ObjectEvent -InputObject $process -EventName ErrorDataReceived  -MessageData $data -Action $handler)
    )

    [void]$process.Start()
    if (-not $script:ChildrenInJob -and -not [BigkasJob]::Add($process.Handle)) {
        Write-Host "[launcher]   Could not tie $Name to this window; if you close the window, stop it from Task Manager." -ForegroundColor Yellow
    }
    $process.BeginOutputReadLine()
    $process.BeginErrorReadLine()

    $service = [pscustomobject]@{
        Name = $Name; Process = $process; Subscriptions = $subscriptions
    }
    [void]$script:Owned.Add($service)

    return $service
}

function Stop-Children {
    # Ends everything this window started, including grandchildren (the Flask
    # debug reloader runs the app in a second python.exe).
    foreach ($service in @($script:Owned)) {
        try {
            if (-not $service.Process.HasExited) {
                & taskkill.exe /PID $service.Process.Id /T /F 2>&1 | Out-Null
            }
        } catch { }

        foreach ($subscription in $service.Subscriptions) {
            try { Unregister-Event -SubscriptionId $subscription.Id -ErrorAction SilentlyContinue } catch { }
            try { Remove-Job -Id $subscription.Id -Force -ErrorAction SilentlyContinue } catch { }
        }
    }
    $script:Owned.Clear()
}

function Invoke-Launcher {
    param([bool]$WithScheduler, [int]$TimeoutSeconds)

    $Host.UI.RawUI.WindowTitle = 'BIGKAS-AI  engine + scheduler'

    if (-not (Test-Path $script:Python)) {
        throw "The engine's Python was not found at $($script:Python). Set it up first (see README, ML service)."
    }

    $php = $null
    if ($WithScheduler) {
        $found = Get-Command php -ErrorAction SilentlyContinue
        if (-not $found) {
            throw 'php was not found on PATH. Add your PHP folder (for XAMPP, F:\xampp\php) to PATH, or run with -NoScheduler.'
        }
        $php = $found.Source
    }

    if ([BigkasJob]::Create() -and [BigkasJob]::AddCurrent()) {
        $script:ChildrenInJob = $true
    } else {
        Write-Host '[launcher]   Could not set up cleanup; closing this window may leave the engine running (stop it from Task Manager).' -ForegroundColor Yellow
    }

    Write-Host ''
    Write-Host '  BIGKAS-AI' -ForegroundColor White
    Write-Host ('  engine    : speech-to-text + model  (127.0.0.1:5000)') -ForegroundColor Gray
    if ($WithScheduler) {
        Write-Host '  scheduler : scores batch assessments and re-scores readings saved during an outage' -ForegroundColor Gray
    } else {
        Write-Host '  scheduler : OFF (-NoScheduler). Batch assessments will not be scored.' -ForegroundColor Yellow
    }
    Write-Host '  Ctrl+C or close this window to stop.' -ForegroundColor Gray
    Write-Host ''

    # ---- engine ---------------------------------------------------------
    $engine = $null
    $reused = $false

    if (Test-PortInUse -Port 5000) {
        $health = Get-EngineHealth
        if ($null -eq $health) {
            throw 'Port 5000 is already in use by something that is not the BIGKAS engine. Close it, then start again.'
        }
        # Someone (an earlier window) already runs it. Starting a second one
        # would fail on the port; use that one and leave it alone on exit.
        $reused = $true
        Write-Host '[launcher]   The engine is already running on port 5000 - using it (it will be left running when you stop).' -ForegroundColor Cyan
    } else {
        Write-Host '[launcher]   Starting the engine. The speech model takes a little while to load...' -ForegroundColor Cyan
        $engine = Start-Child -Name 'engine' -File $script:Python -Arguments 'app.py' `
            -WorkingDirectory $script:MlDir -Color 'Cyan'
    }

    # ---- wait until it can actually transcribe -------------------------
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $health   = $null
    while ((Get-Date) -lt $deadline) {
        if ($null -ne $engine -and $engine.Process.HasExited) {
            throw "The engine stopped while starting (exit code $($engine.Process.ExitCode)). See the [engine] lines above."
        }
        $health = Get-EngineHealth
        if ($null -ne $health -and $health.whisper_loaded) { break }
        Start-Sleep -Seconds 1
    }

    if ($null -eq $health) {
        Write-Host "[launcher]   The engine has not answered after $TimeoutSeconds s. Carrying on - check the [engine] lines." -ForegroundColor Yellow
    } elseif (-not $health.whisper_loaded) {
        Write-Host '[launcher]   The engine is up but the speech model is not loaded yet. Recordings cannot be scored until it is.' -ForegroundColor Yellow
    } else {
        $classifier = if ($health.classifier_loaded) { 'model v' + $health.model_version } else { 'NO model loaded (no weakness will be classified)' }
        Write-Host ('[launcher]   Engine ready: speech model loaded, ' + $classifier + '.') -ForegroundColor Green
    }

    # ---- scheduler (only once the engine answers, so the first health check
    #      does not record a false outage while the model was still loading) --
    $scheduler = $null
    if ($WithScheduler) {
        $already = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -match 'artisan"?\s+schedule:work' })
        if ($already.Count -gt 0) {
            # Not skipped: the command line does not say which project it belongs
            # to, and a missing scheduler means a batch that never finishes. Two
            # are harmless - each task takes a lock, so it runs once a minute -
            # but only one is needed.
            Write-Host ('[launcher]   Another scheduler is already running (PID ' + (($already | ForEach-Object { $_.ProcessId }) -join ', ') + '), probably your old window. Two do no harm, but you can close the old one.') -ForegroundColor Yellow
        }
        Write-Host '[launcher]   Starting the scheduler.' -ForegroundColor Cyan
        $scheduler = Start-Child -Name 'scheduler' -File $php -Arguments 'artisan schedule:work' `
            -WorkingDirectory $script:Root -Color 'Magenta'
    }

    Write-Host ''
    Write-Host '[launcher]   Running. Leave this window open while you assess.' -ForegroundColor Green
    Write-Host ''

    # ---- supervise -----------------------------------------------------
    $warnedScheduler = $false
    while ($true) {
        Start-Sleep -Seconds 1

        if ($null -ne $engine -and $engine.Process.HasExited) {
            Write-Host "[launcher]   The engine stopped (exit code $($engine.Process.ExitCode)). Stopping everything." -ForegroundColor Red
            return 1
        }

        if ($null -ne $scheduler -and $scheduler.Process.HasExited -and -not $warnedScheduler) {
            # Single-child assessments do not need it, so the engine keeps running.
            Write-Host "[launcher]   The scheduler stopped (exit code $($scheduler.Process.ExitCode)). Batch assessments will not be scored until you start again." -ForegroundColor Red
            $warnedScheduler = $true
        }
    }
}

# Dot-sourcing (. .\start-bigkas.ps1) loads the functions and stops here, so
# they can be exercised without starting anything.
if ($MyInvocation.InvocationName -eq '.') { return }

$exitCode = 0
try {
    $exitCode = Invoke-Launcher -WithScheduler (-not $NoScheduler) -TimeoutSeconds $ReadyTimeoutSeconds
} catch {
    Write-Host ''
    Write-Host ('[launcher]   ' + $_.Exception.Message) -ForegroundColor Red
    $exitCode = 1
} finally {
    Write-Host ''
    Write-Host '[launcher]   Stopping...' -ForegroundColor Cyan
    Stop-Children
    Write-Host '[launcher]   Stopped.' -ForegroundColor Cyan
}

exit $exitCode
