@include('kesei._history-layout', [
    'header' => __('Lot Making'),
    'pageTitle' => __('History Scan'),
    'routeName' => 'lot-making-scans.index',
    'resultsPartial' => 'lot-makings._history-scan-results',
    'searchPlaceholder' => __('Cari part no...'),
])
