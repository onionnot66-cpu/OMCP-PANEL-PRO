@extends('layouts.admin')

@section('title')
    {{ $node->name }}: OMCP instances
@endsection

@section('content-header')
    <h1>OMCP Instances <small>{{ $node->name }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li><a href="{{ route('admin.nodes.view.configuration', $node->id) }}">Configuration</a></li>
        <li class="active">OMCP instances</li>
    </ol>
@endsection

@section('content')
<div class="callout callout-warning">
    <h4>Experimental trusted instance management</h4>
    <p>Reserve an instance, then authorize its UUID, port and memory in the local connector fleet manifest before installing or starting it. Use a dedicated OMCP node; do not mix this registry with Wings or the single-server test runtime. Resource budgets are accounting values, not operating-system limits.</p>
</div>
<div class="checkbox"><label><input type="checkbox" id="fleetEula"> I have read and accept the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">Minecraft EULA</a> for installations.</label></div>
<pre id="fleetOutput" role="status" style="white-space:pre-wrap;max-height:350px;overflow:auto">No runtime job selected.</pre>
<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Reserve a server</h3></div>
            <form method="POST" action="{{ route('admin.nodes.omcp.instances.store', $node->id) }}">
                @csrf
                <div class="box-body">
                    <div class="form-group"><label for="name">Server name</label><input id="name" name="name" class="form-control" required maxlength="100" value="{{ old('name') }}"></div>
                    <div class="form-group"><label for="owner_id">Owner user ID</label><input id="owner_id" name="owner_id" type="number" class="form-control" min="1" required value="{{ old('owner_id') }}"></div>
                    <div class="form-group">
                        <label for="runtime">Required connector runtime</label>
                        <select id="runtime" name="runtime" class="form-control" required>
                            <option value="">Choose a runtime</option>
                            @foreach (['native' => 'Trusted native Java', 'podman' => 'Rootless Podman', 'docker' => 'Rootless Docker'] as $id => $label)
                                <option value="{{ $id }}" @selected(old('runtime') === $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group"><label for="software">Software</label><select id="software" name="software" class="form-control">@foreach (['paper', 'purpur', 'vanilla'] as $software)<option value="{{ $software }}" @selected(old('software', 'paper') === $software)>{{ ucfirst($software) }}</option>@endforeach</select></div>
                    <div class="form-group"><label for="version">Exact version</label><input id="version" name="version" class="form-control" placeholder="1.21.4" maxlength="32" required value="{{ old('version') }}"></div>
                    <div class="form-group"><label for="port">Reserved TCP port</label><input id="port" name="port" type="number" min="1024" max="65535" class="form-control" required value="{{ old('port', 25565) }}"></div>
                    <div class="form-group"><label for="memory_mib">RAM budget (MiB)</label><input id="memory_mib" name="memory_mib" type="number" min="128" max="1048576" class="form-control" required value="{{ old('memory_mib', 1024) }}"></div>
                    <div class="form-group"><label for="disk_mib">Disk budget (MiB)</label><input id="disk_mib" name="disk_mib" type="number" min="512" max="1073741824" class="form-control" required value="{{ old('disk_mib', 4096) }}"></div>
                </div>
                <div class="box-footer"><button type="submit" class="btn btn-primary">Reserve instance</button></div>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Node budgets</h3></div>
            <div class="box-body">
                <p>RAM reserved: {{ $instances->sum('memory_mib') }} / {{ $node->memory }} MiB</p>
                <p>Disk reserved: {{ $instances->sum('disk_mib') }} / {{ $node->disk }} MiB</p>
                <p>Reservations: {{ $instances->count() }} / 32</p>
            </div>
        </div>
    </div>
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Reserved instances</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Name / ID</th><th>Owner ID</th><th>Software</th><th>Port</th><th>RAM</th><th>Disk</th><th>State</th><th>Action</th></tr></thead>
                    <tbody>
                        @forelse ($instances as $instance)
                            <tr>
                                <td>{{ $instance->name }}<br><small>{{ $instance->id }}</small></td>
                                <td>{{ $instance->owner_id ?? 'Unassigned' }}</td>
                                <td>{{ ucfirst($instance->software) }} {{ $instance->version }}<br><small>{{ $instance->runtime ?? 'Legacy runtime selection' }}</small></td>
                                <td>{{ $instance->port }}</td><td>{{ $instance->memory_mib }} MiB</td><td>{{ $instance->disk_mib }} MiB</td>
                                <td>{{ $instance->runtime_requested_at ? 'Runtime requested; verify status' : 'Reserved' }}</td>
                                <td>
                                    <div class="btn-group" style="margin-bottom:6px">
                                        @foreach (['install','start','stop','restart','kill','status','console','metrics'] as $action)
                                            <button type="button" class="btn btn-default btn-xs omcpInstanceAction" data-action="{{ $action }}" data-url="{{ route('admin.nodes.omcp.instances.action', [$node->id, $instance->id]) }}">{{ ucfirst($action) }}</button>
                                        @endforeach
                                    </div>
                                    @if (!$instance->runtime_requested_at)
                                    <form method="POST" action="{{ route('admin.nodes.omcp.instances.destroy', [$node->id, $instance->id]) }}" onsubmit="return confirm('Release this reservation? No game files will be deleted.');">@csrf @method('DELETE')<button class="btn btn-danger btn-xs" type="submit">Release</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8">No reservations yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@if ($instances->isNotEmpty())
<div class="box box-primary">
    <div class="box-header with-border"><h3 class="box-title">Offline File Manager</h3></div>
    <div class="box-body">
        <p>Admin-only, requires stopped instance and local file opt-in. Text files up to 2 KiB; new/delete restricted to notes-*.txt. Do not put secrets in managed files.</p>
        <label for="fleetFileInstance">Instance</label>
        <select id="fleetFileInstance" class="form-control">
            @foreach ($instances as $instance)
                <option value="{{ route('admin.nodes.omcp.instances.files', [$node->id, $instance->id]) }}">{{ $instance->name }} ({{ $instance->id }})</option>
            @endforeach
        </select>
        <label for="fleetFileName">File name</label>
        <input id="fleetFileName" class="form-control" maxlength="64" placeholder="notes-example.txt">
        <p id="fleetFileList" style="white-space:pre-wrap"></p>
        <textarea id="fleetFileText" class="form-control" rows="10" spellcheck="false" aria-label="Text file contents"></textarea>
        <label for="fleetFileUpload">Load local UTF-8 text into editor</label><input id="fleetFileUpload" type="file" accept=".txt,.yml,.yaml,text/plain">
        <div style="margin:10px 0">
            @foreach (['files'=>'List files','file_read'=>'Read','file_write'=>'Save existing','new'=>'Create notes file','file_delete'=>'Delete notes file','download'=>'Download editor text'] as $action => $label)
                <button type="button" class="btn btn-default fleetFileAction" data-action="{{ $action }}">{{ $label }}</button>
            @endforeach
        </div>
        <hr>
        <h4>Server properties</h4>
        <select id="fleetPropertyKey" class="form-control" aria-label="Property">
            @foreach (['difficulty','gamemode','pvp','white-list','allow-flight','spawn-monsters','hardcore','max-players','view-distance','simulation-distance'] as $key)
                <option value="{{ $key }}">{{ $key }}</option>
            @endforeach
        </select>
        <input id="fleetPropertyValue" class="form-control" maxlength="32" placeholder="New value (e.g. true, hard, 40)" aria-label="Property value">
        <button type="button" class="btn btn-primary fleetFileAction" data-action="property">Apply property</button>
        <p id="fleetFileStatus" role="status"></p>
    </div>
</div>
@endif
@endsection

@section('footer-scripts')
    @parent
    <script>
        let loadedFile = null;
        $('#fleetFileInstance, #fleetFileName').on('change input', function () { loadedFile = null; });
        $('#fleetFileUpload').on('change', async function () {
            const file = this.files[0];
            if (!file) return;
            if (file.size > 2048) { $('#fleetFileStatus').text('Maximum 2048 bytes.'); return; }
            try {
                $('#fleetFileText').val(new TextDecoder('utf-8', {fatal:true}).decode(await file.arrayBuffer()));
                $('#fleetFileStatus').text('Local text loaded; not saved yet.');
            } catch (_) { $('#fleetFileStatus').text('Invalid UTF-8 file.'); }
        });
        $('.fleetFileAction').on('click', function () {
            const action = $(this).data('action');
            const url = $('#fleetFileInstance').val();
            const name = $('#fleetFileName').val();
            const text = $('#fleetFileText').val();
            const status = $('#fleetFileStatus');
            if (action === 'download') {
                const objectUrl = URL.createObjectURL(new Blob([text],{type:'text/plain;charset=utf-8'}));
                const link = document.createElement('a');
                link.href = objectUrl; link.download = /^[A-Za-z0-9_.-]{1,64}$/.test(name) ? name : 'omcp-text.txt';
                link.click(); setTimeout(() => URL.revokeObjectURL(objectUrl),1000); return;
            }
            const body = {action: action === 'new' ? 'file_write' : action};
            if (['file_read','file_write','file_delete','new'].includes(action)) body.file = name;
            if (action === 'file_write' || action === 'file_delete') {
                if (!loadedFile || loadedFile.url !== url || loadedFile.name !== name) { status.text('Read the exact file first.'); return; }
                body.expected = loadedFile.sha256;
            }
            if (action === 'new') body.expected = 'new';
            if (action === 'file_write' || action === 'new') {
                const bytes = new TextEncoder().encode(text);
                if (bytes.length > 2048) { status.text('Maximum 2048 bytes.'); return; }
                body.content_base64 = btoa(String.fromCharCode(...bytes));
            }
            if (action === 'file_delete' && !window.confirm('Delete this notes file?')) return;
            if (action === 'property') { body.key = $('#fleetPropertyKey').val(); body.value = $('#fleetPropertyValue').val(); }
            const controls = $('.fleetFileAction, #fleetFileInstance, #fleetFileName, #fleetFileText, #fleetFileUpload, #fleetPropertyKey, #fleetPropertyValue').prop('disabled',true);
            status.text('Queueing...');
            const finish = () => controls.prop('disabled',false);
            $.ajax({method:'POST',url:url,data:body,dataType:'json',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}})
                .done(function (data) {
                    const resultUrl = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}'.replace('JOB_ID',encodeURIComponent(data.id));
                    let attempts = 0;
                    function pollFile() {
                        $.getJSON(resultUrl).done(function (job) {
                            status.text(job.state);
                            if ((job.state === 'queued' || job.state === 'running') && ++attempts < 50) { setTimeout(pollFile,5000); return; }
                            try {
                                if (job.state === 'succeeded') {
                                    const result = JSON.parse(job.result);
                                    if (result.files) $('#fleetFileList').text(result.files.join('\n') + (result.limited ? '\n(List limited)' : ''));
                                    if (action === 'file_read') {
                                        const bytes = Uint8Array.from(atob(result.content_base64), c => c.charCodeAt(0));
                                        $('#fleetFileText').val(new TextDecoder('utf-8',{fatal:true}).decode(bytes));
                                        loadedFile = {url:url,name:name,sha256:result.sha256};
                                    }
                                    if (result.saved) loadedFile = {url:url,name:name,sha256:result.sha256};
                                    if (result.deleted) { loadedFile = null; $('#fleetFileText').val(''); }
                                } else if (job.result) status.text(job.state + ': ' + job.result);
                            } catch (_) { status.text('Invalid job response.'); }
                            finish();
                        }).fail(function () { status.text('Result unavailable.'); finish(); });
                    }
                    pollFile();
                }).fail(function (xhr) { status.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'File action failed.'); finish(); });
        });
        $('.omcpInstanceAction').on('click', function () {
            const action = $(this).data('action');
            if (action === 'install' && !$('#fleetEula').prop('checked')) {
                $('#fleetOutput').text('Read and accept the EULA first.');
                return;
            }
            if (action === 'kill' && !window.confirm('Force stop? Unsaved changes may be lost.')) return;
            const controls = $('.omcpInstanceAction').prop('disabled', true);
            const output = $('#fleetOutput').text('Queueing ' + action + '...');
            $.ajax({method:'POST',url:$(this).data('url'),data:{action:action,accept_eula:$('#fleetEula').prop('checked') ? 1 : 0},dataType:'json',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}})
                .done(function (data) {
                    const url = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}'.replace('JOB_ID', encodeURIComponent(data.id));
                    let attempts = 0;
                    function poll() {
                        $.getJSON(url).done(function (job) {
                            output.text(job.state + (job.result ? '\n' + job.result : ''));
                            if ((job.state === 'queued' || job.state === 'running') && ++attempts < 270) setTimeout(poll,5000);
                            else controls.prop('disabled',false);
                        }).fail(function () { output.text('Job result unavailable.'); controls.prop('disabled',false); });
                    }
                    poll();
                }).fail(function (xhr) { output.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Action failed.'); controls.prop('disabled',false); });
        });
    </script>
@endsection
