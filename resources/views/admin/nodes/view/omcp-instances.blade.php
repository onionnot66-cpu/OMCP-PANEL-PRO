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
    <h4>Experimental reservation registry</h4>
    <p>Reservations do not create or start Minecraft processes. Use a dedicated OMCP node; do not mix this registry with Wings or the single-server test runtime. Resource budgets are accounting values, not operating-system limits.</p>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">Reserve a server</h3></div>
            <form method="POST" action="{{ route('admin.nodes.omcp.instances.store', $node->id) }}">
                @csrf
                <div class="box-body">
                    <div class="form-group"><label for="name">Server name</label><input id="name" name="name" class="form-control" required maxlength="100" value="{{ old('name') }}"></div>
                    <div class="form-group"><label for="owner_id">Owner user ID</label><input id="owner_id" name="owner_id" type="number" class="form-control" min="1" required value="{{ old('owner_id') }}"></div>
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
                                <td>{{ ucfirst($instance->software) }} {{ $instance->version }}</td>
                                <td>{{ $instance->port }}</td><td>{{ $instance->memory_mib }} MiB</td><td>{{ $instance->disk_mib }} MiB</td>
                                <td>Reserved only</td>
                                <td><form method="POST" action="{{ route('admin.nodes.omcp.instances.destroy', [$node->id, $instance->id]) }}" onsubmit="return confirm('Release this reservation? No game files will be deleted.');">@csrf @method('DELETE')<button class="btn btn-danger btn-xs" type="submit">Release</button></form></td>
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
@endsection
