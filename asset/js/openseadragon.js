jQuery(document).ready(function(){
    Object.keys(iiifViewerOpenSeaDragonArgs).forEach(function(key) {
        OpenSeadragon(iiifViewerOpenSeaDragonArgs[key]);
    });
});
