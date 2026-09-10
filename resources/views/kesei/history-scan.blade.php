@include('kesei._history-layout', [
    'pageTitle' => __('History Scan'),
    'routeName' => 'kesei-scans.index',
    'resultsPartial' => 'kesei._history-scan-results',
    'searchPlaceholder' => __('Cari part no / lokasi...'),
])
