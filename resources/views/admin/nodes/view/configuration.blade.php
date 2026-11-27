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
                <div style="margin: 15px 0">
                    <strong>Trusted native Minecraft (experimental)</strong>
                    <p>Controls only the single server directory explicitly enabled on this connector. Never use for untrusted tenants.</p>
                    @foreach (['start', 'stop', 'restart', 'kill', 'status', 'console', 'metrics'] as $action)
                        <button type="button" class="btn btn-default omcpPower" data-action="{{ $action }}">{{ ucfirst($action) }}</button>
                    @endforeach
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
                <p class="text-warning">Experimental: enrollment, diagnostics and opt-in native power controls. Console retrieves a recent output snapshot, not a live stream. Existing client game management still requires the legacy daemon.</p>
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
    function refreshOmcp() {
        $.getJSON('{{ route('admin.nodes.omcp.status', $node->id) }}').done(function (data) {
            omcpStatus.text(data.online ? 'Online — last heartbeat: ' + data.last_seen_at :
                (data.registered ? 'Offline' : 'Not registered'));
        }).fail(function () { omcpStatus.text('Status unavailable'); });
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
        if (action === 'kill' && !window.confirm('Force stop the trusted Minecraft process? Unsaved changes may be lost.')) return;
        if (action === 'install' && !$('#omcpEula').prop('checked')) {
            $('#omcpDiagnosticOutput').prop('hidden', false).text('Read and accept the Minecraft EULA first.');
            return;
        }
        clearTimeout(diagnosticTimer);
        const button = $('#omcpDiagnostic, .omcpPower').prop('disabled', true);
        const output = $('#omcpDiagnosticOutput').prop('hidden', false).text('Queueing ' + (action || 'diagnostic') + '...');
        $.ajax({
            method: 'POST',
            url: action === 'install' ? '{{ route('admin.nodes.omcp.install', $node->id) }}' : (action ? '{{ route('admin.nodes.omcp.power', $node->id) }}' : '{{ route('admin.nodes.omcp.diagnostic', $node->id) }}'),
            data: action === 'install' ? {software: $('#omcpSoftware').val(), version: $('#omcpVersion').val(), port: $('#omcpPort').val(), accept_eula: 1} : (action ? {action: action} : {}),
            dataType: 'json',
            headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'}
        }).done(function (data) {
            let attempts = 0;
            const url = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}'.replace('JOB_ID', encodeURIComponent(data.id));
            function poll() {
                $.getJSON(url).done(function (job) {
                    output.text(job.state + (job.result ? '\n' + job.result : ''));
                    if ((job.state === 'queued' || job.state === 'running') && ++attempts < (action === 'install' ? 270 : 70)) {
                        diagnosticTimer = setTimeout(poll, 5000);
                    } else button.prop('disabled', false);
                }).fail(function () { output.text('Unable to read diagnostic.'); button.prop('disabled', false); });
            }
            poll();
        }).fail(function (xhr) { output.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Unable to queue action.'); button.prop('disabled', false); });
    });
    refreshOmcp();
    setInterval(refreshOmcp, 30000);
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
