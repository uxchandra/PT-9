@include('kesei._history-layout', [
    'header' => __('Lot Making'),
    'pageTitle' => __('History'),
    'routeName' => 'lot-making-cycles.index',
    'resultsPartial' => 'lot-makings._history-cycle-results',
    'searchPlaceholder' => __('Cari part no...'),
])
