@extends('layouts.admin')
@section('title')
    Minecraft version changer
@endsection
@section('content-header')
    <h1>Version Changer <small>{{ $instance->name }}</small></h1>
    <ol class="breadcrumb"><li><a href="{{ route('admin.nodes.omcp.instances', $node->id) }}">Instances</a></li><li class="active">Versions</li></ol>
@endsection
@section('content')
<div class="omcp-tool-shell">
    <div class="omcp-tool-hero"><span class="omcp-tool-eyebrow">OMCP / Game tools</span><h2>Upgrade Minecraft</h2><p>{{ ucfirst($instance->software) }} {{ $instance->version }} · {{ $instance->runtime }}</p></div>
    <div class="callout callout-warning"><h4>Offline upgrades only</h4><p>Stop the server, enable --allow-fleet-versions, and independently verify a complete server backup. Choose a newer exact release of the same software. Check Java, plugin and world compatibility first. An old JAR is retained outside the game directory, but it cannot undo world format changes. The server is not restarted automatically.</p></div>
    <div class="box box-primary"><div class="box-body">
        @if (!$instance->version_job_id)
            <form method="POST" action="{{ route('admin.nodes.omcp.instances.versions.action', [$node->id, $instance->id]) }}">
                @csrf
                <input type="hidden" name="action" value="version_change">
                <label for="targetVersion">Target Minecraft release</label><input id="targetVersion" name="target_version" class="form-control" required maxlength="32" placeholder="Exact newer release">
                <p><label><input type="checkbox" name="accept_eula" value="1" required> I accept the <a href="https://www.minecraft.net/eula" target="_blank" rel="noopener noreferrer">Minecraft EULA</a>.</label></p>
                <p><label><input type="checkbox" name="backup_confirmed" value="1" required> I have independently verified a complete server backup and checked compatibility.</label></p>
                <p><label><input type="checkbox" name="confirmed" value="1" required> I authorize this stopped server upgrade.</label></p>
                <button class="btn btn-primary">Download &amp; upgrade</button>
            </form>
        @else
            <h3>Pending: {{ $instance->version }} → {{ $instance->version_target }}</h3>
            <p>Start and other mutations are blocked until a consistent installed version is confirmed.</p>
            <pre>{{ $job->state ?? 'unavailable' }}: {{ $job->result ?? '' }}</pre>
            <a class="btn btn-default" href="{{ route('admin.nodes.omcp.instances.versions', [$node->id, $instance->id]) }}">Refresh job</a>
            @foreach (['finalize' => 'Finalize verified receipt', 'version_inspect' => 'Inspect / reconcile installed version'] as $action => $label)
                <form method="POST" style="margin-top:12px" action="{{ route('admin.nodes.omcp.instances.versions.action', [$node->id, $instance->id]) }}">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <label><input type="checkbox" name="confirmed" value="1" required> I authorize this operation.</label><br>
                    <button class="btn btn-primary" @disabled($action === 'finalize' && ($job->state ?? '') !== 'succeeded')>{{ $label }}</button>
                </form>
            @endforeach
        @endif
    </div></div>
</div>
@endsection
