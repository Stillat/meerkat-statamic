@extends('statamic::layout')
@section('title', __('meerkat::general.dashboard_title'))

@section('content')
    <meerkat-comments
        :blueprint='@json($blueprint)'
        :meta='@json($meta)'
        :columns="{{ $columns->toJson() }}"
        :filters="{{ $filters->toJson() }}"
        :permissions='@json($permissions)'
        sort-column="{{ $sortColumn }}"
        sort-direction="{{ $sortDirection }}"
        action-url="{{ cp_route('meerkat.comments.actions.run') }}"
    ></meerkat-comments>
@stop
