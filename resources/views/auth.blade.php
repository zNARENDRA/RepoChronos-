@extends('layout')
@section('content')
<div class="card mx-auto p-4 auth-card">
<h1>{{ $mode==='register'?'Create account':'Log in' }}</h1>
<p>Track repository history and code quality.</p>
<form method="post" action="/{{ $mode }}">@csrf
@if($mode==='register')<label class="form-label d-block">Name<input class="form-control" name="name" required maxlength="80" value="{{ old('name') }}"></label>@endif
<label class="form-label d-block">Email<input class="form-control" name="email" type="email" required value="{{ old('email') }}"></label>
<label class="form-label d-block">Password<input class="form-control" name="password" type="password" required autocomplete="{{ $mode==='register'?'new-password':'current-password' }}"></label>
@if($mode==='register')<label class="form-label d-block">Confirm password<input class="form-control" name="password_confirmation" type="password" required></label><p class="small">Use at least 12 characters.</p>@endif
<button class="btn btn-success">{{ $mode==='register'?'Register':'Log in' }}</button>
</form><a class="mt-3" href="/{{ $mode==='register'?'login':'register' }}">{{ $mode==='register'?'Already registered? Log in':'Create an account' }}</a>
</div>
@endsection
