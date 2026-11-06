@extends('layout')
@section('title', $mode==='register' ? 'Create account' : 'Log in')
@section('content')
<div class="auth-layout">
    @include('revision-art')
    <section class="auth-form">
        <p class="eyebrow">Workspace access</p>
        <h1>{{ $mode==='register'?'Create account':'Log in' }}<em>.</em></h1>
        <p class="intro">{{ $mode==='register'?'Create your RepoChronos account.':'Open your repository workspace.' }}</p>
        <a class="btn btn-outline-success wide" href="{{ route('github.login') }}">Continue with GitHub</a>
        <p class="minor-link">Or use your email and password</p>
        <form method="post" action="/{{ $mode }}" class="fields">
            @csrf
            @if($mode==='register')
                <label>Name<input class="form-control" name="name" required maxlength="80" autocomplete="name" value="{{ old('name') }}"></label>
            @endif
            <label>Email<input class="form-control" name="email" type="email" required autocomplete="email" placeholder="you@example.com" value="{{ old('email') }}"></label>
            <label>Password<input class="form-control" name="password" type="password" required autocomplete="{{ $mode==='register'?'new-password':'current-password' }}" @if($mode==='register') minlength="12" aria-describedby="password-help" @endif></label>
            @if($mode==='register')
                <small id="password-help">Use at least 12 characters.</small>
                <label>Confirm password<input class="form-control" name="password_confirmation" type="password" required autocomplete="new-password"></label>
            @endif
            <button class="btn btn-success wide">{{ $mode==='register'?'Create account':'Log in' }} <span aria-hidden="true">↗</span></button>
        </form>
        <a class="minor-link" href="/{{ $mode==='register'?'login':'register' }}">{{ $mode==='register'?'Already registered? Log in':'Create an account' }}</a>
    </section>
</div>
@endsection
