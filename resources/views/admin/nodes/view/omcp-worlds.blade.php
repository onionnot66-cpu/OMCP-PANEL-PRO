@extends('layouts.admin')
@section('title')
    {{ $instance->name }}: Worlds & backups
@endsection
@section('content-header')
    <h1>World Manager <small>{{ $instance->name }}</small></h1>
    <ol class="breadcrumb"><li><a href="{{ route('admin.nodes.omcp.instances', $node->id) }}">Instances</a></li><li class="active">Worlds</li></ol>
@endsection
@section('content')
<div class="callout callout-warning">
    <h4>Offline world operations</h4>
    <p>Stop the server. Enable --allow-fleet-worlds on its connector. A backup covers one world directory, not all server files. Five backups per instance, each up to 256 MiB of world contents. Restore creates a new folder and never replaces the original world. Only Vanilla supports automatic activation. Paper/Purpur dimension folders need independent backups and manual configuration.</p>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">World directories</h3></div>
            <div class="box-body">
                <button type="button" id="worldRefresh" class="btn btn-default worldAction">Refresh worlds</button>
                <div id="worldList" style="margin-top:12px"></div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Local world backups</h3></div>
            <div class="box-body">
                <button type="button" id="backupRefresh" class="btn btn-default worldAction">Refresh backups</button>
                <label for="restoreName">New restore folder</label>
                <input id="restoreName" class="form-control" maxlength="48" placeholder="restored-world">
                <div id="backupList" style="margin-top:12px"></div>
            </div>
        </div>
    </div>
</div>
<pre id="worldStatus" role="status" style="white-space:pre-wrap;overflow-wrap:anywhere">No job submitted.</pre>
@endsection
@section('footer-scripts')
@parent
<script>
(function () {
    const url = '{{ route('admin.nodes.omcp.instances.worlds.action', [$node->id, $instance->id]) }}';
    const resultUrl = '{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}';
    const status = $('#worldStatus');
    let busy = false;
    function renderWorlds(names) {
        const list = $('#worldList').empty();
        names.forEach(name => {
            const row = $('<div>').addClass('well well-sm');
            $('<strong>').text(name).appendTo(row);
            $('<button type="button">').addClass('btn btn-default btn-xs worldAction').text('Backup').on('click', () => {
                if (window.confirm('Create a local backup of this stopped world?')) submit({action:'world_backup',world:name,confirmed:1});
            }).appendTo(row);
            @if ($instance->software === 'vanilla')
            $('<button type="button">').addClass('btn btn-primary btn-xs worldAction').text('Activate on restart').on('click', () => {
                if (window.confirm('Change active Vanilla world? Existing worlds are retained.')) submit({action:'world_activate',world:name,confirmed:1});
            }).appendTo(row);
            @endif
            row.appendTo(list);
        });
        if (!names.length) list.text('No recognized world folders.');
    }
    function renderBackups(items) {
        const list = $('#backupList').empty();
        items.forEach(item => {
            const row = $('<div>').addClass('well well-sm');
            $('<strong>').text(item.world + ' · ' + item.bytes + ' bytes').appendTo(row);
            $('<p>').css('overflow-wrap','anywhere').text(item.id + ' | SHA-256: ' + item.sha256).appendTo(row);
            $('<button type="button">').addClass('btn btn-primary btn-xs worldAction').text('Restore copy').on('click', () => {
                const target = $('#restoreName').val();
                if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/.test(target)) {status.text('Enter a safe new world folder name.');return;}
                if (window.confirm('Restore into new folder ' + target + '?')) submit({action:'world_restore',world:target,backup_id:item.id,backup_sha256:item.sha256,confirmed:1});
            }).appendTo(row);
            row.appendTo(list);
        });
        if (!items.length) list.text('No local backups.');
    }
    function submit(body) {
        if (busy) return;
        busy = true; $('.worldAction').prop('disabled',true);
        status.text('Submitting...');
        const finish = () => {busy=false;$('.worldAction').prop('disabled',false);};
        $.ajax({url:url,method:'POST',data:body,dataType:'json',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}})
            .done(data => {
                let attempts=0;
                function poll() {
                    $.getJSON(resultUrl.replace('JOB_ID',encodeURIComponent(data.id))).done(job => {
                        status.text(job.state + ': ' + (job.result || ''));
                        if (['queued','running'].includes(job.state) && ++attempts<420) {setTimeout(poll,3000);return;}
                        try {
                            if (job.state==='succeeded' && body.action==='world_list') renderWorlds(JSON.parse(job.result));
                            if (job.state==='succeeded' && body.action==='world_backup_list') renderBackups(JSON.parse(job.result));
                        } catch (_) {status.text('Invalid world result.');}
                        finish();
                    }).fail(() => {status.text('Job unavailable.');finish();});
                }
                poll();
            }).fail(xhr => {status.text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'World job rejected.');finish();});
    }
    $('#worldRefresh').on('click',()=>submit({action:'world_list'}));
    $('#backupRefresh').on('click',()=>submit({action:'world_backup_list'}));
})();
</script>
@endsection
