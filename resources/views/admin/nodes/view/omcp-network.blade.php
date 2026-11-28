@extends('layouts.admin')
@section('title')
    Game networking
@endsection
@section('content-header')
    <h1>Game networking <small>{{ $instance->name }}</small></h1>
@endsection
@section('content')
    <div class="omcp-tool-shell">
        <div class="omcp-tool-hero"><span class="omcp-tool-eyebrow">OMCP / Networking</span><h2>Public game endpoint</h2><p>Register an existing direct TCP endpoint or an externally configured tunnel. No tunnel is created here.</p></div>
        <div class="callout callout-warning"><p>Use an endpoint you control and have tested. Cloudflare records are DNS-only, not a Minecraft TCP proxy. Use the displayed port when connecting. DNS publication does not prove game reachability. Disable external forwarding before clearing an endpoint.</p></div>
        <div class="box">
            <div class="box-body">
                <form method="POST" action="{{ route('admin.nodes.omcp.instances.network.action', [$node->id, $instance->id]) }}">
                    @csrf
                    <input type="hidden" name="action" value="register">
                    <label for="provider">Endpoint provider</label>
                    <select id="provider" name="provider" class="form-control">
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected(($gameRoute->provider ?? 'direct') === $provider)>{{ ucfirst($provider) }}</option>
                        @endforeach
                    </select>
                    <label for="host">Public IP or hostname</label>
                    <input id="host" name="host" class="form-control" required maxlength="253" value="{{ $gameRoute->endpoint_host ?? '' }}">
                    <label for="port">Public TCP port</label>
                    <input id="port" name="port" type="number" min="1" max="65535" class="form-control" required value="{{ $gameRoute->endpoint_port ?? $instance->port }}">
                    <label><input type="checkbox" name="confirmed" value="1" required> I control this endpoint and authorize registration.</label><br>
                    <button class="btn btn-primary" @disabled($gameRoute && $gameRoute->dns_state !== 'none')>Save endpoint</button>
                </form>
            </div>
        </div>
        @if ($gameRoute)
            <div class="box"><div class="box-body">
                <h3>DNS subdomain</h3><p>State: {{ $gameRoute->dns_state }}</p>
                @if ($gameRoute->hostname)<p>{{ $gameRoute->hostname }}:{{ $gameRoute->endpoint_port }}</p>@endif
                @foreach (['publish' => 'Publish / reconcile DNS', 'unpublish' => 'Remove managed DNS', 'clear' => 'Clear endpoint'] as $action => $label)
                    <form method="POST" style="margin-top:12px" action="{{ route('admin.nodes.omcp.instances.network.action', [$node->id, $instance->id]) }}">
                        @csrf
                        <input type="hidden" name="action" value="{{ $action }}">
                        <label><input type="checkbox" name="confirmed" value="1" required> {{ $action === 'clear' ? 'I have disabled external forwarding.' : 'I authorize this DNS operation.' }}</label><br>
                        <button class="btn btn-default" @disabled($action === 'clear' && $gameRoute->dns_state !== 'none')>{{ $label }}</button>
                    </form>
                @endforeach
            </div></div>
        @endif
    </div>
@endsection
