@extends('errors::minimal')

@section('title', '服务器错误')
@section('code', '500')
@section('message', $exception->getMessage() ?: '服务器错误')
