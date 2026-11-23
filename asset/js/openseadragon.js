(function () {
    const init = () => {
        Object.keys(iiifViewerOpenSeaDragonArgs).forEach(function(key) {
            OpenSeadragon(iiifViewerOpenSeaDragonArgs[key]);
        });
    };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
})();
