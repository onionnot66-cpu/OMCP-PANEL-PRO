@extends('layouts.admin')
@section('title')
    Modpack manager
@endsection
@section('content-header')
    <h1>Modpack Manager <small>{{ $instance->name }}</small></h1>
    <ol class="breadcrumb"><li><a href="{{ route('admin.nodes.omcp.instances', $node->id) }}">Instances</a></li><li class="active">Modpacks</li></ol>
@endsection
@section('content')
<div class="omcp-tool-shell">
    <div class="omcp-tool-hero"><span class="omcp-tool-eyebrow">OMCP / Game tools</span><h2>Discover modpacks</h2><p>Modrinth · Minecraft {{ $instance->version }} · Verified package staging</p></div>
    <div class="callout callout-warning"><h4>Staging only — not installed</h4><p>Enable --allow-fleet-modpacks locally. This downloads and checks one .mrpack outside the game directory. It does not install a loader, extract overrides, download mods, start Minecraft, or change the reserved server. Review all executable content and licenses before any future installation.</p></div>
    <div class="row"><div class="col-md-6"><div class="box box-primary"><div class="box-body">
        <label for="packLoader">Required loader</label><select id="packLoader" class="form-control"><option value="fabric">Fabric</option><option value="forge">Forge</option><option value="neoforge">NeoForge</option><option value="quilt">Quilt</option></select>
        <label for="packQuery">Search Modrinth</label><div class="input-group"><input id="packQuery" class="form-control" maxlength="100" placeholder="Modpack name"><span class="input-group-btn"><button type="button" id="packSearch" class="btn btn-primary">Search</button></span></div>
        <p id="packCatalogStatus" role="status"></p><div id="packResults" class="omcp-result-grid"></div>
    </div></div></div><div class="col-md-6"><div class="box"><div class="box-body">
        <label for="packVersion">Compatible stable release</label><select id="packVersion" class="form-control" disabled></select>
        <p id="packDetails" style="overflow-wrap:anywhere"></p><button type="button" id="packStage" class="btn btn-primary" disabled>Stage verified package</button>
        <pre id="packJob" role="status" style="white-space:pre-wrap">No job submitted.</pre>
    </div></div></div></div>
</div>
@endsection
@section('footer-scripts')
@parent
<script>
(function(){
    const catalog='{{ route('admin.nodes.omcp.instances.modpacks.catalog', [$node->id, $instance->id]) }}';
    const stage='{{ route('admin.nodes.omcp.instances.modpacks.stage', [$node->id, $instance->id]) }}';
    const result='{{ route('admin.nodes.omcp.diagnostic.result', [$node->id, 'JOB_ID']) }}';
    let project=null, versions=[], busy=false, generation=0;
    const loader=()=>$('#packLoader').val(), current=()=>versions.find(v=>v.id===$('#packVersion').val());
    const status=$('#packCatalogStatus'), output=$('#packJob');
    function refresh(){
        const v=current();
        $('#packDetails').text(v ? v.filename+' · '+v.size+' bytes · SHA-512 '+v.sha512 : '');
        $('#packStage').prop('disabled',busy||!v||!project);
    }
    function reset(){generation++;project=null;versions=[];$('#packResults,#packVersion').empty();$('#packVersion').prop('disabled',true);refresh();}
    $('#packLoader').on('change',reset);$('#packVersion').on('change',refresh);
    $('#packSearch').on('click',()=>{
        reset();const g=generation;status.text('Searching...');
        $.getJSON(catalog,{loader:loader(),query:$('#packQuery').val()}).done(items=>{
            if(g!==generation)return;
            items.forEach(item=>{
                const card=$('<div>').addClass('well well-sm');
                $('<strong>').text(item.title).appendTo(card);$('<p>').text(item.description).appendTo(card);$('<small>').text(item.author).appendTo(card);
                $('<a>').attr({href:'https://modrinth.com/modpack/'+encodeURIComponent(item.id),target:'_blank',rel:'noopener noreferrer'}).text(' Review project ').appendTo(card);
                $('<button type="button">').addClass('btn btn-default btn-xs').text('Versions').on('click',()=>{
                    project=item.id;versions=[];$('#packVersion').empty().prop('disabled',true);refresh();
                    const selected=project, q=++generation;
                    $.getJSON(catalog,{loader:loader(),project:selected}).done(rows=>{
                        if(q!==generation||project!==selected)return;
                        versions=rows;rows.forEach(v=>$('<option>').val(v.id).text(v.version+' — '+v.filename).appendTo('#packVersion'));
                        $('#packVersion').prop('disabled',!rows.length);status.text(rows.length?'Select a compatible release.':'No eligible releases.');refresh();
                    }).fail(()=>{if(q===generation)status.text('Version lookup failed.');});
                }).appendTo(card);card.appendTo('#packResults');
            });
            status.text(items.length?'Select a project.':'No results.');
        }).fail(()=>{if(g===generation)status.text('Catalog unavailable.');});
    });
    $('#packStage').on('click',()=>{
        const v=current();if(!v||!project||busy||!window.confirm('Stage this exact archive for review? Nothing will be installed.'))return;
        busy=true;refresh();output.text('Submitting...');
        const finish=()=>{busy=false;refresh();};
        $.ajax({url:stage,method:'POST',dataType:'json',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'},data:{modpack_loader:loader(),plugin_project:project,plugin_version:v.id,plugin_sha512:v.sha512,confirmed:1}})
          .done(data=>{
            let attempts=0;
            function poll(){
                $.getJSON(result.replace('JOB_ID',encodeURIComponent(data.id))).done(job=>{
                    output.text(job.state+': '+(job.result||''));
                    if(['queued','running'].includes(job.state)&&++attempts<420){setTimeout(poll,3000);return;}
                    finish();
                }).fail(()=>{output.text('Job unavailable.');finish();});
            }poll();
          }).fail(xhr=>{output.text(xhr.responseJSON&&xhr.responseJSON.message?xhr.responseJSON.message:'Staging rejected.');finish();});
    });
})();
</script>
@endsection
