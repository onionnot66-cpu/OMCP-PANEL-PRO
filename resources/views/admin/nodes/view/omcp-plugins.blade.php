@extends('layouts.admin')

@section('title')
    {{ $instance->name }}: Plugin manager
@endsection

@section('content-header')
    <h1>Plugin Manager <small>{{ $instance->name }}</small></h1>
    <ol class="breadcrumb"><li><a href="{{ route('admin.nodes.omcp.instances', $node->id) }}">Instances</a></li><li class="active">Plugins</li></ol>
@endsection

@section('content')
<div class="omcp-tool-shell">
    <div class="omcp-tool-hero"><span class="omcp-tool-eyebrow">OMCP / Game tools</span><h2>Discover &amp; manage plugins</h2><p>{{ $instance->name }} · {{ ucfirst($instance->software) }} {{ $instance->version }} · Modrinth + Hangar</p></div>
<div class="callout callout-warning">
    <h4>Trusted admin-only plugin management</h4>
    <p>{{ ucfirst($instance->software) }} {{ $instance->version }} · {{ $instance->runtime }}. Stop the server first. Enable --allow-fleet-plugins locally. Only compatible stable versions with verified SHA-512 (Modrinth) or SHA-256 (Hangar) and no required/incompatible dependencies are installable. Review the publisher, license and plugin code before trusting a JAR. Existing JARs will not be overwritten. Data directories are retained on deletion.</p>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Modrinth catalog / Hangar</h3></div>
            <div class="box-body">
                <label for="pluginProvider">Plugin provider</label>
                <select id="pluginProvider" class="form-control" style="margin-bottom:12px"><option value="modrinth">Modrinth</option><option value="hangar">Hangar</option></select>
                <div class="input-group"><input id="pluginQuery" maxlength="100" class="form-control" placeholder="Search plugins" aria-label="Plugin search"><span class="input-group-btn"><button id="pluginSearch" class="btn btn-primary" type="button">Search</button></span></div>
                <p id="pluginCatalogStatus" role="status"></p>
                <div id="pluginResults" class="omcp-result-grid"></div>
                <label for="pluginVersions">Compatible releases</label>
                <select id="pluginVersions" class="form-control" disabled></select>
                <p id="pluginDetails" style="overflow-wrap:anywhere"></p>
                <button id="pluginInstall" class="btn btn-primary" type="button" disabled>Install selected release</button>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">OMCP-managed plugins</h3></div>
            <div class="box-body">
                <button id="pluginRefresh" type="button" class="btn btn-default">Refresh installed list</button>
                <div id="pluginInventory" class="omcp-result-grid"></div>
                <pre id="pluginJobStatus" role="status" style="white-space:pre-wrap">No job submitted.</pre>
            </div>
        </div>
    </div>
</div>
</div>
@endsection

@section('footer-scripts')
@parent
<script>
(function () {
    const catalogUrl = '{{ route('admin.nodes.omcp.instances.plugins.catalog', [$node->id, $instance->id]) }}';
    const actionUrl = '{{ route('admin.nodes.omcp.instances.plugins.action', [$node->id, $instance->id]) }}';
    const resultUrl = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}';
    const catalogStatus = $('#pluginCatalogStatus'), status = $('#pluginJobStatus');
    let project = null, versions = [], busy = false;
    const provider = () => $('#pluginProvider').val();
    function selected() { return versions.find(v => v.id === $('#pluginVersions').val()); }
    function refreshControls() {
        $('#pluginInstall').prop('disabled', busy || !selected());
        $('#pluginRefresh, .pluginMutation').prop('disabled', busy);
    }
    function showVersion() {
        const v = selected();
        $('#pluginDetails').text(v ? v.filename + ' | ' + v.size + ' bytes | ' + (v.sha256 ? 'SHA-256: ' + v.sha256 : 'SHA-512: ' + v.sha512) : '');
        refreshControls();
    }
    function loadVersions(id) {
        const source = provider();
        project = id; versions = [];
        $('#pluginVersions').empty().prop('disabled', true);
        showVersion();
        catalogStatus.text('Loading compatible releases...');
        $.getJSON(catalogUrl, {project:id,provider:source}).done(function (data) {
            if (project !== id || provider() !== source) return;
            versions = data;
            data.forEach(v => $('<option>').val(v.id).text(v.version + ' — ' + v.filename).appendTo('#pluginVersions'));
            $('#pluginVersions').prop('disabled', !data.length);
            catalogStatus.text(data.length ? 'Select a release.' : 'No eligible stable release. Dependencies may require manual review.');
            showVersion();
        }).fail(function () { if (project === id) catalogStatus.text('Version lookup failed.'); });
    }
    $('#pluginVersions').on('change', showVersion);
    $('#pluginProvider').on('change', () => { project=null; versions=[]; $('#pluginResults, #pluginVersions').empty(); $('#pluginVersions').prop('disabled',true); showVersion(); });
    $('#pluginSearch').on('click', function () {
        const source = provider();
        const button = $(this).prop('disabled', true);
        project = null; versions = []; $('#pluginVersions').empty().prop('disabled', true); showVersion();
        $('#pluginResults').empty(); catalogStatus.text('Searching...');
        $.getJSON(catalogUrl, {query:$('#pluginQuery').val(),provider:source}).done(function (data) {
            if (provider() !== source) return;
            data.forEach(p => {
                const row = $('<div>').addClass('well well-sm');
                $('<strong>').text(p.title).appendTo(row);
                $('<p>').text(p.description).appendTo(row);
                $('<small>').text(p.author + ' · ' + p.license + ' · ' + p.downloads + ' downloads').appendTo(row);
                $('<a>').attr({href:source==='hangar' && /^https:\/\/hangar\.papermc\.io\/[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+$/.test(p.source_url) ? p.source_url : 'https://modrinth.com/plugin/' + encodeURIComponent(p.id),target:'_blank',rel:'noopener noreferrer'}).text(' Review source page ').appendTo(row);
                $('<button type="button">').addClass('btn btn-default btn-xs').text('Versions').on('click', () => loadVersions(p.id)).appendTo(row);
                row.appendTo('#pluginResults');
            });
            catalogStatus.text(data.length ? 'Select a project and review it.' : 'No results.');
        }).fail(() => catalogStatus.text('Search unavailable.')).always(() => button.prop('disabled', false));
    });
    function showInventory(names) {
        const area = $('#pluginInventory').empty();
        if (!names.length) { area.text('No managed plugins.'); return; }
        names.forEach(name => {
            const hangar = /^omcp-hangar-([1-9][0-9]{0,18})-([1-9][0-9]{0,18})\.jar(\.disabled)?$/.exec(name);
            const m = hangar || /^omcp-([A-Za-z0-9]{3,32})-([A-Za-z0-9]{3,32})\.jar(\.disabled)?$/.exec(name);
            if (!m) return;
            const row = $('<div>').addClass('well well-sm');
            $('<p>').text(name).appendTo(row);
            const body = {plugin_provider:hangar?'hangar':'modrinth',plugin_project:m[1],plugin_version:m[2],plugin_disabled:m[3] ? 1 : 0,confirmed:1};
            $('<button type="button">').addClass('btn btn-default btn-xs pluginMutation').text(m[3] ? 'Enable' : 'Disable').on('click', () => {
                if (window.confirm('Change this managed plugin?')) submit({...body,action:m[3] ? 'plugin_enable' : 'plugin_disable'});
            }).appendTo(row);
            $('<button type="button">').addClass('btn btn-danger btn-xs pluginMutation').text('Delete JAR').on('click', () => {
                if (window.confirm('Delete only this managed JAR? Plugin data folders remain.')) submit({...body,action:'plugin_delete'});
            }).appendTo(row);
            row.appendTo(area);
        });
    }
    function submit(body) {
        if (busy) return;
        busy = true; refreshControls(); status.text('Submitting...');
        const finish = () => { busy = false; refreshControls(); };
        $.ajax({url:actionUrl,method:'POST',data:body,dataType:'json',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}})
            .done(data => {
                let attempts = 0;
                function poll() {
                    $.getJSON(resultUrl.replace('JOB_ID',encodeURIComponent(data.id))).done(job => {
                        status.text(job.state + ': ' + (job.result || ''));
                        if (['queued','running'].includes(job.state) && ++attempts < 90) { setTimeout(poll,3000); return; }
                        if (job.state === 'succeeded' && body.action === 'plugin_list') {
                            try { showInventory(JSON.parse(job.result)); } catch (_) { status.text('Invalid inventory response.'); }
                        }
                        finish();
                        if (job.state === 'succeeded' && body.action !== 'plugin_list') submit({action:'plugin_list'});
                    }).fail(() => { status.text('Job result unavailable.'); finish(); });
                }
                poll();
            }).fail(xhr => { status.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Plugin job rejected.'); finish(); });
    }
    $('#pluginRefresh').on('click', () => submit({action:'plugin_list'}));
    $('#pluginInstall').on('click', () => {
        const v = selected();
        if (v && project && window.confirm('Install this exact verified release from a trusted publisher? A plugin is executable code.')) {
            submit({action:'plugin_install',plugin_provider:provider(),plugin_project:project,plugin_version:v.id,...(v.sha256?{plugin_sha256:v.sha256}:{plugin_sha512:v.sha512}),confirmed:1});
        }
    });
})();
</script>
@endsection
