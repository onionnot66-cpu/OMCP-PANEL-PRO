@extends('layouts.admin')

@section('title')
    {{ $node->name }}: Configuration
@endsection

@section('content-header')
    <h1>{{ $node->name }}<small>Your daemon configuration file.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li><a href="{{ route('admin.nodes.view', $node->id) }}">{{ $node->name }}</a></li>
        <li class="active">Configuration</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="nav-tabs-custom nav-tabs-floating">
            <ul class="nav nav-tabs">
                <li><a href="{{ route('admin.nodes.view', $node->id) }}">About</a></li>
                <li><a href="{{ route('admin.nodes.view.settings', $node->id) }}">Settings</a></li>
                <li class="active"><a href="{{ route('admin.nodes.view.configuration', $node->id) }}">Configuration</a></li>
                <li><a href="{{ route('admin.nodes.view.allocation', $node->id) }}">Allocation</a></li>
                <li><a href="{{ route('admin.nodes.view.servers', $node->id) }}">Servers</a></li>
            </ul>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">OMCP Connect</h3></div>
            <div class="box-body">
                <p>Outbound connector enrollment for local or remote Linux nodes. No Wings or Docker needed for registration.</p>
                <p id="omcpStatus" role="status">Checking connection...</p>
                <button type="button" class="btn btn-primary" id="omcpEnroll">Generate enrollment</button>
                <button type="button" class="btn btn-default" id="omcpRotate">Revoke and re-enroll</button>
                <button type="button" class="btn btn-default" id="omcpDiagnostic">Run diagnostic</button>
                <a class="btn btn-default" href="{{ route('admin.nodes.omcp.instances', $node->id) }}">Instance reservations</a>
                <div style="margin: 15px 0">
                    <strong>Trusted native Minecraft (experimental)</strong>
                    <p>Controls only the single server directory explicitly enabled on this connector. Never use for untrusted tenants.</p>
                    @foreach (['start', 'stop', 'restart', 'kill', 'status', 'console', 'metrics'] as $action)
                        <button type="button" class="btn btn-default omcpPower" data-action="{{ $action }}">{{ ucfirst($action) }}</button>
                    @endforeach
                </div>
                <div class="well">
                    <h4>Native console &amp; resource monitor</h4>
                    <p id="omcpNativeMetrics">Waiting for native telemetry...</p>
                    <pre id="omcpLiveConsole" style="background:#171329;color:#e9e5ff;white-space:pre-wrap;overflow:auto;height:220px" aria-label="Recent Minecraft console">Waiting for connector...</pre>
                    <label for="omcpConsoleCommand">Minecraft command (restricted)</label>
                    <div class="input-group">
                        <input id="omcpConsoleCommand" maxlength="256" class="form-control" placeholder="list, save-all, say Hello" autocomplete="off">
                        <span class="input-group-btn"><button type="button" id="omcpSendCommand" class="btn btn-primary omcpPower" data-action="command" disabled>Send</button></span>
                    </div>
                    <p class="help-block">Requires local console opt-in. Output refreshes approximately every five seconds; this is not a WebSocket stream.</p>
                </div>
                <div class="well">
                    <h4>Create a trusted Minecraft test server</h4>
                    <p>Requires connector started with explicit local provisioning enabled and an unused directory. Existing worlds are never overwritten.</p>
                    <div class="row">
                        <div class="col-sm-4"><label for="omcpSoftware">Software</label><select id="omcpSoftware" class="form-control"><option value="paper">Paper</option><option value="purpur">Purpur</option><option value="vanilla">Vanilla</option></select></div>
                        <div class="col-sm-4"><label for="omcpVersion">Exact Minecraft version</label><input id="omcpVersion" class="form-control" placeholder="1.21.4" maxlength="32"></div>
                        <div class="col-sm-4"><label for="omcpPort">TCP port</label><input id="omcpPort" class="form-control" type="number" min="1024" max="65535" value="25565"></div>
                    </div>
                    <div class="checkbox"><label><input type="checkbox" id="omcpEula"> I have read and accept the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">Minecraft EULA</a>.</label></div>
                    <button type="button" class="btn btn-primary omcpPower" data-action="install">Install Minecraft</button>
                </div>
                <pre id="omcpDiagnosticOutput" role="status" style="white-space:pre-wrap;max-height:400px;overflow:auto" hidden></pre>
                <div id="omcpInstructions" hidden>
                    <p>Run the command on your node and supply the token privately when prompted. Token expires after 15 minutes.</p>
                    <pre id="omcpCommand"></pre>
                    <input id="omcpToken" type="password" readonly class="form-control" autocomplete="off" aria-label="One-time enrollment token">
                    <button type="button" class="btn btn-default" id="omcpReveal">Show / hide token</button>
                </div>
                <p class="text-warning">Experimental: enrollment, diagnostics and opt-in native power controls. Console refreshes a bounded output snapshot, not a full log stream. Existing client game management still requires the legacy daemon.</p>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-sm-8">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Configuration File</h3>
            </div>
            <div class="box-body">
                <pre class="no-margin">{{ $node->getYamlConfiguration() }}</pre>
            </div>
            <div class="box-footer">
                <p class="no-margin">This file should be placed in your daemon's root directory (usually <code>/etc/pterodactyl</code>) in a file called <code>config.yml</code>.</p>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Auto-Deploy</h3>
            </div>
            <div class="box-body">
                <p class="text-muted small">
                    Use the button below to generate a custom deployment command that can be used to configure
                    wings on the target server with a single command.
                </p>
            </div>
            <div class="box-footer">
                <button type="button" id="configTokenBtn" class="btn btn-sm btn-default" style="width:100%;">Generate Token</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>
    const omcpStatus = $('#omcpStatus');
    let consoleAllowed = false;
    let jobPending = false;
    function refreshOmcp() {
        $.getJSON('{{ route('admin.nodes.omcp.status', $node->id) }}').done(function (data) {
            omcpStatus.text(data.online ? 'Online — last heartbeat: ' + data.last_seen_at + (data.capabilities && data.capabilities.runtime ? ' · Runtime: ' + data.capabilities.runtime : '') :
                (data.registered ? 'Offline' : 'Not registered'));
            consoleAllowed = !!(data.online && data.capabilities && data.capabilities.native_console_input);
            $('#omcpSendCommand').prop('disabled', jobPending || !consoleAllowed);
            const telemetry = data.telemetry;
            const view = $('#omcpLiveConsole');
            if (!telemetry) {
                view.text('No recent native telemetry.');
                $('#omcpNativeMetrics').text('Metrics unavailable.');
                return;
            }
            const element = view[0];
            const nearBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 30;
            if (view.text() !== telemetry.console) {
                view.text(telemetry.console);
                if (nearBottom) element.scrollTop = element.scrollHeight;
            }
            const m = telemetry.metrics || {};
            const mib = n => typeof n === 'number' ? (n / 1048576).toFixed(1) + ' MiB' : 'N/A';
            const cpu = typeof m.cpu_host_percent === 'number' ? m.cpu_host_percent.toFixed(2) + '%' : 'N/A';
            $('#omcpNativeMetrics').text((m.process_running ? 'Running' : 'Stopped') +
                ' | CPU (host share): ' + cpu + ' | Java RSS: ' + mib(m.java_rss_bytes) +
                ' | Heap limit: ' + m.java_heap_limit_mib + ' MiB | Filesystem free: ' + mib(m.filesystem_available_bytes));
        }).fail(function () {
            omcpStatus.text('Status unavailable');
            consoleAllowed = false;
            $('#omcpSendCommand').prop('disabled', true);
            $('#omcpNativeMetrics').text('Unable to refresh telemetry; displayed console may be stale.');
        });
    }
    function issueOmcp(rotate) {
        $('#omcpInstructions').prop('hidden', true);
        $('#omcpToken').val('').attr('type', 'password');
        $.ajax({
            method: 'POST',
            url: '{{ route('admin.nodes.omcp.enroll', $node->id) }}',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'},
            data: {rotate: rotate ? 1 : 0}
        }).done(function (data) {
            $('#omcpCommand').text(data.command);
            $('#omcpToken').val(data.token);
            $('#omcpInstructions').prop('hidden', false);
            refreshOmcp();
        }).fail(function (xhr) {
            omcpStatus.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Enrollment failed.');
        });
    }
    $('#omcpEnroll').on('click', function () { issueOmcp(false); });
    $('#omcpRotate').on('click', function () {
        if (window.confirm('Revoke the existing connector credential?')) issueOmcp(true);
    });
    $('#omcpReveal').on('click', function () {
        const token = $('#omcpToken');
        token.attr('type', token.attr('type') === 'password' ? 'text' : 'password');
    });
    let diagnosticTimer;
    $('#omcpDiagnostic, .omcpPower').on('click', function () {
        const action = $(this).data('action');
        const command = $('#omcpConsoleCommand').val();
        if (jobPending || (action === 'command' && (!consoleAllowed || !command.trim()))) return;
        if (action === 'kill' && !window.confirm('Force stop the trusted Minecraft process? Unsaved changes may be lost.')) return;
        if (action === 'install' && !$('#omcpEula').prop('checked')) {
            $('#omcpDiagnosticOutput').prop('hidden', false).text('Read and accept the Minecraft EULA first.');
            return;
        }
        clearTimeout(diagnosticTimer);
        jobPending = true;
        const button = $('#omcpDiagnostic, .omcpPower').prop('disabled', true);
        function release() { jobPending = false; button.prop('disabled', false); $('#omcpSendCommand').prop('disabled', !consoleAllowed); }
        const output = $('#omcpDiagnosticOutput').prop('hidden', false).text('Queueing ' + (action || 'diagnostic') + '...');
        $.ajax({
            method: 'POST',
            url: action === 'command' ? '{{ route('admin.nodes.omcp.command', $node->id) }}' : action === 'install' ? '{{ route('admin.nodes.omcp.install', $node->id) }}' : (action ? '{{ route('admin.nodes.omcp.power', $node->id) }}' : '{{ route('admin.nodes.omcp.diagnostic', $node->id) }}'),
            data: action === 'command' ? {command: command} : action === 'install' ? {software: $('#omcpSoftware').val(), version: $('#omcpVersion').val(), port: $('#omcpPort').val(), accept_eula: 1} : (action ? {action: action} : {}),
            dataType: 'json',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'}
        }).done(function (data) {
            if (action === 'command') $('#omcpConsoleCommand').val('');
            let attempts = 0;
            const url = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}'.replace('JOB_ID', encodeURIComponent(data.id));
            function poll() {
                $.getJSON(url).done(function (job) {
                    output.text(job.state + (job.result ? '\n' + job.result : ''));
                    if ((job.state === 'queued' || job.state === 'running') && ++attempts < (action === 'install' ? 270 : 70)) {
                        diagnosticTimer = setTimeout(poll, 5000);
                    } else release();
                }).fail(function () { output.text('Unable to read diagnostic.'); release(); });
            }
            poll();
        }).fail(function (xhr) { output.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Unable to queue action.'); release(); });
    });
    refreshOmcp();
    setInterval(refreshOmcp, 5000);
    $('#configTokenBtn').on('click', function (event) {
        $.ajax({
            method: 'POST',
            url: '{{ route('admin.nodes.view.configuration.token', $node->id) }}',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        }).done(function (data) {
            swal({
                type: 'success',
                title: 'Token created.',
                text: '<p>To auto-configure your node run the following command:<br /><small><pre>cd /etc/pterodactyl && sudo wings configure --panel-url {{ config('app.url') }} --token ' + data.token + ' --node ' + data.node + '{{ config('app.debug') ? ' --allow-insecure' : '' }}</pre></small></p>',
                html: true
            })
        }).fail(function () {
            swal({
                title: 'Error',
                text: 'Something went wrong creating your token.',
                type: 'error'
            });
        });
    });
    </script>
@endsection
